// Real-mode sales writes. A new order goes through the outbox (a provisional SO reference offline, the server's real number and prices after
// sync). Cash confirmation, approval, invoicing and payments are online-only: the server checks the books and the screens show its words.
import type { Client, Invoice, Order, Payment, SiteCode } from '@tanaad/domain';
import { useStore } from '../store';
import { uuid7, nowIso } from '../ids';
import { ApiError, api } from './http';
import { loadSession } from './auth';
import { enqueue } from './outbox';
import { refreshCounts, siteCodeOf, syncNow } from './sync';
import { putInvoice, putOrder, putPayment } from './sales';
import { mergeById } from './mapping';
import { approveBody, clientFromRow, clientPayload, confirmCashBody, holdBody, invoiceFromRow, orderCreatedPayload, paymentBody, provisionalOrderRef, salesErrorMessage } from './salesMap';

type Row = Record<string, unknown>;

/** A refused sales action: `message` is plain wording, `code` the server's problem code (day_locked, state_conflict, network...). */
export class SalesError extends Error {
  code: string; status: number;
  constructor(message: string, code: string, status: number) { super(message); this.name = 'SalesError'; this.code = code; this.status = status; }
}
const words = (e: unknown, what?: string) => (e instanceof ApiError ? new SalesError(salesErrorMessage(e, what), e.code, e.status) : e);

async function call<T>(site: SiteCode, path: string, method: 'GET' | 'POST' | 'PUT', body?: unknown, what?: string): Promise<T> {
  const s = loadSession(); if (!s) throw new Error('Sign in again to continue.');
  try { return await api<T>(`/sites/${encodeURIComponent(site)}${path}`, { method, token: s.token, ...(body === undefined ? {} : { body }) }); }
  catch (e) { throw words(e, what); }
}
export const reasonOf = (e: unknown, fallback: string) => (e instanceof Error && e.message ? e.message : fallback);

/* ───────────── orders ───────────── */

/**
 * Create an order. It shows at once with a provisional number and the prices the screen estimated; the outbox sends only the client, the
 * products and the bundles, and the server's number, prices, total, credit check and status replace these on the next pull.
 */
export async function createOrderReal(o: Pick<Order, 'siteId' | 'clientId' | 'currency' | 'lines' | 'notes'>): Promise<Order> {
  const s = loadSession(); const st = useStore.getState();
  const client = st.clients.find((c) => c.id === o.clientId);
  const id = uuid7();
  const rec: Order = { ...o, id, number: provisionalOrderRef(id), createdAt: nowIso(), fxReference: st.fx, clientName: client?.name, status: client?.channel === 'local' ? 'awaiting_cash' : 'awaiting_approval' };
  useStore.setState((x) => ({ orders: [rec, ...x.orders] }));
  if (!s) return rec;
  await enqueue(o.siteId, { id: uuid7(), type: 'orders.created', version: 1, occurred_at: nowIso(), payload: orderCreatedPayload(rec), provisional_ref: rec.number, ...(s.device?.id ? { device_id: s.device.id } : {}) });
  await refreshCounts();
  void syncNow();
  return rec;
}

export async function refreshOrder(site: SiteCode, id: string): Promise<void> {
  const r = await call<{ order: Row }>(site, `/orders/${encodeURIComponent(id)}`, 'GET').catch(() => null);
  if (r) putOrder(r.order, siteCodeOf);
}

/** Cashier confirms the cash for a local order. The server books the cash entry and unlocks the order; the amount must equal its total. */
export async function confirmCashOnline(order: Order, amount: number): Promise<Order> {
  const r = await call<{ order: Row }>(order.siteId, `/orders/${order.id}/confirm-cash`, 'POST', confirmCashBody(amount), 'The cash');
  putOrder(r.order, siteCodeOf); void syncNow(); // the cash entry the server made is pulled with the cash ledger
  return useStore.getState().orders.find((o) => o.id === order.id)!;
}

/** CEO credit desk. The order moves to what the server says (a local order approved still awaits its cash). */
export async function decideOrderOnline(orderId: string, decision: 'approved' | 'held', reason?: string): Promise<void> {
  const st = useStore.getState(); const o = st.orders.find((x) => x.id === orderId); if (!o) return;
  try {
    if (decision === 'held' && !reason?.trim()) throw new SalesError('Write the reason for holding this order.', 'invalid_payload', 422);
    const r = await call<{ order: Row }>(o.siteId, `/orders/${orderId}/${decision === 'approved' ? 'approve' : 'hold'}`, 'POST', decision === 'approved' ? approveBody(reason) : holdBody(reason ?? ''), 'The decision');
    putOrder(r.order, siteCodeOf); void syncNow();
  } catch (e) {
    useStore.getState().toast({ kind: 'error', title: `${o.number} was not ${decision === 'approved' ? 'approved' : 'held'}`, detail: reasonOf(e, 'The decision could not be recorded.') });
    await refreshOrder(o.siteId, orderId);
  }
}

/* ───────────── invoices and payments ───────────── */

/** Issue the invoice for a released order. The server numbers it, takes the total from the order and settles it when cash was paid up front. */
export async function issueInvoiceOnline(order: Order): Promise<Invoice> {
  const r = await call<{ invoice: Row; order: Row }>(order.siteId, `/orders/${order.id}/invoice`, 'POST', {}, 'The invoice');
  putInvoice(r.invoice); putOrder(r.order, siteCodeOf);
  return invoiceFromRow(r.invoice);
}

/** Record a payment against an invoice. The server refuses more than the invoice still owes and a cash payment on a closed cash day. */
export async function recordPaymentOnline(site: SiteCode, invoice: Invoice, p: { amount: number; method: string; reference: string }): Promise<Payment> {
  const r = await call<{ payment: Row; invoice: Row }>(site, `/invoices/${invoice.id}/payments`, 'POST', paymentBody({ ...p, currency: invoice.currency }), 'The payment');
  putPayment(r.payment); putInvoice(r.invoice);
  const orderId = invoice.orderId; await refreshOrder(site, orderId); void syncNow();
  return useStore.getState().payments.find((x) => x.id === String(r.payment.id))!;
}

/* ───────────── clients ───────────── */

/** Save a client. Online it is checked at once (the market must be master data); offline it waits in the outbox and syncs later. */
export async function saveClientReal(site: SiteCode, c: Omit<Client, 'id'> & { id?: string }): Promise<Client> {
  const existing = c.id ? useStore.getState().clients.find((x) => x.id === c.id) : undefined;
  const rec: Client = { ...existing, ...c, id: c.id ?? uuid7(), active: c.active ?? existing?.active ?? true } as Client;
  const body = clientPayload(rec, existing);
  const put = (x: Client) => useStore.setState((st) => ({ clients: mergeById<Client>(st.clients, [{ row: x, deleted: false }]) }));
  try {
    const r = existing ? await call<{ client: Row }>(site, `/clients/${rec.id}`, 'PUT', body, 'The client') : await call<{ client: Row }>(site, '/clients', 'POST', body, 'The client');
    const saved = clientFromRow(r.client); put(saved); return saved;
  } catch (e) {
    if (!(e instanceof SalesError) || e.code !== 'network') throw e;
    const s = loadSession(); put(rec);
    await enqueue(site, { id: uuid7(), type: 'orders.client_saved', version: 1, occurred_at: nowIso(), payload: body, ...(s?.device?.id ? { device_id: s.device.id } : {}) });
    await refreshCounts(); void syncNow();
    return rec;
  }
}

/* ───────────── statement ───────────── */

/** Read a client's statement from the server (the group view for roles that see every site) and fold its invoices and payments into the store. */
export async function loadStatement(site: SiteCode, clientId: string): Promise<void> {
  const r = await call<{ invoices: Row[]; payments: Row[] }>(site, `/clients/${clientId}/statement`, 'GET').catch(() => null);
  if (!r) return;
  for (const i of r.invoices) putInvoice(i);
  for (const p of r.payments) putPayment(p);
}
