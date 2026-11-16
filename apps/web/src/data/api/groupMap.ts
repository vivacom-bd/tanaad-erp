// Real-mode group views, pure mapping: the server's cockpit, approvals, audit feed and costing answers become the shapes the screens read.
// Figures are the server's; nothing here computes a business number. A missing figure stays null so the screen can show a dash with the reason.
import type { AuditEvent, SiteCode } from '@tanaad/domain';
import type { SiteOf } from './mapping';
import { userLabel } from './names';

const num = (v: unknown): number => { const n = typeof v === 'number' ? v : parseFloat(String(v ?? '')); return Number.isFinite(n) ? n : 0; };
const numOrNull = (v: unknown): number | null => (v === null || v === undefined || v === '' ? null : num(v));
const rec = (v: unknown): Record<string, unknown> => (v && typeof v === 'object' && !Array.isArray(v) ? (v as Record<string, unknown>) : {});

/* ───────────── cockpit ───────────── */

export interface CockpitSite {
  siteId: string; siteCode: SiteCode; name: string; currency: string;
  output: { entries: number; goodUnits: number; rejectUnits: number; outputKg: number; inputKg: number; belowBenchmark: number };
  headcount: { present: number; half: number; absent: number; total: number; overtimeHours: number };
  cash: { inSafe: number; asOf: string | null; closeStatus: string; variance: number | null };
  exceptions: { critical: number; warning: number; info: number; held: number; total: number };
  stock: { bundles: number; kg: number; products: number };
  dispatches: { pending: number; held: number; inTransit: number };
  machinesDown: { count: number; machines: string[] };
  /** null when the server has no invoicing figures for this site. */
  receivables: { open: number; count: number } | null;
}
export interface Cockpit { day: string; sites: CockpitSite[] }

export function cockpitFromApi(raw: unknown, siteOf: SiteOf): Cockpit {
  const r = rec(raw);
  const sites = (Array.isArray(r.sites) ? r.sites : []).map((x): CockpitSite => {
    const s = rec(x); const o = rec(s.output); const h = rec(s.headcount); const c = rec(s.cash); const e = rec(s.exceptions); const st = rec(s.stock); const d = rec(s.dispatches); const m = rec(s.machinesDown); const rc = s.receivables;
    return {
      siteId: String(s.siteId ?? ''), siteCode: siteOf(String(s.siteCode ?? s.siteId ?? '')), name: String(s.name ?? ''), currency: String(s.currency ?? 'UGX'),
      output: { entries: num(o.entries), goodUnits: num(o.goodUnits), rejectUnits: num(o.rejectUnits), outputKg: num(o.outputKg), inputKg: num(o.inputKg), belowBenchmark: num(o.belowBenchmark) },
      headcount: { present: num(h.present), half: num(h.half), absent: num(h.absent), total: num(h.total), overtimeHours: num(h.overtimeHours) },
      cash: { inSafe: num(c.inSafe), asOf: c.asOf ? String(c.asOf) : null, closeStatus: String(c.closeStatus ?? 'none'), variance: numOrNull(c.variance) },
      exceptions: { critical: num(e.critical), warning: num(e.warning), info: num(e.info), held: num(e.held), total: num(e.total) },
      stock: { bundles: num(st.bundles), kg: num(st.kg), products: num(st.products) },
      dispatches: { pending: num(d.pending), held: num(d.held), inTransit: num(d.inTransit) },
      machinesDown: { count: num(m.count), machines: Array.isArray(m.machines) ? m.machines.map(String) : [] },
      receivables: rc && typeof rc === 'object' ? { open: num(rec(rc).open), count: num(rec(rc).count) } : null,
    };
  });
  return { day: String(r.day ?? ''), sites };
}

/** Why a cash figure may be missing: the server reports the newest cash page up to today. */
export function cashNote(c: CockpitSite['cash'], day: string): string {
  if (!c.asOf) return 'No cash page has been opened yet';
  if (c.asOf !== day) return `From the cash page of ${c.asOf}; none opened today`;
  return c.closeStatus === 'closed' ? 'Cash day closed' : 'Cash day open';
}

/* ───────────── approvals ───────────── */

export interface ApprovalOrder { id: string; siteCode: SiteCode; number: string; clientName: string | null; total: number | null; currency: string; createdAt: string }
export interface ApprovalCounts { exceptions: number; orders: number; critical: number; warning: number; info: number }

export function approvalOrderFromApi(raw: unknown, siteOf: SiteOf): ApprovalOrder {
  const r = rec(raw);
  return { id: String(r.id ?? ''), siteCode: siteOf(String(r.siteCode ?? r.site_id ?? '')), number: String(r.number ?? ''), clientName: r.clientName || r.client_name ? String(r.clientName ?? r.client_name) : null, total: numOrNull(r.total), currency: String(r.currency ?? 'UGX'), createdAt: String(r.at ?? r.created_at ?? '') };
}

export function approvalsFromApi(raw: unknown, siteOf: SiteOf): { orders: ApprovalOrder[]; counts: ApprovalCounts } {
  const r = rec(raw); const c = rec(r.counts);
  return { orders: (Array.isArray(r.orders) ? r.orders : []).map((o) => approvalOrderFromApi(o, siteOf)), counts: { exceptions: num(c.exceptions), orders: num(c.orders), critical: num(c.critical), warning: num(c.warning), info: num(c.info) } };
}

/** The server refuses a credit_over_limit decision made on the exception; it is decided from the order. */
export const decidedFromOrder = (kind: string) => kind === 'credit_over_limit';

/* ───────────── audit ───────────── */

export interface ServerAudit extends AuditEvent { seq: number; facts: Record<string, unknown>; correctsEventId?: string }
const FLAGS = new Set(['edit', 'reopen', 'override', 'variance', 'normal']);

export function auditFromApi(raw: unknown, siteOf: SiteOf): ServerAudit {
  const r = rec(raw); const f = String(r.flag ?? 'normal');
  return {
    id: String(r.id ?? ''), seq: num(r.seq), kind: String(r.type ?? ''), summary: String(r.summary ?? ''), at: String(r.at ?? ''),
    siteId: r.siteCode ? siteOf(String(r.siteCode)) : undefined,
    actorId: r.actorId ? String(r.actorId) : '', actorName: r.actorName ? String(r.actorName) : userLabel(r.actorId ? String(r.actorId) : undefined, []),
    deviceId: '', reason: r.reason ? String(r.reason) : undefined,
    flag: (FLAGS.has(f) ? f : 'normal') as AuditEvent['flag'], facts: rec(r.facts), correctsEventId: r.correctsEventId ? String(r.correctsEventId) : undefined,
  };
}
export interface AuditPage { rows: ServerAudit[]; page: number; lastPage: number; total: number }
export function auditPageFromApi(raw: unknown, siteOf: SiteOf): AuditPage {
  const r = rec(raw); const m = rec(r.meta);
  return { rows: (Array.isArray(r.data) ? r.data : []).map((x) => auditFromApi(x, siteOf)), page: num(m.page) || 1, lastPage: num(m.lastPage) || 1, total: num(m.total) };
}
/** "payment_amount" becomes "Payment amount"; values stay as the server wrote them. */
export const factLabel = (k: string) => { const t = k.replace(/_/g, ' ').trim(); return t ? t[0].toUpperCase() + t.slice(1) : k; };
export const factText = (v: unknown): string => (v === null || v === undefined || v === '' ? '—' : typeof v === 'object' ? JSON.stringify(v) : String(v));

/* ───────────── costing ───────────── */

export interface UnitCostRow { productId: string; product: string; kind: string; basis: 'kg' | 'unit'; units: number; material: number; overhead: number; total: number; priceUgx: number; marginPct: number }
export interface Pnl {
  siteCode?: SiteCode; period: string; from: string; to: string; provisional: boolean; status: string; version: number; closedAt: string | null;
  closedDays: string[]; openDays: number; daysInRange: number;
  revenue: { cashSales: number; invoiced: number; total: number; unconvertedLines: number };
  costs: { byCategory: Record<string, number>; cash: number; nonCash: number; excluded: number; total: number };
  net: number; marginPct: number; fx: number | null; materialPerKg: number | null; materialSource: string; unitCosts: UnitCostRow[];
}
const byCat = (v: unknown) => Object.fromEntries(Object.entries(rec(v)).map(([k, n]) => [k, num(n)]));

export function pnlFromApi(raw: unknown, siteOf?: SiteOf): Pnl {
  const r = rec(raw); const rv = rec(r.revenue); const c = rec(r.costs); const mat = rec(r.material);
  return {
    siteCode: r.site ? siteOf?.(String(r.site)) : undefined, period: String(r.period ?? ''), from: String(r.from ?? ''), to: String(r.to ?? ''),
    provisional: r.provisional !== false, status: String(r.status ?? 'open'), version: num(r.version), closedAt: r.closed_at ? String(r.closed_at) : null,
    closedDays: Array.isArray(r.closed_days) ? r.closed_days.map(String) : [], openDays: num(r.open_days), daysInRange: num(r.days_in_range),
    revenue: { cashSales: num(rv.cash_sales), invoiced: num(rv.invoiced), total: num(rv.total), unconvertedLines: num(rv.unconverted_lines) },
    costs: { byCategory: byCat(c.by_category), cash: num(c.cash), nonCash: num(c.non_cash), excluded: num(c.excluded), total: num(c.total) },
    net: num(r.net), marginPct: num(r.margin_pct), fx: numOrNull(r.fx_usd_ugx),
    materialPerKg: numOrNull(mat.per_kg), materialSource: String(mat.source ?? ''),
    unitCosts: (Array.isArray(r.unit_costs) ? r.unit_costs : []).map((x): UnitCostRow => {
      const u = rec(x);
      return { productId: String(u.product_id ?? ''), product: String(u.product ?? ''), kind: String(u.kind ?? ''), basis: u.basis === 'kg' ? 'kg' : 'unit', units: num(u.units), material: num(u.material), overhead: num(u.overhead), total: num(u.total), priceUgx: num(u.price_ugx), marginPct: num(u.margin_pct) };
    }),
  };
}

export interface GroupPnl {
  period: string; from: string; to: string; provisional: boolean; sites: Pnl[];
  group: { revenue: number; costs: number; net: number; byCategory: Record<string, number>; marginPct: number; fx: number | null; usd: { revenue: number | null; costs: number | null; net: number | null } };
}
export function groupPnlFromApi(raw: unknown, siteOf: SiteOf): GroupPnl {
  const r = rec(raw); const g = rec(r.group); const u = rec(g.usd);
  return {
    period: String(r.period ?? ''), from: String(r.from ?? ''), to: String(r.to ?? ''), provisional: r.provisional !== false,
    sites: (Array.isArray(r.sites) ? r.sites : []).map((s) => pnlFromApi(s, siteOf)),
    group: { revenue: num(g.revenue), costs: num(g.costs), net: num(g.net), byCategory: byCat(g.by_category), marginPct: num(g.margin_pct), fx: numOrNull(g.fx_usd_ugx), usd: { revenue: numOrNull(u.revenue), costs: numOrNull(u.costs), net: numOrNull(u.net) } },
  };
}

/** Why a unit cost list may be empty: the server only costs products with output on closed cash days and a price. */
export function unitCostGap(p: Pnl): string {
  if (p.closedDays.length === 0) return 'No cash day is closed in this period yet, so there is no output to cost.';
  return 'No product with output, a price and more than one unit in this period.';
}

export const monthOf = (day: string) => day.slice(0, 7);
export function shiftMonth(month: string, delta: number): string {
  const [y, m] = month.split('-').map(Number); const t = y * 12 + (m - 1) + delta;
  return `${Math.floor(t / 12)}-${String((t % 12) + 1).padStart(2, '0')}`;
}
