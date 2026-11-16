import { api, ApiError } from './http';
import { loadSession } from './auth';

/** The opening balance a day will start with (carried forward from the last counted close), from the day read's expectedOpening. Undefined when the server does not send it. */
export async function readExpectedOpening(site: string, day: string): Promise<number | undefined> {
  const s = loadSession(); if (!s) return undefined;
  try {
    const res = await api<{ expectedOpening?: unknown; data?: { expectedOpening?: unknown } }>(`/sites/${encodeURIComponent(site)}/cash/days/${encodeURIComponent(day)}`, { token: s.token });
    const v = res?.expectedOpening ?? res?.data?.expectedOpening;
    const n = v === undefined || v === null || v === '' ? NaN : Number(v);
    return Number.isFinite(n) ? n : undefined;
  } catch { return undefined; }
}

export interface ApprovalManager { id: string; name: string }
/** The active Site Managers of a site, from the directory rows (GET /directory), for a cashier to pick who approves a reopen. */
export function siteManagers(rows: { id: string; name: string; active?: boolean; roles?: unknown }[], siteCode: string): ApprovalManager[] {
  return rows
    .filter((u) => u.active !== false && Array.isArray(u.roles) && (u.roles as { role?: string; siteCode?: string | null }[]).some((r) => r.role === 'site_manager' && r.siteCode === siteCode))
    .map((u) => ({ id: u.id, name: u.name }))
    .sort((a, b) => a.name.localeCompare(b.name));
}

/** True when the signed-in user holds the Site Manager role at this site, so no second person's approval is needed. */
export function isSiteManagerAt(siteCode: string): boolean {
  return !!loadSession()?.user.roles.some((r) => r.role === 'site_manager' && r.site_code === siteCode);
}

/** Plain words for a refused or failed approved reopen. The manager's PIN is never part of a message. */
export function reopenApprovalMessage(e: unknown): string {
  if (!(e instanceof ApiError)) return 'Reopening failed. Try again.';
  if (e.code === 'network' || e.status === 0) return 'Reopening needs a connection.';
  const code = String(e.details?.code ?? '');
  if (e.status === 429 || /lock/i.test(code)) return 'Too many wrong PINs. That manager is locked for a few minutes.';
  if (e.status === 403 && code === 'approval_failed') return 'Manager approval failed. Check the manager and PIN.';
  return e.detail || 'Reopening failed. Try again.';
}

/** Reopen a closed day with a manager's approval typed on this (cashier) device. Online only; the PIN goes in this one request and is kept nowhere. */
export async function reopenWithApproval(site: string, day: string, reason: string, approverUserId: string, approverPin: string): Promise<void> {
  const s = loadSession(); if (!s) throw new ApiError(401, 'unknown', 'Sign in first.');
  await api(`/sites/${encodeURIComponent(site)}/cash/days/${encodeURIComponent(day)}/reopen`, { method: 'POST', token: s.token, body: { reason, approver_user_id: approverUserId, approver_pin: approverPin } });
}
