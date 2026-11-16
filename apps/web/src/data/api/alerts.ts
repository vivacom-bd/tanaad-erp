import { create } from 'zustand';
import type { BusinessException, SiteCode } from '@tanaad/domain';
import { api } from './http';
import { loadSession } from './auth';

export interface BellAlert { id: string; title: string; detail: string; at: string; siteId?: SiteCode; severity: 'info' | 'warning' | 'critical'; read: boolean }

/** What the bell lists from the server (GET /alerts). Empty until it answers; a server without the endpoint leaves it empty. */
export const useServerAlerts = create<{ items: Omit<BellAlert, 'read'>[]; available: boolean }>(() => ({ items: [], available: false }));

const SEV = new Set(['info', 'warning', 'critical']);
export function alertFromRow(r: Record<string, unknown>): Omit<BellAlert, 'read'> | null {
  if (!r || (r.id === undefined && r.title === undefined)) return null;
  const sev = String(r.severity ?? 'warning');
  const site = r.siteCode ?? r.siteId;
  return {
    id: `srv:${String(r.id ?? r.title)}`, title: String(r.title ?? r.summary ?? 'Alert'), detail: String(r.detail ?? r.body ?? ''),
    at: String(r.at ?? r.raisedAt ?? r.createdAt ?? new Date().toISOString()),
    siteId: typeof site === 'string' && ['mats', 'bags', 'recycling'].includes(site) ? (site as SiteCode) : undefined,
    severity: (SEV.has(sev) ? sev : 'warning') as BellAlert['severity'],
  };
}

/** Poll GET /alerts. 404 (not built yet) or any failure is tolerated: the bell falls back to the open exceptions. */
/** The server answers /alerts to the CEO, group admins and site managers only (403 for everyone else), so nobody else asks. */
export const mayReadAlerts = (roles: readonly { role: string }[]) => roles.some((r) => r.role === 'ceo' || r.role === 'group_admin' || r.role === 'site_manager');

export async function loadServerAlerts(): Promise<void> {
  const s = loadSession(); if (!s) return;
  if (!mayReadAlerts(s.user.roles)) { useServerAlerts.setState({ items: [], available: false }); return; }
  try {
    const res = await api<{ data?: Record<string, unknown>[] } | Record<string, unknown>[]>('/alerts', { token: s.token });
    const rows = Array.isArray(res) ? res : res?.data ?? [];
    useServerAlerts.setState({ items: rows.map(alertFromRow).filter((x): x is Omit<BellAlert, 'read'> => !!x), available: true });
  } catch { useServerAlerts.setState({ items: [], available: false }); }
}

const RANK = { critical: 0, warning: 1, info: 2 } as const;

/** Open critical and warning exceptions as bell items: gate holds, credit over limit, cash variance and the rest. */
export function alertsFromExceptions(ex: BusinessException[], site?: SiteCode): Omit<BellAlert, 'read'>[] {
  return ex.filter((e) => e.status === 'open' && e.severity !== 'info' && (!site || e.siteId === site))
    .map((e) => ({ id: `ex:${e.id}`, title: e.title, detail: e.detail, at: e.raisedAt, siteId: e.siteId, severity: e.severity }));
}

/** Merge local pushes, the server's list (or, without it, the exceptions), newest first with critical ahead; mark what has been seen. */
export function mergeAlerts(local: { id: string; title: string; detail: string; at: string; siteId?: SiteCode; read: boolean }[], server: { items: Omit<BellAlert, 'read'>[]; available: boolean }, exceptions: BusinessException[], seen: ReadonlySet<string>, site?: SiteCode): BellAlert[] {
  const base = server.available ? server.items.filter((a) => !site || !a.siteId || a.siteId === site) : alertsFromExceptions(exceptions, site);
  const mine = local.filter((a) => !site || !a.siteId || a.siteId === site).map((a) => ({ ...a, severity: 'warning' as const }));
  const seenTitle = new Set(base.map((a) => a.title));
  return [...base.map((a) => ({ ...a, read: seen.has(a.id) })), ...mine.filter((a) => !seenTitle.has(a.title))]
    .sort((a, b) => RANK[a.severity] - RANK[b.severity] || b.at.localeCompare(a.at));
}

const KEY = 'tanaad.alerts.seen';
export function loadSeen(): Set<string> { try { return new Set(JSON.parse(localStorage.getItem(KEY) ?? '[]') as string[]); } catch { return new Set(); } }
export function saveSeen(ids: Set<string>) { try { localStorage.setItem(KEY, JSON.stringify([...ids].slice(-500))); } catch { /* storage unavailable: unread state is per visit */ } }
