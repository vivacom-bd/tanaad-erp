// Real-mode recycling: collector intake goes through the outbox (offline-capable), payment is online-only.
// Net kg and amount shown before sync come from the shared intakeNet arithmetic; the server's values replace them.
import { create } from 'zustand';
import type { CollectorDelivery } from '@tanaad/domain';
import { api, ApiError } from './http';
import { loadSession } from './auth';
import { isUuid } from './names';

type Row = Record<string, unknown>;
const num = (v: unknown) => (v === null || v === undefined ? 0 : Number(v));
const str = (v: unknown) => (v === null || v === undefined || v === '' ? undefined : String(v));
const iso = (v: unknown) => { const s = str(v); return s ? (s.includes('T') ? s : s.replace(' ', 'T').replace(/(\.\d{3})\d*$/, '$1') + 'Z') : ''; };
const uid = () => (typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`);

/** Temporary voucher number shown until the server allocates the real one. */
export function provisionalVoucher(day: string, rand: string = Math.random().toString(36).slice(2, 6)): string {
  return `PV-${day.slice(2).replace(/-/g, '')}-${rand.toUpperCase().padEnd(4, '0').slice(0, 4)}`;
}

/** Whether the server has confirmed the delivery (it carries the server's voucher number). */
export const isConfirmed = (d: Pick<CollectorDelivery, 'voucherNo'>) => !!d.voucherNo;

/** The number to show on a voucher: the server's, else the provisional one, else undefined (demo falls back to its own). */
export const voucherLabel = (d: Pick<CollectorDelivery, 'voucherNo' | 'provisionalNo'>) => d.voucherNo ?? d.provisionalNo;

/** The intake payload. Net kg and amount are left out on purpose: the server computes them. A preset id is sent only when it is a server id. */
export function intakePayload(d: CollectorDelivery): Record<string, unknown> {
  const o: Record<string, unknown> = {
    delivery_id: d.id, day: d.day, collector: d.collector, gross_kg: d.grossKg, tare_kg: d.tareKg, deduction_pct: d.deductionPct,
    price_per_kg: d.pricePerKg, has_scale_photo: d.hasScalePhoto,
  };
  if (isUuid(d.deductionPresetId)) o.deduction_preset_id = d.deductionPresetId;
  if (d.scalePhotoHash) o.scale_photo_hash = d.scalePhotoHash;
  if (d.provisionalNo) o.provisional_no = d.provisionalNo;
  return o;
}

/** Snapshot row (collector_deliveries) → store shape. */
export const deliveryFromRow = (r: Row): CollectorDelivery => ({
  id: String(r.id), siteId: 'recycling', collector: String(r.collector ?? ''), grossKg: num(r.grossKg), tareKg: num(r.tareKg),
  deductionPct: num(r.deductionPct), netKg: num(r.netKg), pricePerKg: num(r.pricePerKg), currency: (r.currency as CollectorDelivery['currency']) ?? 'UGX',
  paid: !!Number(r.paid), day: String(r.day ?? '').slice(0, 10), at: iso(r.at), hasScalePhoto: !!Number(r.hasScalePhoto),
  voucherNo: str(r.voucherNo), provisionalNo: str(r.provisionalNo), deductionPresetId: str(r.deductionPresetId), scalePhotoHash: str(r.scalePhotoHash),
});

/* ───────────── collector ledger (server endpoint) ───────────── */

export interface LedgerServerRow { collector: string; deliveries: number; unpaidDeliveries: number; totalNetKg: number; totalOwed: number; totalPaid: number; balance: number; lastDelivery: string }
export const ledgerRowFromApi = (r: Row): LedgerServerRow => ({
  collector: String(r.collector ?? ''), deliveries: num(r.deliveries), unpaidDeliveries: num(r.unpaidDeliveries), totalNetKg: num(r.totalNetKg),
  totalOwed: num(r.totalOwed), totalPaid: num(r.totalPaid), balance: num(r.balance), lastDelivery: String(r.lastDelivery ?? '').slice(0, 10),
});

interface LedgerState { rows: LedgerServerRow[]; loaded: boolean }
export const useCollectorLedger = create<LedgerState>(() => ({ rows: [], loaded: false }));
export const resetCollectorLedger = () => useCollectorLedger.setState({ rows: [], loaded: false });

export async function loadCollectorLedger(siteCode = 'recycling'): Promise<void> {
  const s = loadSession(); if (!s) return;
  try {
    const res = await api<{ collectors: Row[] }>(`/sites/${siteCode}/collector-ledger`, { token: s.token });
    useCollectorLedger.setState({ rows: res.collectors.map(ledgerRowFromApi), loaded: true });
  } catch { /* keep what is on screen; the local rows still show */ }
}

/** Owed / paid / unpaid for one collector: the server's totals plus deliveries still waiting to sync. */
export function ledgerTotals(local: CollectorDelivery[], server: LedgerServerRow | undefined, amountOf: (d: CollectorDelivery) => number) {
  const pending = local.filter((d) => !isConfirmed(d));
  const pendingAmount = pending.reduce((t, d) => t + amountOf(d), 0);
  if (!server) return null;
  return { amount: server.totalOwed + pendingAmount, paid: server.totalPaid, unpaid: server.balance + pendingAmount, unpaidCount: server.unpaidDeliveries + pending.length };
}

/* ───────────── payment (online only) ───────────── */

/** Plain wording for a failed payment. */
export function payErrorMessage(e: unknown): string {
  if (!(e instanceof ApiError)) return 'The payment could not be recorded.';
  if (e.code === 'network') return 'No connection. Paying a collector needs the server; try again when online.';
  if (e.code === 'day_locked') return 'Today’s cash book is closed. Reopen it before paying a collector.';
  if (e.code === 'state_conflict') return e.detail || 'This delivery is already paid.';
  if (e.code === 'not_found') return 'The server does not have this delivery yet. Wait for it to sync.';
  return e.detail || 'The payment could not be recorded.';
}

/** Pay one confirmed delivery on the server (it posts the cash-out itself). Throws ApiError; callers show payErrorMessage. */
export async function payCollectorOnline(deliveryId: string, siteCode = 'recycling'): Promise<void> {
  const s = loadSession(); if (!s) throw new ApiError(401, 'unauthenticated', 'Sign in again.');
  await api(`/sites/${siteCode}/collector-deliveries/${encodeURIComponent(deliveryId)}/pay`, { method: 'POST', token: s.token, body: { paymentId: uid() } });
}
