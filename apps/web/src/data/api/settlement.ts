// Real-mode settlement: server runs/advances in a small store, command envelopes through the outbox. Never used in demo mode.
import { create } from 'zustand';
import type { SiteCode } from '@tanaad/domain';
import { api } from './http';
import { loadSession } from './auth';
import { outboxDb, enqueue, type OutboxItem } from './outbox';
import type { EnvelopeOut } from './types';

export type RunKind = 'weekly_casual' | 'end_of_term' | 'monthly';
export type RunStatus = 'created' | 'approved' | 'paid' | 'cancelled';
export type LineStatus = 'unpaid' | 'paid' | 'cancelled';
export type PayMethod = 'cash' | 'mobile_money' | 'bank';

export interface SettlementLineRow {
  id: string; runId: string; personId: string; periodFrom: string; periodTo: string; rateBasis: string; rate: number;
  units: number; overtimeHours: number; gross: number; overtime: number; bonus: number; advances: number; net: number;
  status: LineStatus; payoutId?: string;
}
export interface PayoutRow { id: string; runId: string; lineId: string; personId: string; amount: number; method: string; paidAt: string; reference?: string }
export interface SettlementRunRow {
  id: string; siteCode: SiteCode; kind: RunKind; from: string; to: string; currency: string; status: RunStatus;
  createdBy?: string; approvedBy?: string; approvedAt?: string; totalNet: number; totalAdvances: number; totalGross: number;
  lines: SettlementLineRow[]; payouts: PayoutRow[];
}
export interface AdvanceRow { id: string; siteCode: SiteCode; personId: string; amount: number; deducted: number; balance: number; reason: string; givenOn: string; givenBy?: string; queued?: boolean }

type Row = Record<string, unknown>;
const num = (v: unknown) => (v === null || v === undefined ? 0 : Number(v));
const str = (v: unknown) => (v === null || v === undefined ? undefined : String(v));
const day = (v: unknown) => String(v ?? '').slice(0, 10);
/** Money is 2dp on the server; keep the same on this side so sums never drift. */
export const money2 = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100;

export const lineFromApi = (r: Row): SettlementLineRow => ({
  id: String(r.id), runId: String(r.settlementRunId), personId: String(r.personId), periodFrom: day(r.periodFrom), periodTo: day(r.periodTo),
  rateBasis: String(r.rateBasis ?? ''), rate: num(r.rate), units: num(r.units), overtimeHours: num(r.overtimeHours), gross: num(r.gross),
  overtime: num(r.overtime), bonus: num(r.bonus), advances: num(r.advancesDeducted), net: num(r.net),
  status: (r.status as LineStatus) ?? 'unpaid', payoutId: str(r.payoutId),
});
export const payoutFromApi = (r: Row): PayoutRow => ({
  id: String(r.id), runId: String(r.settlementRunId), lineId: String(r.settlementLineId), personId: String(r.personId),
  amount: num(r.amount), method: String(r.method ?? ''), paidAt: String(r.paidAt ?? ''), reference: str(r.reference),
});
export const runFromApi = (e: { run: Row; lines?: Row[]; payouts?: Row[] }, siteCode: SiteCode): SettlementRunRow => ({
  id: String(e.run.id), siteCode, kind: e.run.kind as RunKind, from: day(e.run.periodFrom), to: day(e.run.periodTo), currency: String(e.run.currency ?? 'UGX'),
  status: e.run.status as RunStatus, createdBy: str(e.run.createdBy), approvedBy: str(e.run.approvedBy), approvedAt: str(e.run.approvedAt),
  totalNet: num(e.run.totalNet), totalAdvances: num(e.run.totalAdvances), totalGross: num(e.run.totalGross),
  lines: (e.lines ?? []).map(lineFromApi), payouts: (e.payouts ?? []).map(payoutFromApi),
});
export const advanceFromApi = (r: Row, siteCode: SiteCode): AdvanceRow => ({
  id: String(r.id), siteCode, personId: String(r.personId), amount: num(r.amount), deducted: num(r.deducted), balance: num(r.balance),
  reason: String(r.reason ?? ''), givenOn: day(r.givenOn), givenBy: str(r.givenBy),
});

/* ───────────── commands (outbox envelopes) ───────────── */

const TYPES = {
  create: 'people.settlement_run_created', approve: 'people.settlement_approved', cancel: 'people.settlement_cancelled',
  payout: 'people.payout_recorded', advance: 'people.advance_given',
} as const;
const SETTLEMENT_TYPES = new Set<string>(Object.values(TYPES));
export const isSettlementType = (t: string) => SETTLEMENT_TYPES.has(t);

const uid = () => (typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`);
function envelope(type: string, payload: Record<string, unknown>, reason?: string): EnvelopeOut {
  const device = loadSession()?.device?.id;
  return { id: uid(), type, version: 1, occurred_at: new Date().toISOString(), payload, ...(device ? { device_id: device } : {}), ...(reason ? { reason } : {}) };
}
export interface Bonus { personId: string; amount: number }
/** Optional bonuses go as [{person_id, amount}]; zero or blank amounts are dropped. */
export const createRunEnvelope = (kind: RunKind, from: string, to: string, runId = uid(), bonuses: Bonus[] = []) => {
  const b = bonuses.filter((x) => x.personId && x.amount > 0).map((x) => ({ person_id: x.personId, amount: money2(x.amount) }));
  return envelope(TYPES.create, { settlement_run_id: runId, kind, from, to, ...(b.length ? { bonuses: b } : {}) });
};
/** A line with nothing to pay out (net 0) counts as settled: no payout is needed. */
export const lineSettled = (l: SettlementLineRow) => l.status === 'paid' || (l.status === 'unpaid' && l.net <= 0);
export const approveEnvelope = (runId: string) => envelope(TYPES.approve, { settlement_run_id: runId });
export const cancelEnvelope = (runId: string, reason?: string) => envelope(TYPES.cancel, { settlement_run_id: runId, ...(reason?.trim() ? { reason: reason.trim() } : {}) }, reason?.trim() || undefined);
export const payoutEnvelope = (runId: string, personId: string, method: PayMethod, reference?: string) =>
  envelope(TYPES.payout, { settlement_run_id: runId, person_id: personId, method, payout_id: uid(), ...(reference?.trim() ? { reference: reference.trim().slice(0, 64) } : {}) });
export const advanceEnvelope = (personId: string, amount: number, reason: string, givenOn?: string) =>
  envelope(TYPES.advance, { advance_id: uid(), person_id: personId, amount: money2(amount), reason: reason.trim(), ...(givenOn ? { given_on: givenOn } : {}) });

/** Whether a run may be cancelled from the screen: created or approved, nothing paid, no payout waiting to sync. */
export function canCancel(run: SettlementRunRow, queued: Queued): boolean {
  if (run.status !== 'created' && run.status !== 'approved') return false;
  if (run.lines.some((l) => l.status === 'paid')) return false;
  return !queued.payouts.some((k) => k.startsWith(`${run.id}|`)) && !queued.cancelled.includes(run.id);
}

/* ───────────── pending state (what is still in the outbox) ───────────── */

export interface Queued { creates: number; approved: string[]; cancelled: string[]; payouts: string[]; advances: AdvanceRow[] }
export const emptyQueued = (): Queued => ({ creates: 0, approved: [], cancelled: [], payouts: [], advances: [] });

/** Fold the outbox's unsent settlement envelopes into what the screen shows as "waiting to sync". Payout keys are run|person. */
export function queuedFrom(items: OutboxItem[]): Queued {
  const q = emptyQueued();
  for (const { envelope: e, siteCode } of items) {
    const p = e.payload as Record<string, unknown>;
    if (e.type === TYPES.create) q.creates++;
    else if (e.type === TYPES.approve) q.approved.push(String(p.settlement_run_id));
    else if (e.type === TYPES.cancel) q.cancelled.push(String(p.settlement_run_id));
    else if (e.type === TYPES.payout) q.payouts.push(`${p.settlement_run_id}|${p.person_id}`);
    else if (e.type === TYPES.advance) q.advances.push({ id: String(p.advance_id), siteCode: siteCode as SiteCode, personId: String(p.person_id), amount: num(p.amount), deducted: 0, balance: num(p.amount), reason: String(p.reason ?? ''), givenOn: day(p.given_on ?? e.occurred_at), queued: true });
  }
  return q;
}

/* ───────────── store ───────────── */

interface SettlementState { runs: SettlementRunRow[]; advances: AdvanceRow[]; queued: Queued; loaded: boolean; error?: string }
export const useSettlement = create<SettlementState>(() => ({ runs: [], advances: [], queued: emptyQueued(), loaded: false }));

export const resetSettlement = () => useSettlement.setState({ runs: [], advances: [], queued: emptyQueued(), loaded: false, error: undefined });

export async function refreshQueued(): Promise<void> {
  const items = (await outboxDb.outbox.toArray()).filter((i) => isSettlementType(i.envelope.type));
  useSettlement.setState({ queued: queuedFrom(items) });
}

/** Load runs and advances for the given sites from the API. Failures keep what is on screen. */
export async function loadSettlement(sites: SiteCode[]): Promise<void> {
  const s = loadSession(); if (!s) return;
  try {
    const runs: SettlementRunRow[] = []; const advances: AdvanceRow[] = [];
    for (const site of sites) {
      const [r, a] = await Promise.all([
        api<{ data: { run: Row; lines: Row[]; payouts: Row[] }[] }>(`/sites/${site}/settlement-runs`, { token: s.token }),
        api<{ data: Row[] }>(`/sites/${site}/advances`, { token: s.token }),
      ]);
      runs.push(...r.data.map((e) => runFromApi(e, site)));
      advances.push(...a.data.map((x) => advanceFromApi(x, site)));
    }
    useSettlement.setState({ runs, advances, loaded: true, error: undefined });
  } catch (e) {
    useSettlement.setState({ error: e instanceof Error ? e.message : String(e) });
  }
  await refreshQueued();
}

/** Queue one command for the site and start a sync. The reason a server rejects it shows in the sync-issues list. */
export async function submitSettlement(siteCode: SiteCode, env: EnvelopeOut): Promise<void> {
  await enqueue(siteCode, env);
  await refreshQueued();
  const { refreshCounts, syncNow } = await import('./sync');
  await refreshCounts();
  void syncNow();
}
