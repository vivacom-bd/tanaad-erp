// Real-mode production actions that are not store events: a waste weighing (offline-capable, through the outbox),
// a mixer batch (same), and closing a shift (online, so the supervisor sees the server's answer at once).
import type { SiteCode } from '@tanaad/domain';
import { useStore, type AppState } from '../store';
import { uuid7, nowIso } from '../ids';
import { ApiError, api } from './http';
import { loadSession } from './auth';
import { enqueue } from './outbox';
import { refreshCounts, syncNow } from './sync';
import { batchPayload, closeShiftMessage, closeShiftPayload, closedKey, wastePayload, type BatchLine, type WasteWeighing } from './productionMap';
import { emptyViews } from './production';

async function queue(site: SiteCode, type: string, payload: Record<string, unknown>) {
  const s = loadSession(); if (!s) return;
  await enqueue(site, { id: uuid7(), type, version: 1, occurred_at: nowIso(), payload, ...(s.device?.id ? { device_id: s.device.id } : {}) });
  await refreshCounts();
  void syncNow();
}

/** Weigh waste: shown at once, kept in the outbox while offline, and counted in the server's mass balance after sync. */
export async function weighWaste(w: Omit<WasteWeighing, 'id'>): Promise<WasteWeighing> {
  const row: WasteWeighing = { ...w, id: uuid7() };
  useStore.setState((st): Partial<AppState> => { const v = st.serverViews ?? emptyViews(); return { serverViews: { ...v, waste: [...(v.waste ?? []), row] } }; });
  await queue(w.siteId, 'production.waste_weighed', wastePayload({ wasteId: row.id, ...w }));
  return row;
}

/** Record a mixer batch with its recipe lines. The batch label stays a label; this gives the server the recipe and the output. */
export async function recordBatch(b: { site: SiteCode; day: string; kind?: string; machineId?: string; lines: BatchLine[]; outputKg: number; labelCode?: string; note?: string }): Promise<void> {
  await queue(b.site, 'production.batch_recorded', batchPayload({ batchId: uuid7(), ...b }));
}

export const isShiftClosed = (views: AppState['serverViews'], site: string, day: string, kind: string) => !!views?.closed?.includes(closedKey(site, day, kind));

/** Close a shift. Throws an Error with a plain message when the server refuses (409 already closed, 403, offline). */
export async function closeShift(site: SiteCode, day: string, kind: string): Promise<void> {
  const s = loadSession(); if (!s) throw new Error('Sign in again to close the shift.');
  try {
    await api(`/sites/${encodeURIComponent(site)}/production/shifts/close`, { method: 'POST', token: s.token, body: closeShiftPayload(day, kind) });
  } catch (e) {
    if (e instanceof ApiError) {
      if (e.status === 409) markClosed(site, day, kind);
      throw new Error(e.code === 'network' ? 'No connection. A shift can only be closed while online.' : closeShiftMessage(e.status, e.detail));
    }
    throw e;
  }
  markClosed(site, day, kind);
  void syncNow();
}

function markClosed(site: string, day: string, kind: string) {
  useStore.setState((st): Partial<AppState> => { const v = st.serverViews ?? emptyViews(); return { serverViews: { ...v, closed: [...new Set([...(v.closed ?? []), closedKey(site, day, kind)])] } }; });
}
