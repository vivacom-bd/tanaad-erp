// Real-mode sales, read side. Clients, orders, invoices and payments come from their snapshots; the server owns prices, totals, numbers,
// the credit check and every status change, so a pulled row replaces the local one with the same id. Writes are in salesActions.ts.
// Nothing here runs in demo mode.
import type { Client, Invoice, Order, Payment } from '@tanaad/domain';
import { useStore, type AppState } from '../store';
import { mergeById, type SiteOf } from './mapping';
import { outboxDb } from './outbox';
import { SO_PENDING } from './salesMap';
import { clientFromRow, clientStub, invoiceFromRow, orderFromRow, paymentFromRow } from './salesMap';

type Rows = Record<string, unknown>[];
export const SALES_PROJECTIONS = ['clients', 'orders', 'invoices', 'payments'] as const;
export type SalesProj = typeof SALES_PROJECTIONS[number];
export const isSalesProj = (p: string): p is SalesProj => (SALES_PROJECTIONS as readonly string[]).includes(p);

const wrap = <T extends { id: string }>(rows: Rows, f: (r: Rows[number]) => T, serverIds: Set<string>) =>
  rows.map((r) => { serverIds.add(String(r.id)); return { row: f(r), deleted: !!r.deletedAt }; });

/** Merge pulled rows. Server rows win. An order whose client this role cannot read (a storekeeper) gets a client of just the name the order carries. */
export function mergeSalesRows(proj: SalesProj, rows: Rows, siteOf: SiteOf, serverIds: Set<string>) {
  useStore.setState((st): Partial<AppState> => {
    if (proj === 'clients') return { clients: mergeById<Client>(st.clients, wrap(rows, clientFromRow, serverIds)) };
    if (proj === 'invoices') return { invoices: mergeById<Invoice>(st.invoices, wrap(rows, invoiceFromRow, serverIds)) };
    if (proj === 'payments') return { payments: mergeById<Payment>(st.payments, wrap(rows, paymentFromRow, serverIds)) };
    const orders = mergeById<Order>(st.orders, wrap(rows, (r) => orderFromRow(r, siteOf, st.fx), serverIds));
    const known = new Set(st.clients.map((c) => c.id)); const stubs: Client[] = [];
    for (const r of rows) {
      const cid = String(r.clientId ?? ''); if (!cid || known.has(cid) || r.deletedAt) continue;
      known.add(cid); stubs.push(clientStub(orderFromRow(r, siteOf, st.fx), r.channel));
    }
    return { orders, ...(stubs.length ? { clients: [...st.clients, ...stubs] } : {}) };
  });
}

/** Put one order (a GET or action answer) into the store; the invoice and payment that came with it go too. */
export function putOrder(raw: Record<string, unknown>, siteOf: SiteOf) {
  useStore.setState((st): Partial<AppState> => ({ orders: mergeById<Order>(st.orders, [{ row: orderFromRow(raw, siteOf, st.fx), deleted: false }]) }));
}
export function putInvoice(raw: Record<string, unknown>) {
  useStore.setState((st): Partial<AppState> => ({ invoices: mergeById<Invoice>(st.invoices, [{ row: invoiceFromRow(raw), deleted: false }]) }));
}
export function putPayment(raw: Record<string, unknown>) {
  useStore.setState((st): Partial<AppState> => ({ payments: mergeById<Payment>(st.payments, [{ row: paymentFromRow(raw), deleted: false }]) }));
}

/** Empty the sales rows (the demo seed's) when the first real sign-in loads. */
export const resetSales = () => useStore.setState({ clients: [], orders: [], invoices: [], payments: [] });

/** Drop optimistic orders the server refused for good (a rejected outbox item), so a refused order does not linger on the screen. */
export async function pruneRejectedOrders(): Promise<void> {
  const bad = (await outboxDb.rejected.toArray()).filter((i) => i.envelope.type === 'orders.created');
  if (!bad.length) return;
  const ids = new Set(bad.map((i) => String(i.envelope.payload.order_id)));
  useStore.setState((x) => ({ orders: x.orders.filter((o) => !(ids.has(o.id) && o.number.startsWith(SO_PENDING))) }));
}

