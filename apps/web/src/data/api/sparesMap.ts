// Pure translation for spares and breakdowns: snapshot rows and REST answers in, request bodies and plain messages out. No store import.
import type { Breakdown, Part, PartIssue } from '@tanaad/domain';
import type { SiteOf } from './mapping';

type Row = Record<string, unknown>;
const num = (v: unknown) => (v === null || v === undefined ? 0 : Number(v));
const str = (v: unknown) => (v === null || v === undefined || v === '' ? undefined : String(v));
const iso = (v: unknown) => { const s = str(v); return s ? (s.includes('T') ? s : s.replace(' ', 'T').replace(/(\.\d{3})\d*$/, '$1') + 'Z') : ''; };

export const partFromRow = (r: Row, site: SiteOf): Part => ({
  id: String(r.id), siteId: site(r.siteId), code: str(r.code), name: String(r.name ?? ''), unit: String(r.unit ?? ''),
  minLevel: num(r.minLevel), unitCost: num(r.unitCost), onHand: num(r.onHand),
});
/** Parts the site manager switched off are not offered for issue or receipt. */
export const partActive = (r: Row) => r.active === undefined || r.active === null ? true : !!Number(r.active);

export const issueFromRow = (r: Row, site: SiteOf): PartIssue => ({
  id: String(r.id), siteId: site(r.siteId), partId: String(r.partId), machineId: String(r.machineId), mechanicId: str(r.mechanicId) ?? '', qty: num(r.qty), at: iso(r.at),
});

export const breakdownFromRow = (r: Row, site: SiteOf): Breakdown => ({
  id: String(r.id), siteId: site(r.siteId), machineId: String(r.machineId), cause: String(r.cause ?? ''), startedAt: iso(r.startedAt),
  resolvedAt: str(r.resolvedAt) ? iso(r.resolvedAt) : undefined, sparesCost: num(r.sparesCost),
});

/* ───────────── request bodies (the REST endpoints accept the camelCase the screens already use) ───────────── */

/** POST parts/{id}/issue. No PIN: the signed-in issuer is the accountability. The issue id makes a retry harmless. */
export const issueBody = (i: { issueId: string; qty: number; machineId: string; mechanicId: string; breakdownId?: string; reason?: string }): Row =>
  ({ issueId: i.issueId, qty: i.qty, machineId: i.machineId, mechanicId: i.mechanicId, ...(i.breakdownId ? { breakdownId: i.breakdownId } : {}), ...(i.reason?.trim() ? { reason: i.reason.trim() } : {}) });
export const receiveBody = (r: { receiptId: string; qty: number; supplier?: string; unitCost?: number }): Row =>
  ({ receiptId: r.receiptId, qty: r.qty, ...(r.supplier?.trim() ? { supplier: r.supplier.trim() } : {}), ...(r.unitCost !== undefined && r.unitCost > 0 ? { unitCost: r.unitCost } : {}) });
export const reportBody = (b: { id: string; machineId: string; cause: string }): Row => ({ id: b.id, machineId: b.machineId, cause: b.cause.trim() });

/* ───────────── plain wording for refusals ───────────── */

interface Failure { status: number; code: string; detail: string; details?: Record<string, unknown> }
/** The first field message of a 422, else the server's own sentence. */
export function problemText(e: Failure): string {
  const errors = e.details && typeof e.details === 'object' ? (e.details as { errors?: Record<string, unknown> }).errors : undefined;
  const first = errors && typeof errors === 'object' ? Object.values(errors).find((v) => typeof v === 'string') : undefined;
  return typeof first === 'string' ? first : e.detail;
}

/** Wording for a failed spares or breakdown call. The server's sentence is shown as is when it has one. */
export function sparesMessage(e: Failure, what: 'issue' | 'receive' | 'report' | 'resolve'): string {
  if (e.code === 'network') return 'No connection. This needs the server, try again when you are online.';
  if (e.status === 403) return e.detail || (what === 'issue' ? 'Only the Storekeeper or Supervisor of this site may issue spares.' : 'Your role may not do this here.');
  if (e.status === 401) return 'Sign in again.';
  const t = problemText(e);
  return t || { issue: 'The part could not be issued.', receive: 'The parts could not be booked in.', report: 'The breakdown could not be reported.', resolve: 'The breakdown could not be resolved.' }[what];
}

/** Machines with an open breakdown are down, and a machine whose breakdowns are all resolved is back; any other status is left alone. */
export function machineStatuses(breakdowns: Pick<Breakdown, 'machineId' | 'resolvedAt'>[], current: { id: string; status: string }[]): Map<string, 'down' | 'active'> {
  const open = new Set(breakdowns.filter((b) => !b.resolvedAt).map((b) => b.machineId)); const seen = new Set(breakdowns.map((b) => b.machineId));
  const out = new Map<string, 'down' | 'active'>();
  for (const m of current) { if (open.has(m.id)) { if (m.status !== 'down') out.set(m.id, 'down'); } else if (seen.has(m.id) && m.status === 'down') out.set(m.id, 'active'); }
  return out;
}
