// Real-mode spares and maintenance: the server's parts, issues and breakdowns merged into the store, and the online-only
// actions (the server alone checks the stock, the roles and the machine). Nothing here runs in demo mode.
import type { SiteCode } from '@tanaad/domain';
import { useStore, type AppState } from '../store';
import { uuid7 } from '../ids';
import { ApiError, api } from './http';
import { loadSession } from './auth';
import { mergeById, type SiteOf } from './mapping';
import { pullBatch } from './snapshots';
import { canRead } from './readAccess';
import { siteCodeOf } from './sync';
import { breakdownFromRow, issueBody, issueFromRow, machineStatuses, partActive, partFromRow, receiveBody, reportBody, sparesMessage } from './sparesMap';

type Rows = Record<string, unknown>[];
export const SPARES_PROJECTIONS = ['parts', 'part_issues', 'breakdowns'] as const;
export type SparesProj = typeof SPARES_PROJECTIONS[number];

/** Machines show down while a breakdown is open: the server holds the breakdown, the machine row comes from config. */
function syncMachineStatus() {
  useStore.setState((st): Partial<AppState> => {
    const changes = machineStatuses(st.breakdowns, st.machines);
    return changes.size ? { machines: st.machines.map((m) => (changes.has(m.id) ? { ...m, status: changes.get(m.id)! } : m)) } : {};
  });
}

/** Merge pulled rows. Server rows win; a part switched off on the server leaves the lists. */
export function mergeSparesRows(proj: SparesProj, rows: Rows, siteOf: SiteOf) {
  useStore.setState((st): Partial<AppState> => {
    if (proj === 'parts') return { parts: mergeById(st.parts, rows.map((r) => ({ row: partFromRow(r, siteOf), deleted: !!r.deletedAt || !partActive(r) }))) };
    if (proj === 'part_issues') return { partIssues: mergeById(st.partIssues, rows.map((r) => ({ row: issueFromRow(r, siteOf), deleted: !!r.deletedAt }))) };
    return { breakdowns: mergeById(st.breakdowns, rows.map((r) => ({ row: breakdownFromRow(r, siteOf), deleted: !!r.deletedAt }))) };
  });
  if (proj === 'breakdowns') syncMachineStatus();
}

/** First sign-in in real mode: the demo spares are replaced by what the server holds. */
export function resetSpares() { useStore.setState({ parts: [], partIssues: [], breakdowns: [] }); }

async function call<T>(site: SiteCode, path: string, body: unknown, what: Parameters<typeof sparesMessage>[1]): Promise<T> {
  const s = loadSession(); if (!s) throw new Error('Sign in again.');
  try { return await api<T>(`/sites/${encodeURIComponent(site)}${path}`, { method: 'POST', token: s.token, body }); }
  catch (e) { throw e instanceof ApiError ? new Error(sparesMessage(e, what)) : e; }
}
const siteOfStore: SiteOf = (id) => siteCodeOf(id); // the server's site id to the store's site code
const upsert = <K extends 'parts' | 'partIssues' | 'breakdowns'>(key: K, row: AppState[K][number]) =>
  useStore.setState((st): Partial<AppState> => ({ [key]: [row, ...(st[key] as { id: string }[]).filter((x) => x.id !== (row as { id: string }).id)] } as Partial<AppState>));

/** Issue a part against a machine and a mechanic. The server checks stock and role; the answer is the part with its new level. */
export async function issuePartOnline(p: { siteId: SiteCode; partId: string; machineId: string; mechanicId: string; qty: number }): Promise<{ ok: boolean; message: string; left?: number; below?: boolean }> {
  try {
    const r = await call<{ issue: Record<string, unknown> }>(p.siteId, `/parts/${encodeURIComponent(p.partId)}/issue`, issueBody({ issueId: uuid7(), qty: p.qty, machineId: p.machineId, mechanicId: p.mechanicId }), 'issue');
    upsert('partIssues', issueFromRow(r.issue, siteOfStore));
    const before = useStore.getState().parts.find((x) => x.id === p.partId);
    await refreshSpares(p.siteId); // the part's new level and any breakdown cost come from the server
    const after = useStore.getState().parts.find((x) => x.id === p.partId);
    return { ok: true, message: `${before?.name ?? 'Part'} ×${p.qty} issued`, left: after?.onHand, below: after ? after.onHand < after.minLevel : undefined };
  } catch (e) { return { ok: false, message: e instanceof Error ? e.message : 'The part could not be issued.' }; }
}

export async function receivePartOnline(siteId: SiteCode, partId: string, qty: number): Promise<{ ok: boolean; message: string; onHand?: number }> {
  try {
    const r = await call<{ part: Record<string, unknown> }>(siteId, `/parts/${encodeURIComponent(partId)}/receive`, receiveBody({ receiptId: uuid7(), qty }), 'receive');
    const part = partFromRow(r.part, siteOfStore); upsert('parts', part);
    return { ok: true, message: `${part.name} +${qty} ${part.unit}`, onHand: part.onHand };
  } catch (e) { return { ok: false, message: e instanceof Error ? e.message : 'The parts could not be booked in.' }; }
}

export async function reportBreakdownOnline(siteId: SiteCode, machineId: string, cause: string): Promise<{ ok: boolean; message: string }> {
  try {
    const r = await call<{ breakdown: Record<string, unknown> }>(siteId, '/breakdowns', reportBody({ id: uuid7(), machineId, cause }), 'report');
    upsert('breakdowns', breakdownFromRow(r.breakdown, siteOfStore)); syncMachineStatus();
    return { ok: true, message: 'Breakdown reported' };
  } catch (e) { return { ok: false, message: e instanceof Error ? e.message : 'The breakdown could not be reported.' }; }
}

export async function resolveBreakdownOnline(siteId: SiteCode, breakdownId: string): Promise<{ ok: boolean; message: string }> {
  try {
    const r = await call<{ breakdown: Record<string, unknown> }>(siteId, `/breakdowns/${encodeURIComponent(breakdownId)}/resolve`, {}, 'resolve');
    upsert('breakdowns', breakdownFromRow(r.breakdown, siteOfStore)); syncMachineStatus();
    return { ok: true, message: 'Breakdown resolved' };
  } catch (e) { return { ok: false, message: e instanceof Error ? e.message : 'The breakdown could not be resolved.' }; }
}

/** Pull a site's parts, issues and breakdowns now (after an action). A role that may not read one of them is skipped. */
export async function refreshSpares(site: SiteCode): Promise<void> {
  const s = loadSession(); if (!s) return;
  const tables = SPARES_PROJECTIONS.filter((p) => canRead(s.user.roles, site, p));
  if (!tables.length) return;
  const res = await pullBatch<Record<string, unknown>>(site, tables, s.token).catch((e) => { if (e instanceof ApiError && e.code === 'network') return null; throw e; });
  if (!res) return;
  for (const proj of tables) { const o = res.tables[proj]; if (o?.ok) mergeSparesRows(proj, o.rows, siteOfStore); }
}
