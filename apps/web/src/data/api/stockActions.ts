// Real-mode stock and purchase writes. Stock writes are online-only (the server checks what is on hand and locks counts) and throw an Error with a
// plain message; purchases go through the outbox (provisional PO reference offline, the server's real one after sync).
import type { Product, Purchase, SiteCode, StockCount } from '@tanaad/domain';
import { useStore } from '../store';
import { uuid7, nowIso } from '../ids';
import { ApiError, api } from './http';
import { loadSession } from './auth';
import { enqueue } from './outbox';
import { refreshCounts, siteCodeOf, syncNow } from './sync';
import { mergeById } from './mapping';
import { refreshStockViews } from './stock';
import {
  adjustPayload, countFromEvent, countPayload, provisionalPoRef, purchaseChangedPayload, purchaseReceivedPayload, purchaseRecordedPayload, receiveTransferPayload,
  reopenPayload, scrapPayload, sendTransferPayload, stockErrorMessage, zoneMovePayload,
} from './stockMap';

/* ───────────── stock writes (online only) ───────────── */

/** A refused stock action: the message is plain wording, `code` the server's problem code (day_locked, state_conflict, network...). */
export class StockError extends Error {
  code: string; status: number;
  constructor(message: string, code: string, status: number) { super(message); this.name = 'StockError'; this.code = code; this.status = status; }
}

async function post<T = { events: { type: string; payload: Record<string, unknown> }[] }>(site: SiteCode, path: string, body: Record<string, unknown>): Promise<T> {
  const s = loadSession(); if (!s) throw new Error('Sign in again to continue.');
  try { return await api<T>(`/sites/${encodeURIComponent(site)}/stock/${path}`, { method: 'POST', token: s.token, body }); }
  catch (e) { throw e instanceof ApiError ? new StockError(stockErrorMessage(e), e.code, e.status) : e; }
}
async function done(site: SiteCode) { await refreshStockViews([site], siteCodeOf).catch(() => undefined); void syncNow(); }

type Pq = Pick<Product, 'bundlePieces' | 'stdBundleWeightKg'>;

export async function moveBetweenZones(site: SiteCode, a: { productId: string; fromZoneId: string; toZoneId: string; bundles: number; product: Pq; reason?: string }) {
  await post(site, 'moves', zoneMovePayload({ productId: a.productId, fromZoneId: a.fromZoneId, toZoneId: a.toZoneId, q: { bundles: a.bundles, product: a.product }, reason: a.reason }));
  await done(site);
}
/** `bundles` is signed: negative takes stock out of the zone. */
export async function adjustStock(site: SiteCode, a: { productId: string; zoneId: string; bundles: number; product: Pq; reason: string }) {
  await post(site, 'moves', adjustPayload({ productId: a.productId, zoneId: a.zoneId, q: { bundles: a.bundles, product: a.product }, reason: a.reason }));
  await done(site);
}
/** Scrapping writes off one in-stock label, found by its code. */
export async function scrapLabel(site: SiteCode, code: string, reason: string) {
  await post(site, `labels/${encodeURIComponent(code.trim())}/scrap`, scrapPayload(reason));
  await done(site);
}
export async function sendTransfer(site: SiteCode, a: { toSite: SiteCode; productId: string; fromZoneId: string; bundles: number; product: Pq; note?: string }) {
  await post(site, 'transfers', sendTransferPayload({ transferId: uuid7(), toSite: a.toSite, productId: a.productId, fromZoneId: a.fromZoneId, q: { bundles: a.bundles, product: a.product }, note: a.note }));
  await done(site);
}
export async function receiveTransfer(site: SiteCode, transferId: string, a: { toZoneId: string; productId: string; bundles?: number; note?: string }) {
  await post(site, `transfers/${encodeURIComponent(transferId)}/receive`, receiveTransferPayload(a));
  await done(site);
}

export interface CountResult { countedBundles: number; systemBundles: number; variance: number }
/** Record one count. The system figure comes back from the server and replaces the screen's; a difference needs a reason. */
export async function recordCountOnline(site: SiteCode, a: { zoneId: string; productId: string; counted: number; day: string; reason?: string }): Promise<CountResult> {
  const res = await post(site, 'counts', countPayload({ countId: uuid7(), ...a }));
  const ev = res.events.find((e) => e.type === 'stock.counted');
  const u = useStore.getState().session?.userId ?? '';
  if (ev) {
    const rec = countFromEvent(ev.payload, site, nowIso(), u);
    useStore.setState((st) => ({ stockCounts: mergeById<StockCount>(st.stockCounts, [{ row: rec, deleted: false }]) }));
    await done(site);
    return { countedBundles: rec.countedBundles, systemBundles: rec.systemBundles, variance: rec.countedBundles - rec.systemBundles };
  }
  await done(site);
  return { countedBundles: a.counted, systemBundles: a.counted, variance: 0 };
}
/** Site Manager only: opens a locked zone and product again so it can be counted a second time. */
export async function reopenCountOnline(site: SiteCode, a: { zoneId: string; productId: string; day: string; reason: string }) {
  await post(site, 'counts/reopen', reopenPayload(a));
  await done(site);
}

/* ───────────── purchases (outbox) ───────────── */

async function queue(site: SiteCode, type: string, payload: Record<string, unknown>) {
  const s = loadSession(); if (!s) return;
  await enqueue(site, { id: uuid7(), type, version: 1, occurred_at: nowIso(), payload, ...(s.device?.id ? { device_id: s.device.id } : {}) });
  await refreshCounts();
  void syncNow();
}
const put = (rec: Purchase) => useStore.setState((st) => ({ purchases: st.purchases.some((p) => p.id === rec.id) ? st.purchases.map((p) => (p.id === rec.id ? rec : p)) : [rec, ...st.purchases] }));

/** Order or edit a purchase. A new one shows a provisional PO reference and the group exchange reference until the server answers with its own. */
export async function savePurchaseReal(p: Omit<Purchase, 'id' | 'reference' | 'fxReference' | 'orderedOn' | 'status'> & { id?: string }): Promise<Purchase> {
  const st = useStore.getState(); const existing = p.id ? st.purchases.find((x) => x.id === p.id) : undefined;
  if (existing) {
    const rec: Purchase = { ...existing, ...p, id: existing.id, example: false };
    put(rec); await queue(rec.siteId, 'purchases.changed', purchaseChangedPayload(rec)); return rec;
  }
  const id = uuid7();
  const rec: Purchase = { ...p, id, reference: provisionalPoRef(id), fxReference: st.fx, status: 'ordered', orderedOn: st.today, example: false };
  put(rec); await queue(rec.siteId, 'purchases.recorded', purchaseRecordedPayload(rec)); return rec;
}
/** Move a purchase to its next stage before receipt. */
export async function advancePurchase(id: string, status: Exclude<Purchase['status'], 'received'>): Promise<void> {
  const cur = useStore.getState().purchases.find((x) => x.id === id); if (!cur) return;
  const rec = { ...cur, status }; put(rec); await queue(rec.siteId, 'purchases.changed', purchaseChangedPayload(rec));
}
/** Receive the kilos weighed in. The landed cost shown afterwards is the server's, once it has synced. */
export async function receivePurchaseReal(id: string, receivedKg: number): Promise<void> {
  const st = useStore.getState(); const cur = st.purchases.find((x) => x.id === id); if (!cur) return;
  put({ ...cur, status: 'received', receivedOn: st.today, receivedKg });
  await queue(cur.siteId, 'purchases.received', purchaseReceivedPayload(id, receivedKg, st.today));
}
