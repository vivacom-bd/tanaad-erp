// Pure translation for stock and purchases: payloads out, snapshot/query rows in, plain wording for refusals. No store import.
import type { Product, Purchase, SiteCode, StockCount, StockMovement } from '@tanaad/domain';
import { isUuid } from './names';
import type { SiteOf } from './mapping';

type Row = Record<string, unknown>;
const num = (v: unknown) => (v === null || v === undefined ? 0 : Number(v));
const str = (v: unknown) => (v === null || v === undefined || v === '' ? undefined : String(v));
const iso = (v: unknown) => { const s = str(v); return s ? (s.includes('T') ? s : s.replace(' ', 'T').replace(/(\.\d{3})\d*$/, '$1') + 'Z') : ''; };
const dayOf = (v: unknown) => String(v ?? '').slice(0, 10);
const opt = <T extends Record<string, unknown>>(o: T) => Object.fromEntries(Object.entries(o).filter(([, v]) => v !== undefined && v !== '')) as T;
const round3 = (n: number) => Math.round(n * 1000) / 1000;

/* ───────────── on hand ───────────── */

/** One row of the stock_on_hand snapshot: a product in a zone. The server computes these; the client never adds to them. */
export interface StockOnHand { id: string; siteId: SiteCode; productId: string; zoneId?: string; bundles: number; pieces: number; kg: number }
export const onHandFromRow = (r: Row, site: SiteOf): StockOnHand => ({
  id: String(r.id), siteId: site(r.siteId), productId: String(r.productId), zoneId: str(r.zoneId), bundles: num(r.bundles), pieces: num(r.pieces), kg: num(r.kg),
});

export interface StockLine { product: Product; bundles: number; pieces: number; kg: number; zones: Map<string, number> }
/** On hand per product in the shape stockByProduct returns. Rows for products the server config does not list are left out. */
export function stockLinesFromRows(rows: StockOnHand[], site: SiteCode, products: Product[]): StockLine[] {
  const by = new Map<string, StockLine>();
  for (const r of rows) {
    if (r.siteId !== site || (r.bundles === 0 && r.pieces === 0 && r.kg === 0)) continue;
    const product = products.find((p) => p.id === r.productId); if (!product) continue;
    const cur = by.get(product.id) ?? { product, bundles: 0, pieces: 0, kg: 0, zones: new Map<string, number>() };
    cur.bundles += r.bundles; cur.pieces += r.pieces; cur.kg += r.kg;
    if (r.zoneId) cur.zones.set(r.zoneId, (cur.zones.get(r.zoneId) ?? 0) + r.bundles);
    by.set(product.id, cur);
  }
  return [...by.values()].sort((a, b) => b.bundles - a.bundles);
}

/* ───────────── outgoing: stock writes (all online; the server checks what is on hand) ───────────── */

export interface Qty { bundles: number; product: Pick<Product, 'bundlePieces' | 'stdBundleWeightKg'> }
/** Quantity block: bundles plus their pieces and kg at the product's standard. */
export const qtyFields = ({ bundles, product }: Qty) => ({
  bundles, qty_units: round3(bundles * (product.bundlePieces || 0)), qty_kg: round3(bundles * (product.stdBundleWeightKg || 0)),
});

export const receiptPayload = (a: { receiptId: string; productId: string; zoneId: string; q: Qty; cause?: string; reason?: string }) =>
  opt({ receipt_id: a.receiptId, product_id: a.productId, zone_id: a.zoneId, ...qtyFields(a.q), cause: a.cause, reason: a.reason?.trim() });
export const zoneMovePayload = (a: { productId: string; fromZoneId: string; toZoneId: string; q: Qty; reason?: string }) =>
  opt({ kind: 'transfer', product_id: a.productId, from_zone_id: a.fromZoneId, to_zone_id: a.toZoneId, ...qtyFields(a.q), reason: a.reason?.trim() });
/** An adjustment is signed: a negative figure takes stock out. Needs a reason of at least three characters. */
export const adjustPayload = (a: { productId: string; zoneId: string; q: Qty; reason: string }) =>
  ({ kind: 'adjust', product_id: a.productId, zone_id: a.zoneId, ...qtyFields(a.q), reason: a.reason.trim() });
export const scrapPayload = (reason: string) => ({ reason: reason.trim() });
export const sendTransferPayload = (a: { transferId: string; toSite: string; productId: string; fromZoneId: string; q: Qty; note?: string }) =>
  opt({ transfer_id: a.transferId, to_site_id: a.toSite, product_id: a.productId, from_zone_id: a.fromZoneId, ...qtyFields(a.q), note: a.note?.trim() });
/** The receiving site books it into a zone; sending nothing for the quantity means "all of it arrived". */
/** The receiving site's own product is required by the API (product codes are global, so the sender's id is never reused). */
export const receiveTransferPayload = (a: { toZoneId: string; productId: string; bundles?: number; note?: string }) => {
  if (!isUuid(a.productId)) throw new Error('Choose which of your products this transfer is');
  return opt({ to_zone_id: a.toZoneId, product_id: a.productId, bundles: a.bundles, note: a.note?.trim() });
};
/** The system figure is never sent: the server reads it at the moment of the count. */
export const countPayload = (a: { countId: string; zoneId: string; productId: string; counted: number; day: string; reason?: string }) =>
  opt({ count_id: a.countId, zone_id: a.zoneId, product_id: a.productId, counted_bundles: a.counted, day: a.day, reason: a.reason?.trim() });
export const reopenPayload = (a: { zoneId: string; productId: string; day: string; reason: string }) =>
  ({ zone_id: a.zoneId, product_id: a.productId, day: a.day, reason: a.reason.trim() });

/** Plain wording for a refused stock or purchase action. `status`/`code` come from the ApiError. */
export function stockErrorMessage(e: { status?: number; code?: string; detail?: string } | null | undefined, what = 'This'): string {
  if (!e) return `${what} could not be saved.`;
  if (e.code === 'network') return 'No connection. This needs the server; try again when online.';
  if (e.code === 'day_locked') return 'This zone and product were already counted today. The Site Manager can reopen the count with a reason.';
  if (e.status === 403 || e.code === 'forbidden') return e.detail || 'Your role may not do this here.';
  if (e.code === 'state_conflict') return e.detail || `${what} no longer matches the books. Refresh and check again.`;
  if (e.code === 'not_found') return e.detail || 'The server does not have this record yet. Wait for it to sync.';
  if (e.code === 'stale_config') return e.detail || 'A setting this needs is missing on the server.';
  if (e.code === 'invalid_payload') return e.detail || `${what} was not accepted: check the figures.`;
  return e.detail || `${what} could not be saved.`;
}

/* ───────────── incoming: movements, counts, transfers ───────────── */

const KIND: Record<string, StockMovement['kind']> = {
  production_in: 'production_in', dispatch_out: 'dispatch_out', receive: 'receive', transfer: 'transfer', label_move: 'transfer',
  adjust: 'adjust', scrap: 'scrap', transfer_out: 'transfer', transfer_in: 'transfer',
};
/** A server movement in the prototype's StockMovement shape (units = bundles). Kinds with no prototype wording (consumed) pass through unchanged. */
export function movementFromApi(r: Row, site: SiteOf): { id: string; occurredAt: string; actorId: string; payload: StockMovement } {
  const kind = String(r.kind ?? '');
  const out = kind === 'transfer_out'; const toSite = out && r.toSiteId ? site(r.toSiteId) : undefined;
  return {
    id: String(r.id), occurredAt: iso(r.at), actorId: String(r.by ?? ''),
    payload: {
      id: String(r.id), siteId: site(r.siteId), kind: (KIND[kind] ?? kind) as StockMovement['kind'], labelId: str(r.labelId), productId: str(r.productId),
      fromZoneId: str(r.fromZoneId), toZoneId: str(r.toZoneId), toSiteId: toSite, qtyUnits: num(r.bundles), qtyKg: num(r.qtyKg),
      reason: str(r.reason) ?? (str(r.labelCode) ? `Label ${r.labelCode}` : undefined), at: iso(r.at), by: String(r.by ?? ''),
    } as StockMovement,
  };
}

/** A count row (count-variance) in the store's StockCount shape. Variance is counted minus the server's system figure. */
export const countFromApi = (r: Row, site: SiteOf): StockCount => ({
  id: String(r.id), siteId: site(r.siteId), zoneId: String(r.zoneId), day: dayOf(r.day), productId: String(r.productId),
  countedBundles: num(r.countedBundles), systemBundles: num(r.systemBundles), by: String(r.by ?? ''), at: iso(r.at),
});
/** The stock.counted event the server answered with, as a StockCount (its system figure, not the screen's). */
export const countFromEvent = (p: Row, site: SiteCode, at: string, by: string): StockCount => ({
  id: String(p.count_id), siteId: site, zoneId: String(p.zone_id), day: dayOf(p.day), productId: String(p.product_id),
  countedBundles: num(p.counted_bundles), systemBundles: num(p.system_bundles), by, at,
});

export interface ServerTransfer {
  id: string; fromSiteId: string; toSiteId: string; productId: string; fromZoneId?: string; status: string; bundles: number; qtyKg: number;
  receivedBundles?: number; discrepancyBundles?: number; note?: string; sentAt: string; receivedAt?: string;
}
export const transferFromApi = (r: Row): ServerTransfer => ({
  id: String(r.id), fromSiteId: String(r.fromSiteId), toSiteId: String(r.toSiteId), productId: String(r.productId), fromZoneId: str(r.fromZoneId), status: String(r.status ?? ''),
  bundles: num(r.bundles), qtyKg: num(r.qtyKg), receivedBundles: r.receivedBundles == null ? undefined : num(r.receivedBundles),
  discrepancyBundles: r.discrepancyBundles == null ? undefined : num(r.discrepancyBundles), note: str(r.note), sentAt: iso(r.sentAt), receivedAt: str(r.receivedAt) ? iso(r.receivedAt) : undefined,
});

/* ───────────── purchases ───────────── */

export const PO_PENDING = 'PO-pending';
/** Reference shown until the server allocates the real PO number on sync. */
export const provisionalPoRef = (id: string) => `${PO_PENDING}-${id.replace(/-/g, '').slice(-4).toUpperCase()}`;
export const isProvisionalPo = (ref: string) => ref.startsWith(PO_PENDING);

type Costs = Purchase['costs'];
const fieldsOf = (p: Pick<Purchase, 'supplier' | 'originCountry' | 'material' | 'qtyKg' | 'unitPrice' | 'currency' | 'costs' | 'note'>) => opt({
  supplier: p.supplier.trim(), origin_country: p.originCountry.trim() || undefined, material: p.material, qty_kg: p.qtyKg, unit_price: p.unitPrice, currency: p.currency,
  costs: (p.costs as Costs).filter((c) => c.amount > 0).map((c) => ({ label: c.label.trim(), amount: c.amount, currency: c.currency })), note: p.note?.trim() || undefined,
});
/** purchases.recorded. The reference and the exchange reference are left out on purpose: the server allocates and captures them. */
export const purchaseRecordedPayload = (p: Purchase) => ({ purchase_id: p.id, ...fieldsOf(p) });
/** purchases.changed: the full new state and the stage. */
export const purchaseChangedPayload = (p: Purchase) => ({ purchase_id: p.id, ...fieldsOf(p), status: p.status });
/** purchases.received: the kilos weighed in. Landed cost is computed by the server from these. */
export const purchaseReceivedPayload = (id: string, receivedKg: number, receivedOn?: string) => opt({ purchase_id: id, received_kg: receivedKg, received_on: receivedOn });

/** Snapshot row (purchases) in the store's shape, carrying the server's landed cost and variance flag. */
export function purchaseFromRow(r: Row, site: SiteOf): Purchase {
  const costs = Array.isArray(r.costs) ? r.costs : typeof r.costs === 'string' ? (() => { try { const j = JSON.parse(r.costs as string); return Array.isArray(j) ? j : []; } catch { return []; } })() : [];
  const total = r.totalUgx;
  return {
    id: String(r.id), siteId: site(r.siteId), reference: String(r.reference ?? ''), supplier: String(r.supplier ?? ''), originCountry: String(r.originCountry ?? ''), material: String(r.material ?? ''),
    qtyKg: num(r.qtyKg), unitPrice: num(r.unitPrice), currency: (r.currency as Purchase['currency']) ?? 'UGX', fxReference: num(r.fxReference),
    costs: (costs as Row[]).map((c) => ({ label: String(c.label ?? ''), amount: num(c.amount), currency: (c.currency as Purchase['currency']) ?? 'UGX' })),
    status: (r.status as Purchase['status']) ?? 'ordered', orderedOn: dayOf(r.orderedOn), receivedOn: str(r.receivedOn) ? dayOf(r.receivedOn) : undefined,
    receivedKg: r.receivedKg == null ? undefined : num(r.receivedKg), note: str(r.note), example: false,
    ...(total == null ? {} : { landed: { goodsUgx: num(r.goodsUgx), extrasUgx: num(r.extrasUgx), totalUgx: num(total), perKgUgx: num(r.perKgUgx), upliftPct: num(r.upliftPct) } }),
    ...(r.variance == null ? {} : { variance: !!Number(r.variance) }),
  };
}
