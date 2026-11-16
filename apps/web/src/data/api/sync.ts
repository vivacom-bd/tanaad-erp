// Real-mode runtime: store events → outbox → POST /events, and snapshot delta pulls back into the store.
// Nothing here runs in demo mode (no VITE_API_URL, or VITE_DEMO=1): the sink is never installed and no timer starts.
import { loadDirectory } from './names';
import { create } from 'zustand';
import type { DomainEvent, SiteCode } from '@tanaad/domain';
import { eventSink, useStore, type AppState } from '../store';
import { DEMO } from './env';
import { ApiError, api } from './http';
import { clearSession, loadSession, login, logout, me, pinLogin } from './auth';
import { enqueue, flushOutbox, outboxDb, pending, type OutboxItem } from './outbox';
import { pullBatch, setHighWater } from './snapshots';
import { bootstrapSite } from './bootstrap';
import { rememberUser } from './users';
import {
  attendanceFromRow, cashDayFromRow, cashEntryFromRow, engagementFromRow, exceptionFromRow, mergeById,
  personFromRow, provisionalPersonRef, toEnvelope, type SiteOf,
} from './mapping';
import { deliveryFromRow, loadCollectorLedger, resetCollectorLedger } from './recycling';
import { STOCK_PROJECTIONS, mergeStockRows, refreshStockViews, resetStock } from './stock';
import { PRODUCTION_PROJECTIONS, applyConfig, mergeProductionRows, refreshProduction, resetProduction } from './production';
import { loadSettlement, refreshQueued, resetSettlement } from './settlement';
import { SPARES_PROJECTIONS, mergeSparesRows, resetSpares } from './spares';
import { canRead } from './readAccess';
import { refreshDispatches, resetDispatches } from './dispatch';
import { SALES_PROJECTIONS, isSalesProj, mergeSalesRows, pruneRejectedOrders, resetSales } from './sales';
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
export const siteCodeOf = siteOf;

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

/** A cash write the server refused must not stay on screen: drop the optimistic line and read the site's cash pages again from the server. */
export async function undoRejectedCash(site: string, rejected: OutboxItem[]) {
  const cash = rejected.filter((r) => r.envelope.type.startsWith('cash.')); if (!cash.length) return;
  const ids = new Set(cash.map((r) => r.envelope.payload.entry_id).filter((x): x is string => typeof x === 'string'));
  if (ids.size) useStore.setState((st) => ({ cashEntries: st.cashEntries.filter((e) => !ids.has(e.id)) }));
  for (const p of ['cash_days', 'cash_entries']) setHighWater(site, p, 0);
}

let inflight: Promise<void> | null = null;
/** A write queued while a sync is running is not in that sync's batch: run once more when it ends instead of waiting for the 30 s timer. */
let rerun = false;
/** Flush every site's outbox in order, then delta-pull. Single-flight; network failures leave items queued. */
export function syncNow(): Promise<void> {
  if (!real) return Promise.resolve();
  if (inflight) { rerun = true; return inflight; }
  inflight = (async () => {
    const s = loadSession(); if (!s) return;
    useSync.setState({ syncing: true });
    try {
      for (const site of new Set((await outboxDb.outbox.toArray()).map((i) => i.siteCode))) {
        if ((await pending(site)).length) await undoRejectedCash(site, (await flushOutbox(site, s.token, applyAssigned)).rejected);
      }
      await loadDirectory(s.token);
      await syncSites(s);
      useSync.setState({ lastSyncAt: new Date().toISOString(), lastError: undefined });
      useStore.setState({ online: true });
    } catch (e) {
      const err = e instanceof ApiError ? e : null;
      // Only a failed connection means offline; any other error (a 429, a 5xx on one read) came from a server that answered.
      useStore.setState({ online: err?.code !== 'network' });
      if (err?.code === 'unauthenticated') { clearSession(); useStore.getState().signOut(); }
      useSync.setState({ lastError: e instanceof Error ? e.message : String(e) });
    } finally {
      await refreshCounts();
      await pruneRejectedOrders().catch(() => undefined);
      await refreshQueued();
      useSync.setState({ syncing: false });
    }
  })().finally(() => { inflight = null; if (rerun) { rerun = false; void syncNow(); } });
  return inflight;
}

/* ───────────── incoming ───────────── */

type Rows = Record<string, unknown>[];
const PROJECTIONS = ['people', 'engagements', 'attendance', 'cash_days', 'cash_entries', 'exceptions', 'collector_deliveries', ...PRODUCTION_PROJECTIONS, ...STOCK_PROJECTIONS, ...SPARES_PROJECTIONS, ...SALES_PROJECTIONS] as const;
type Proj = typeof PROJECTIONS[number];
const serverIds = new Set<string>();

/** Merge pulled rows into the store. Server rows win over optimistic rows with the same id. */
export function mergeRows(proj: Proj, rows: Rows) {
  if (proj === 'parts' || proj === 'part_issues' || proj === 'breakdowns') return mergeSparesRows(proj, rows, siteOf);
  if (proj === 'production_entries' || proj === 'labels' || proj === 'waste_weighings' || proj === 'production_shifts_closed') return mergeProductionRows(proj, rows, siteOf, serverIds);
  if (isSalesProj(proj)) return mergeSalesRows(proj, rows, siteOf, serverIds);
  if (proj === 'stock_on_hand' || proj === 'purchases') return mergeStockRows(proj, rows, siteOf, serverIds);
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
      case 'collector_deliveries': return { collectorDeliveries: mergeById(st.collectorDeliveries, wrap(deliveryFromRow)) }; // same id as the optimistic row, so the server's voucher and weights replace it
      case 'exceptions': {
        const merged = mergeById(st.exceptions, wrap(exceptionFromRow));
        return { exceptions: merged.filter((x) => serverIds.has(x.id)) }; // exceptions are raised by the server
      }
    }
  });
}

/** Tables to ask for at a site: every projection this role may read (the server would answer 403 for the rest). */
const tablesFor = (s: Session, site: SiteCode) => PROJECTIONS.filter((p) => !(p === 'collector_deliveries' && site !== 'recycling') && canRead(s.user.roles, site, p));

/** One batched request for all of a site's readable tables; rows are merged into the store. A table refused inline (403/404) is skipped. */
export async function pullSite(site: SiteCode, s: Session | null = loadSession()): Promise<{ snapshotSeq: number; changed: boolean } | null> {
  if (!s || !real) return null;
  const tables = tablesFor(s, site); if (!tables.length) return null;
  const res = await pullBatch<Record<string, unknown>>(site, tables, s.token);
  let changed = false;
  for (const proj of tables) {
    const o = res.tables[proj]; if (!o?.ok) continue;
    if (o.rows.length) changed = true;
    mergeRows(proj, o.rows);
  }
  return { snapshotSeq: res.snapshotSeq, changed };
}

/** Pull the given sites' tables now (default: every site of the session), one batched call per site, in order. */
export async function pullAll(sites?: SiteCode[]): Promise<void> {
  const s = loadSession(); if (!s || !real) return;
  for (const site of sites ?? sessionSites(s)) await pullSite(site, s);
}

/* ───────────── what a sync cycle asks for ─────────────
 * Tables: one batched request per site. Views (production wall, stock ledger, settlement, dispatches, collector ledger) are separate
 * endpoints, so they are read only when the ledger moved (snapshot_seq) or the day turned. A group user's other sites are kept
 * fresh at most every 5 minutes, one site per cycle, so a cycle stays small; the site on screen is always current. */
const OTHER_SITE_EVERY_MS = 5 * 60_000;
interface SiteSync { viewsSeq?: number; viewsDay?: string; viewsAt: number; pulledAt: number }
const siteSync = new Map<SiteCode, SiteSync>();
export const resetSiteSync = () => siteSync.clear();

/** The site the user is looking at: the only site of a single-site role, else the open workspace. Undefined on the group overview. */
export function viewedSite(sites: SiteCode[]): SiteCode | undefined {
  if (sites.length === 1) return sites[0];
  // A CEO or group admin opening a site's pages has no site in the session: the address says which one is on screen.
  const open = typeof location !== 'undefined' ? /^\/s\/(mats|bags|recycling)(?:\/|$)/.exec(location.pathname)?.[1] : undefined;
  if (open && sites.includes(open as SiteCode)) return open as SiteCode;
  const cur = useStore.getState().session?.siteId;
  return cur && sites.includes(cur) ? cur : undefined;
}

const toleratingNetwork = (e: unknown) => { if (e instanceof ApiError && e.code === 'network') throw e; };

async function refreshViews(s: Session, site: SiteCode) {
  await refreshProduction([site]).catch(toleratingNetwork); // the server's wall, mass balance and output
  await refreshStockViews([site], siteOf).catch(toleratingNetwork); // ledger, transfers and today's counts
  if (site === 'recycling' && canRead(s.user.roles, 'recycling', 'collector_ledger')) await loadCollectorLedger();
  await loadSettlement([site]); // runs and advances come from their own endpoints
  await refreshDispatches([site]); // dispatches carry unscans, so the list is read whole
}

async function syncSites(s: Session) {
  const sites = sessionSites(s); const viewed = viewedSite(sites); const now = Date.now(); const day = useStore.getState().today;
  let otherViewsDone = false;
  for (const site of sites) {
    const st = siteSync.get(site) ?? { viewsAt: now, pulledAt: 0 };
    siteSync.set(site, st);
    const onScreen = site === viewed;
    // The group overview (no site open) still needs every site's tables, which is one cheap request each; with a site open the others wait.
    if (!(onScreen || viewed === undefined || st.pulledAt === 0 || now - st.pulledAt >= OTHER_SITE_EVERY_MS)) continue;
    const r = await pullSite(site, s); st.pulledAt = now;
    const seq = r ? r.snapshotSeq : Number.NaN; // no readable table: nothing to compare, so the views are read every cycle
    const moved = st.viewsSeq !== seq || st.viewsDay !== day;
    if (!moved) continue;
    if (!onScreen && (otherViewsDone || now - st.viewsAt < OTHER_SITE_EVERY_MS)) continue;
    await refreshViews(s, site);
    st.viewsSeq = seq; st.viewsDay = day; st.viewsAt = Date.now();
    if (!onScreen) otherViewsDone = true;
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
  rememberUser(s.user);
  for (const site of sessionSites(s)) {
    const cfg = await bootstrapSite(site, s.token);
    siteIds.set(String(cfg.site.id), site);
    applyConfig(site, cfg);
    for (const p of PROJECTIONS) setHighWater(site, p, 0);
  }
  useStore.setState({ people: [], engagements: [], attendance: [], cashDays: [], cashEntries: [], exceptions: [], collectorDeliveries: [], purchases: [], stockCounts: [] });
  resetSales();
  resetStock();
  serverIds.clear();
  resetSettlement();
  resetCollectorLedger();
  resetProduction();
  resetSpares();
  resetDispatches();
  resetSiteSync();
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
let unwatchSite: (() => void) | null = null;
const onOnline = () => { void syncNow(); };
// A sync is 1 request per site while nothing moved (about a dozen with a site's views refreshed), against a per-user limit of 600/min shared by all that user's tabs and devices.
// A hidden tab does not poll; it catches up once when it is shown again.
const onVisible = () => { if (typeof document !== 'undefined' && document.visibilityState === 'visible') void syncNow(); };

/** Install the sink and start the 30 s timer + online listener. No-op in demo mode. */
export function startSync() {
  if (!real) return;
  eventSink.current = onEvent;
  if (timer) return;
  timer = setInterval(() => { if (typeof document === 'undefined' || document.visibilityState === 'visible') void syncNow(); }, 30_000);
  if (typeof window !== 'undefined') { window.addEventListener('online', onOnline); document.addEventListener('visibilitychange', onVisible); }
  // Opening another site's workspace makes it the one kept fully current: sync at once.
  unwatchSite = useStore.subscribe((st, prev) => { if (st.session?.siteId !== prev.session?.siteId && st.session) void syncNow(); });
  void (async () => {
    const s = loadSession();
    if (s) rememberUser(s.user);
    if (s && siteIds.size === 0) {
      for (const site of sessionSites(s)) { try { const cfg = await bootstrapSite(site, s.token); siteIds.set(String(cfg.site.id), site); applyConfig(site, cfg); } catch { /* offline */ } }
    }
    await refreshCounts();
    await syncNow();
  })();
}

export function stopSync() {
  eventSink.current = null;
  if (timer) clearInterval(timer);
  timer = null;
  unwatchSite?.(); unwatchSite = null;
  if (typeof window !== 'undefined') { window.removeEventListener('online', onOnline); document.removeEventListener('visibilitychange', onVisible); }
}

