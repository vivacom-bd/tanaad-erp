// Real-mode group and costing reads and writes. Reads are role-tolerant: a 403 means this role does not see the view, so it is skipped (null),
// never an error. Writes (decide an order, close or reopen a period) are online-only and surface the server's own message.
// Nothing here runs in demo mode.
import { create } from 'zustand';
import type { SiteCode } from '@tanaad/domain';
import { ApiError, api } from './http';
import { loadSession } from './auth';
import { siteCodeOf } from './sync';
import {
  approvalsFromApi, auditPageFromApi, cockpitFromApi, groupPnlFromApi, pnlFromApi,
  type ApprovalCounts, type ApprovalOrder, type AuditPage, type Cockpit, type GroupPnl, type Pnl, type ServerAudit,
} from './groupMap';

/** GET that treats 403 (role may not read this view) and 404 as "not available" instead of an error. Network and other failures still throw. */
export async function tolerantGet<T>(path: string, token: string): Promise<T | null> {
  try { return await api<T>(path, { token }); } catch (e) { if (e instanceof ApiError && (e.status === 403 || e.status === 404)) return null; throw e; }
}

export interface AuditQuery { site?: SiteCode | 'all'; type?: string; from?: string; to?: string }
interface GroupState {
  token?: string;
  cockpit: Cockpit | null; cockpitLoaded: boolean;
  approvalOrders: ApprovalOrder[]; approvalCounts: ApprovalCounts | null; approvalsLoaded: boolean;
  audit: ServerAudit[]; auditPage: number; auditLast: number; auditTotal: number; auditLoaded: boolean;
  groupPnl: Record<string, GroupPnl | null>;
  sitePnl: Record<string, Pnl | null>;
}

const empty = (): GroupState => ({ cockpit: null, cockpitLoaded: false, approvalOrders: [], approvalCounts: null, approvalsLoaded: false, audit: [], auditPage: 0, auditLast: 1, auditTotal: 0, auditLoaded: false, groupPnl: {}, sitePnl: {} });
export const useServerGroup = create<GroupState>(() => empty());
export const resetGroup = () => useServerGroup.setState(empty(), true);

/** Drop another user's data when the session changes. */
function guard(token: string) { if (useServerGroup.getState().token !== token) useServerGroup.setState({ ...empty(), token }, true); }

export async function loadCockpit(): Promise<boolean> {
  const s = loadSession(); if (!s) return false; guard(s.token);
  const raw = await tolerantGet<{ data: unknown }>('/group/cockpit', s.token);
  useServerGroup.setState({ cockpit: raw ? cockpitFromApi(raw.data, siteCodeOf) : null, cockpitLoaded: true });
  return !!raw;
}

export async function loadApprovals(): Promise<boolean> {
  const s = loadSession(); if (!s) return false; guard(s.token);
  const raw = await tolerantGet<{ data: unknown }>('/group/approvals', s.token);
  const m = raw ? approvalsFromApi(raw.data, siteCodeOf) : null;
  useServerGroup.setState({ approvalOrders: m?.orders ?? [], approvalCounts: m?.counts ?? null, approvalsLoaded: true });
  return !!raw;
}

export const auditPath = (q: AuditQuery, page: number, perPage = 100) => {
  const p = new URLSearchParams();
  if (q.site && q.site !== 'all') p.set('site', q.site);
  if (q.type) p.set('type', q.type);
  if (q.from) p.set('from', q.from);
  if (q.to) p.set('to', q.to);
  p.set('page', String(page)); p.set('per_page', String(perPage));
  return `/group/audit?${p.toString()}`;
};

/** Page 1 replaces the feed, later pages append. */
export async function loadAudit(q: AuditQuery, page = 1): Promise<boolean> {
  const s = loadSession(); if (!s) return false; guard(s.token);
  const raw = await tolerantGet<unknown>(auditPath(q, page), s.token);
  if (!raw) { useServerGroup.setState({ audit: page === 1 ? [] : useServerGroup.getState().audit, auditLoaded: true }); return false; }
  const p: AuditPage = auditPageFromApi(raw, siteCodeOf);
  useServerGroup.setState((st) => ({ audit: page === 1 ? p.rows : [...st.audit, ...p.rows.filter((r) => !st.audit.some((x) => x.id === r.id))], auditPage: p.page, auditLast: p.lastPage, auditTotal: p.total, auditLoaded: true }));
  return true;
}

export async function loadGroupPnl(month: string): Promise<boolean> {
  const s = loadSession(); if (!s) return false; guard(s.token);
  const raw = await tolerantGet<unknown>(`/costing/pnl?month=${encodeURIComponent(month)}`, s.token);
  useServerGroup.setState((st) => ({ groupPnl: { ...st.groupPnl, [month]: raw ? groupPnlFromApi(raw, siteCodeOf) : null } }));
  return !!raw;
}

export const pnlKey = (site: string, month: string) => `${site}|${month}`;
/** One site's P&L and unit costs for a month (two endpoints, merged). */
export async function loadSiteCosting(site: SiteCode, month: string): Promise<boolean> {
  const s = loadSession(); if (!s) return false; guard(s.token);
  const base = `/sites/${encodeURIComponent(site)}/costing`;
  const [p, u] = await Promise.all([tolerantGet<unknown>(`${base}/pnl?month=${month}`, s.token), tolerantGet<unknown>(`${base}/unit-cost?month=${month}`, s.token)]);
  const pnl = p ? { ...pnlFromApi(p, siteCodeOf), siteCode: site, unitCosts: u ? pnlFromApi(u).unitCosts : [] } : null;
  useServerGroup.setState((st) => ({ sitePnl: { ...st.sitePnl, [pnlKey(site, month)]: pnl } }));
  return !!p;
}

/** Close a month for a site. Online-only; the server answers with the frozen figures. */
export async function closePeriod(site: SiteCode, month: string): Promise<Pnl> {
  const s = loadSession(); if (!s) throw new ApiError(401, 'unauthenticated', 'Sign in first.');
  const res = await api<{ period: unknown }>(`/sites/${encodeURIComponent(site)}/costing/periods/close`, { method: 'POST', token: s.token, body: { month } });
  await Promise.all([loadSiteCosting(site, month), loadGroupPnl(month).catch(() => false)]);
  return pnlFromApi(res.period, siteCodeOf);
}

/** Reopen a closed month. The reason is required and goes on the audit feed. */
export async function reopenPeriod(site: SiteCode, month: string, reason: string): Promise<Pnl> {
  const s = loadSession(); if (!s) throw new ApiError(401, 'unauthenticated', 'Sign in first.');
  if (!reason.trim()) throw new ApiError(422, 'invalid_payload', 'Say why the period is being reopened.');
  const res = await api<{ period: unknown }>(`/sites/${encodeURIComponent(site)}/costing/periods/reopen`, { method: 'POST', token: s.token, body: { month, reason: reason.trim() } });
  await Promise.all([loadSiteCosting(site, month), loadGroupPnl(month).catch(() => false)]);
  return pnlFromApi(res.period, siteCodeOf);
}

/** Approve or hold an order awaiting approval (online-only). A hold needs a reason; the server says so if it is missing. */
export async function decideOrderOnServer(site: SiteCode, orderId: string, decision: 'approved' | 'held', reason?: string): Promise<void> {
  const s = loadSession(); if (!s) throw new ApiError(401, 'unauthenticated', 'Sign in first.');
  if (typeof navigator !== 'undefined' && !navigator.onLine) throw new ApiError(0, 'network', 'You are offline. Orders are decided online.');
  const verb = decision === 'approved' ? 'approve' : 'hold';
  await api(`/sites/${encodeURIComponent(site)}/orders/${encodeURIComponent(orderId)}/${verb}`, { method: 'POST', token: s.token, body: reason?.trim() ? { reason: reason.trim() } : {} });
}

/** Plain wording for a failed online-only write. */
export function writeError(e: unknown): string {
  if (!(e instanceof ApiError)) return 'Something went wrong.';
  if (e.code === 'network') return 'Cannot reach the server. Try again when you are online.';
  if (e.status === 403) return e.detail || 'You do not have permission to do this.';
  if (e.status === 409) return e.detail || 'This changed on the server. Refresh and look again.';
  return e.detail || 'The server refused this.';
}

/** Roles of the signed-in user, to decide which buttons to offer (the server still decides). */
export function sessionRoleNames(): string[] { return loadSession()?.user.roles.map((r) => r.role) ?? []; }
export const canCloseCosting = (roles = sessionRoleNames()) => roles.some((r) => r === 'site_manager' || r === 'ceo' || r === 'group_admin');
export const canReadGroupViews = (roles = sessionRoleNames()) => roles.some((r) => r === 'ceo' || r === 'group_admin');
