// Real-mode runtime: store events → outbox → POST /events, and snapshot delta pulls back into the store.
// Nothing here runs in demo mode (no VITE_API_URL, or VITE_DEMO=1): the sink is never installed and no timer starts.
import { create } from 'zustand';
import type { DomainEvent, SiteCode } from '@tanaad/domain';
import { eventSink, useStore, type AppState } from '../store';
import { DEMO } from './env';
import { ApiError, api } from './http';
import { clearSession, loadSession, login, logout, me, pinLogin } from './auth';
import { enqueue, flushOutbox, outboxDb, pending, type OutboxItem } from './outbox';
import { pullSnapshot, setHighWater } from './snapshots';
import { bootstrapSite } from './bootstrap';
import {
  attendanceFromRow, cashDayFromRow, cashEntryFromRow, engagementFromRow, exceptionFromRow, mergeById,
  personFromRow, provisionalPersonRef, toEnvelope, type SiteOf,
} from './mapping';
import { loadSettlement, refreshQueued, resetSettlement } from './settlement';
import type { Session } from './types';

const ALL_SITES: SiteCode[] = ['mats', 'bags', 'recycling'];
let real = !DEMO;
/** Real mode is on when VITE_API_URL is set and VITE_DEMO is not 1. Tests may flip it. */
export const isReal = () => real;
export function __setReal(v: boolean) { real = v; }

interface SyncState { pending: number; rejected: OutboxItem[]; syncing: boolean; lastSyncAt?: string; lastError?: string }
export const useSync = create<SyncState>(() => ({ pending: 0, rejected: [], syncing: false }));

/* ───────────── site ids ↔ codes ───────────── */
const siteIds = new Map<string, SiteCode>();
const siteOf: SiteOf = (id) => siteIds.get(String(id)) ?? (ALL_SITES.includes(id as SiteCode) ? (id as SiteCode) : 'mats');

export function sessionSites(s: Session | null = loadSession()): SiteCode[] {
  if (!s) return [];
  const codes = [...new Set(s.user.roles.map((r) => r.site_code).filter((c): c is SiteCode => !!c))] as SiteCode[];
  return s.sites === null || codes.length === 0 ? ALL_SITES : codes.filter((c) => ALL_SITES.includes(c));
}

/* ───────────── outgoing ───────────── */

function onEvent(ev: DomainEvent) {
  const s = loadSession(); if (!s) return;
  const out = toEnvelope(ev, s.device?.id);
  if (!out) return;
  void (async () => {
    if (out.envelope.type === 'group.exception_decided' && (typeof navigator === 'undefined' || navigator.onLine)) {
      const p = out.envelope.payload as { exception_id: string; decision: string; note?: string };
      try {
        await api(`/sites/${out.siteCode}/exceptions/${p.exception_id}/decision`, { method: 'POST', token: s.token, body: { decision: p.decision, note: p.note } });
        void pullAll();
        return;
      } catch (e) {
        if (!(e instanceof ApiError) || e.code !== 'network') {
          const err = e instanceof ApiError ? e : new ApiError(0, 'unknown', String(e));
          await outboxDb.rejected.add({ siteCode: out.siteCode, envelope: out.envelope, code: err.code as OutboxItem['code'], reason: err.detail });
          await refreshCounts();
          return;
        }
      }
    }
    await enqueue(out.siteCode, out.envelope);
    await refreshCounts();
    void syncNow();
  })();
}

export function applyAssigned(ref: string, number: string) {
  const prefix = provisionalPersonRef('');
  if (!ref.startsWith(prefix)) return;
  const id = ref.slice(prefix.length);
  useStore.setState((st) => ({ people: st.people.map((p) => (p.id === id ? { ...p, number } : p)) }));
}

export async function refreshCounts() {
  const [p, r] = await Promise.all([outboxDb.outbox.count(), outboxDb.rejected.orderBy('key').toArray()]);
  useSync.setState({ pending: p, rejected: r });
}

let inflight: Promise<void> | null = null;
/** Flush every site's outbox in order, then delta-pull. Single-flight; network failures leave items queued. */
export function syncNow(): Promise<void> {
  if (!real) return Promise.resolve();
  if (inflight) return inflight;
  inflight = (async () => {
    const s = loadSession(); if (!s) return;
    useSync.setState({ syncing: true });
    try {
      for (const site of new Set((await outboxDb.outbox.toArray()).map((i) => i.siteCode))) {
        if ((await pending(site)).length) await flushOutbox(site, s.token, applyAssigned);
      }
      await pullAll();
      await loadSettlement(sessionSites(s)); // runs and advances come from their own endpoints
      useSync.setState({ lastSyncAt: new Date().toISOString(), lastError: undefined });
      useStore.setState({ online: true });
    } catch (e) {
      const err = e instanceof ApiError ? e : null;
      if (err?.code === 'network') useStore.setState({ online: false });
      if (err?.code === 'unauthenticated') { clearSession(); useStore.getState().signOut(); }
      useSync.setState({ lastError: e instanceof Error ? e.message : String(e) });
    } finally {
      await refreshCounts();
      await refreshQueued();
      useSync.setState({ syncing: false });
    }
  })().finally(() => { inflight = null; });
  return inflight;
}

/* ───────────── incoming ───────────── */

type Rows = Record<string, unknown>[];
const PROJECTIONS = ['people', 'engagements', 'attendance', 'cash_days', 'cash_entries', 'exceptions'] as const;
type Proj = typeof PROJECTIONS[number];
const serverIds = new Set<string>();

/** Merge pulled rows into the store. Server rows win over optimistic rows with the same id. */
export function mergeRows(proj: Proj, rows: Rows) {
  const wrap = <T extends { id: string }>(f: (r: Record<string, unknown>, s: SiteOf) => T, gone?: (r: Record<string, unknown>) => boolean) =>
    rows.map((r) => { serverIds.add(String(r.id)); return { row: f(r, siteOf), deleted: !!r.deletedAt || !!gone?.(r) }; });
  useStore.setState((st): Partial<AppState> => {
    switch (proj) {
      case 'people': return { people: mergeById(st.people, wrap(personFromRow)) };
      case 'engagements': {
        const inc = wrap(engagementFromRow); const merged = mergeById(st.engagements, inc);
        // The server assigns its own id to the first engagement of a registration; drop the optimistic twin.
        const key = (e: { personId: string; siteId: string; startDate: string }) => `${e.personId}|${e.siteId}|${e.startDate}`;
        const server = new Set(merged.filter((e) => serverIds.has(e.id)).map(key));
        return { engagements: merged.filter((e) => serverIds.has(e.id) || !server.has(key(e))) };
      }
      case 'attendance': return { attendance: mergeById(st.attendance, wrap(attendanceFromRow)) };
      case 'cash_days': {
        const inc = wrap(cashDayFromRow); const merged = mergeById(st.cashDays, inc);
        const server = new Set(merged.filter((d) => serverIds.has(d.id)).map((d) => `${d.siteId}|${d.day}`));
        const dropped = new Map(merged.filter((d) => !serverIds.has(d.id) && server.has(`${d.siteId}|${d.day}`)).map((d) => [d.id, merged.find((x) => serverIds.has(x.id) && x.siteId === d.siteId && x.day === d.day)!.id]));
        return { cashDays: merged.filter((d) => !dropped.has(d.id)), cashEntries: st.cashEntries.map((e) => (dropped.has(e.cashDayId) ? { ...e, cashDayId: dropped.get(e.cashDayId)! } : e)) };
      }
      case 'cash_entries': return { cashEntries: mergeById(st.cashEntries, wrap(cashEntryFromRow, (r) => !!r.supersededBy)) };
      case 'exceptions': {
        const merged = mergeById(st.exceptions, wrap(exceptionFromRow));
        return { exceptions: merged.filter((x) => serverIds.has(x.id)) }; // exceptions are raised by the server
      }
    }
  });
}

export async function pullAll(): Promise<void> {
  const s = loadSession(); if (!s || !real) return;
  for (const site of sessionSites(s)) {
    for (const proj of PROJECTIONS) {
      const { rows } = await pullSnapshot<Record<string, unknown>>(site, proj, s.token);
      mergeRows(proj, rows);
    }
  }
}

/** CEO / group view: every site's exceptions from GET /exceptions. */
export async function refreshGroupExceptions(): Promise<void> {
  const s = loadSession(); if (!s || !real) return;
  const res = await api<{ data: Rows }>('/exceptions', { token: s.token });
  mergeRows('exceptions', res.data);
}

/** Replace the demo seed's rows for the API-backed projections, then pull everything from zero. */
async function initialLoad(s: Session) {
  for (const site of sessionSites(s)) {
    const cfg = await bootstrapSite(site, s.token);
    siteIds.set(String(cfg.site.id), site);
    for (const p of PROJECTIONS) setHighWater(site, p, 0);
  }
  useStore.setState({ people: [], engagements: [], attendance: [], cashDays: [], cashEntries: [], exceptions: [] });
  serverIds.clear();
  resetSettlement();
  await syncNow();
}

/* ───────────── session ───────────── */

/** Sign in against the API; returns the prototype user id (the API's user code) for the store's logIn(). */
export async function realLogin(username: string, password: string): Promise<string> {
  const s = await login(username, password);
  const m = await me(s.token);
  await initialLoad({ ...s, ...m });
  startSync();
  return m.user.code;
}

/** Quick PIN sign-in against the API; same result as realLogin. */
export async function realPinLogin(username: string, pin: string): Promise<string> {
  const s = await pinLogin(username, pin);
  const m = await me(s.token);
  await initialLoad({ ...s, ...m });
  startSync();
  return m.user.code;
}

/** Wording for a failed sign-in: throttled and wrong-credential cases get plain messages. */
export function signInMessage(e: unknown): string {
  if (!(e instanceof ApiError)) return 'Sign-in failed.';
  if (e.code === 'network') return 'Cannot reach the server.';
  if (e.status === 429) return 'Too many attempts, wait a minute.';
  return e.detail || 'Sign-in failed.';
}

export async function realLogout(): Promise<void> {
  stopSync();
  const s = loadSession();
  if (s) await logout(s.token).catch(() => clearSession());
}

/* ───────────── rejected items ───────────── */

const NOT_RETRYABLE = new Set(['invalid_payload', 'unknown_type', 'duplicate_fact', 'not_found']);
export const canRetry = (i: OutboxItem) => !NOT_RETRYABLE.has(i.code ?? '');

export async function dismissRejected(key: number) { await outboxDb.rejected.delete(key); await refreshCounts(); }
export async function retryRejected(key: number) {
  const item = await outboxDb.rejected.get(key); if (!item) return;
  await outboxDb.rejected.delete(key);
  await enqueue(item.siteCode, item.envelope);
  await refreshCounts();
  await syncNow();
}

/* ───────────── lifecycle ───────────── */

let timer: ReturnType<typeof setInterval> | null = null;
const onOnline = () => { void syncNow(); };

/** Install the sink and start the 30 s timer + online listener. No-op in demo mode. */
export function startSync() {
  if (!real) return;
  eventSink.current = onEvent;
  if (timer) return;
  timer = setInterval(() => { void syncNow(); }, 30_000);
  if (typeof window !== 'undefined') window.addEventListener('online', onOnline);
  void (async () => {
    const s = loadSession();
    if (s && siteIds.size === 0) {
      for (const site of sessionSites(s)) { try { const cfg = await bootstrapSite(site, s.token); siteIds.set(String(cfg.site.id), site); } catch { /* offline */ } }
    }
    await refreshCounts();
    await syncNow();
  })();
}

export function stopSync() {
  eventSink.current = null;
  if (timer) clearInterval(timer);
  timer = null;
  if (typeof window !== 'undefined') window.removeEventListener('online', onOnline);
}

