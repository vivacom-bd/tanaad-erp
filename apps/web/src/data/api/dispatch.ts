// Real-mode dispatch, gate and proof of delivery. Every step is online: the server checks the label, decides the gate pass or
// hold and the variance, allocates the delivery note number, and the screens show exactly what it answers. Nothing here runs in demo mode.
import type { Dispatch, Order, SiteCode } from '@tanaad/domain';
import { useStore, type AppState } from '../store';
import { uuid7 } from '../ids';
import { ApiError, api } from './http';
import { canRead } from './readAccess';
import { loadSession } from './auth';
import { isUuid } from './names';
import { siteCodeOf } from './sync';
import { dispatchSnapshotFromApi, salesErrorMessage } from './salesMap';
import { advancesOrder, deliveryBody, deliveryMessage, dispatchFromServer, orderStatusFor, overrideMessage, scanMessage, startBody, startMessage, weighMessage } from './dispatchMap';

type Rows = Record<string, unknown>[];
export interface Outcome<T = Dispatch> { ok: boolean; message: string; dispatch?: T }

/* ───────────── store ───────────── */

const orderIdOf = (ref: string) => useStore.getState().orders.find((o) => o.number === ref)?.id;

/** Put the server's dispatch into the store, move the local order forward, and mark loaded labels dispatched once the lorry is released. */
function apply(raw: Record<string, unknown>): Dispatch {
  const d = dispatchFromServer(raw, siteCodeOf, orderIdOf);
  useStore.setState((st): Partial<AppState> => {
    const released = d.gateStatus === 'pass' || d.gateStatus === 'overridden'; const ids = new Set(d.scannedLabelIds); const to = orderStatusFor(d);
    return {
      dispatches: [d, ...st.dispatches.filter((x) => x.id !== d.id)],
      orders: st.orders.map((o) => (o.id === d.orderId && advancesOrder(o.status, to) ? { ...o, status: to } : o)),
      labels: released ? st.labels.map((l) => (ids.has(l.id) && l.status === 'in_stock' ? { ...l, status: 'dispatched' } : l)) : st.labels,
    };
  });
  return d;
}

/** Replace the demo dispatches when the first real sign-in loads. */
export function resetDispatches() { useStore.setState({ dispatches: [] }); }

/** A site's dispatches from the server. A role that may not read them (403) is skipped; a network failure keeps what is held. */
export async function refreshDispatches(sites: SiteCode[]): Promise<void> {
  const s = loadSession(); if (!s) return;
  for (const site of sites) {
    if (!canRead(s.user.roles, site, 'dispatches')) continue;
    let res: { dispatches: Rows } | null = null;
    try { res = await api<{ dispatches: Rows }>(`/sites/${encodeURIComponent(site)}/dispatches`, { token: s.token }); }
    catch (e) { if (e instanceof ApiError && e.status === 403) continue; throw e; }
    const mapped = res.dispatches.map((r) => dispatchFromServer(r, siteCodeOf, orderIdOf));
    useStore.setState((st): Partial<AppState> => ({
      dispatches: [...st.dispatches.filter((x) => x.siteId !== site), ...mapped],
      orders: st.orders.map((o) => { const to = mapped.filter((d) => d.orderId === o.id).map(orderStatusFor).sort((a, b) => (advancesOrder(a, b) ? -1 : 1))[0]; return to && advancesOrder(o.status, to) ? { ...o, status: to } : o; }),
    }));
  }
}

/* ───────────── actions ───────────── */

async function post(site: SiteCode, path: string, body: unknown, words: (e: ApiError) => string): Promise<{ dispatch: Record<string, unknown> }> {
  const s = loadSession(); if (!s) throw new Error('Sign in again.');
  try { return await api<{ dispatch: Record<string, unknown> }>(`/sites/${encodeURIComponent(site)}/dispatches${path}`, { method: 'POST', token: s.token, body }); }
  catch (e) { throw e instanceof ApiError ? new Error(words(e)) : e; }
}
const fail = (e: unknown, fallback: string): Outcome => ({ ok: false, message: e instanceof Error ? e.message : fallback });

/** Open a load for an order. The order snapshot is the server's own (GET orders/{id}/dispatch-snapshot); the delivery note number is not known until the gate passes. */
export async function startDispatchOnline(order: Order, driver: string, vehicle: string): Promise<Outcome> {
  const s = loadSession(); if (!s) return { ok: false, message: 'Sign in again.' };
  if (!isUuid(order.id)) return { ok: false, message: 'This order has not reached the server yet. Wait for it to sync.' };
  try {
    const raw = await api<Record<string, unknown>>(`/sites/${encodeURIComponent(order.siteId)}/orders/${order.id}/dispatch-snapshot`, { token: s.token });
    const r = await post(order.siteId, '', startBody({ dispatchId: uuid7(), driver, vehicle, order: dispatchSnapshotFromApi(raw) }), startMessage);
    const dispatch = apply(r.dispatch);
    return { ok: true, message: 'Loading started', dispatch };
  } catch (e) { return fail(e instanceof ApiError ? new Error(salesErrorMessage(e, 'This order')) : e, 'Loading could not be started.'); }
}

/** Scan a label code onto the lorry. The message is the prototype's scan wording, from the server's answer. */
export async function scanOnline(dispatchId: string, code: string): Promise<Outcome> {
  const site = useStore.getState().dispatches.find((d) => d.id === dispatchId)?.siteId; if (!site) return { ok: false, message: 'This load is no longer on the list.' };
  try {
    const d = apply((await post(site, `/${dispatchId}/scan`, { code }, scanMessage)).dispatch);
    const label = useStore.getState().labels.find((l) => l.id === d.scannedLabelIds.at(-1));
    const product = useStore.getState().products.find((p) => p.id === label?.productId)?.internalName;
    return { ok: true, message: [d.scannedCodes?.at(-1) ?? code, product].filter(Boolean).join(' · '), dispatch: d };
  } catch (e) { return fail(e, 'The scan was refused.'); }
}

export async function unscanOnline(dispatchId: string, code: string): Promise<Outcome> {
  const site = useStore.getState().dispatches.find((d) => d.id === dispatchId)?.siteId; if (!site) return { ok: false, message: 'This load is no longer on the list.' };
  try { return { ok: true, message: `${code} removed`, dispatch: apply((await post(site, `/${dispatchId}/unscan`, { code }, scanMessage)).dispatch) }; }
  catch (e) { return fail(e, 'The bundle could not be removed.'); }
}

/** Record the gate scale reading. The pass or hold, the variance, the reasons and the delivery note number all come back from the server. */
export async function weighOnline(dispatchId: string, kg: number): Promise<Outcome> {
  const site = useStore.getState().dispatches.find((d) => d.id === dispatchId)?.siteId; if (!site) return { ok: false, message: 'This load is no longer on the list.' };
  try { return { ok: true, message: 'Weight recorded', dispatch: apply((await post(site, `/${dispatchId}/gate-weight`, { kg }, weighMessage)).dispatch) }; }
  catch (e) { return fail(e, 'The weight could not be recorded.'); }
}

/** Site Manager override of a held gate: a reason and the signed-in session, no PIN. The server refuses anyone else. */
export async function overrideOnline(dispatchId: string, reason: string): Promise<Outcome> {
  const site = useStore.getState().dispatches.find((d) => d.id === dispatchId)?.siteId; if (!site) return { ok: false, message: 'This load is no longer on the list.' };
  if (!reason.trim()) return { ok: false, message: 'Write the reason for the override first.' };
  try { return { ok: true, message: 'Override recorded', dispatch: apply((await post(site, `/${dispatchId}/override`, { reason: reason.trim() }, overrideMessage)).dispatch) }; }
  catch (e) { return fail(e, 'The override was refused.'); }
}

/** Proof of delivery. The signature and photo are uploaded first; the delivery carries their hashes. */
export async function deliverOnline(dispatchId: string, d: { receivedBy: string; bundlesReceived: number; signatureHash: string; photoHash?: string }): Promise<Outcome> {
  const site = useStore.getState().dispatches.find((x) => x.id === dispatchId)?.siteId; if (!site) return { ok: false, message: 'This load is no longer on the list.' };
  try { return { ok: true, message: 'Delivery confirmed', dispatch: apply((await post(site, `/${dispatchId}/delivery`, deliveryBody(d), deliveryMessage)).dispatch) }; }
  catch (e) { return fail(e, 'The delivery could not be confirmed.'); }
}
