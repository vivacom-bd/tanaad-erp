// Pure translation for Orders: clients, orders, invoices and payments, out as payloads and in from snapshot rows, plus plain wording
// for refusals. The server sets unit prices (Config prices), totals, SO-/INV- numbers, the credit check and order status; nothing here
// computes them. A price sent with an order is never read, so the screens show the server's after the order syncs. No store import.
import type { Client, Currency, Invoice, Order, OrderStatus, Payment } from '@tanaad/domain';
import type { SiteOf } from './mapping';

type Row = Record<string, unknown>;
const num = (v: unknown) => (v === null || v === undefined ? 0 : Number(v));
const str = (v: unknown) => (v === null || v === undefined || v === '' ? undefined : String(v));
const iso = (v: unknown) => { const s = str(v); return s ? (s.includes('T') ? s : s.replace(' ', 'T').replace(/(\.\d{3})\d*$/, '$1') + 'Z') : ''; };
const dayOf = (v: unknown) => String(v ?? '').slice(0, 10);
const opt = <T extends Record<string, unknown>>(o: T) => Object.fromEntries(Object.entries(o).filter(([, v]) => v !== undefined && v !== '')) as T;

/* ───────────── provisional order numbers ───────────── */

export const SO_PENDING = 'SO pending';
/** Shown for an order made offline until the server allocates the real SO- number. A label, never a number. */
export const provisionalOrderRef = (id: string) => `${SO_PENDING}-${id.replace(/-/g, '').slice(-4).toUpperCase()}`;
export const isProvisionalOrder = (ref: string) => ref.startsWith(SO_PENDING);

/* ───────────── outgoing ───────────── */

/** orders.created / POST orders. Lines carry the product and the bundles only: the server prices them. */
export function orderCreatedPayload(o: Pick<Order, 'id' | 'clientId' | 'lines' | 'notes'>): Row {
  return opt({ order_id: o.id, client_id: o.clientId, lines: o.lines.map((l) => ({ product_id: l.productId, bundles: l.bundles })), notes: o.notes?.trim() || undefined });
}

/** orders.client_saved / POST or PUT clients. A known id is a change, a new one a registration. */
export function clientPayload(c: Client, existing?: Client): Row {
  const limit = c.channel === 'export' ? c.creditLimit : 0, days = c.channel === 'export' ? c.creditDays : 0;
  const base = { client_id: c.id, name: c.name.trim(), country: c.country.trim(), whatsapp: c.whatsapp.trim(), contact: c.contact.trim(), active: c.active ?? true };
  // The server lets only the CEO or group admin set or change terms, and keeps the current value of an omitted one:
  // so an existing client's terms go only when they changed, and a new client's credit amounts only when above zero.
  if (existing) {
    return opt({ ...base,
      channel: c.channel !== existing.channel ? c.channel : undefined, currency_pref: c.currencyPref !== existing.currencyPref ? c.currencyPref : undefined,
      credit_limit: limit !== existing.creditLimit ? limit : undefined, credit_days: days !== existing.creditDays ? days : undefined });
  }
  return opt({ ...base, channel: c.channel, currency_pref: c.currencyPref, credit_limit: limit > 0 ? limit : undefined, credit_days: days > 0 ? days : undefined });
}

export const confirmCashBody = (amount: number): Row => ({ amount });
export const approveBody = (reason?: string): Row => opt({ reason: reason?.trim() || undefined });
export const holdBody = (reason: string): Row => ({ reason: reason.trim() });
export const paymentBody = (p: { amount: number; currency: Currency; method: string; reference: string }): Row =>
  ({ amount: p.amount, currency: p.currency, method: p.method.trim(), reference: p.reference.trim() });

/** What the order will probably total: the local list prices times the bundles. An estimate shown before the server answers, never sent. */
export const estimateTotal = (lines: { bundles: number; unitPrice: number }[]) => lines.reduce((t, l) => t + l.bundles * l.unitPrice, 0);

/* ───────────── incoming ───────────── */

export const clientFromRow = (r: Row): Client => ({
  id: String(r.id), name: String(r.name ?? ''), country: String(r.country ?? ''), channel: r.channel === 'export' ? 'export' : 'local',
  whatsapp: String(r.whatsapp ?? ''), currencyPref: (r.currencyPref as Currency) ?? 'UGX', creditLimit: num(r.creditLimit), creditDays: num(r.creditDays),
  contact: String(r.contact ?? ''), active: r.active === undefined ? true : !!Number(r.active),
});

const linesOf = (v: unknown): Order['lines'] => {
  const raw = typeof v === 'string' ? (() => { try { return JSON.parse(v) as unknown; } catch { return []; } })() : v;
  return (Array.isArray(raw) ? (raw as Row[]) : []).map((l) => ({ productId: String(l.productId ?? l.product_id ?? ''), bundles: num(l.bundles), unitPrice: num(l.unitPrice ?? l.unit_price) }));
};

/** The order as the server holds it (snapshot row or GET answer). `fx` is the fallback when the server had no exchange reference. */
export const orderFromRow = (r: Row, site: SiteOf, fx = 0): Order => ({
  id: String(r.id), number: String(r.number ?? ''), siteId: site(r.siteId), clientId: String(r.clientId), status: (r.status as OrderStatus) ?? 'awaiting_approval',
  currency: (r.currency as Currency) ?? 'UGX', fxReference: r.fxReference == null ? fx : num(r.fxReference), lines: linesOf(r.lines),
  createdAt: iso(r.at ?? r.createdAt), approvedBy: str(r.approvedBy), approvedAt: str(r.approvedAt) ? iso(r.approvedAt) : undefined,
  cashConfirmedAt: str(r.cashConfirmedAt) ? iso(r.cashConfirmedAt) : undefined, notes: str(r.notes) ?? str(r.holdReason),
  clientName: str(r.clientName), overLimit: !!Number(r.overLimit), holdReason: str(r.holdReason), invoiceId: str(r.invoiceId),
});

export const invoiceFromRow = (r: Row): Invoice => ({
  id: String(r.id), orderId: String(r.orderId), number: String(r.number ?? ''), total: num(r.total), currency: (r.currency as Currency) ?? 'UGX',
  issuedAt: iso(r.issuedAt), efrisFdn: str(r.efrisFdn), efrisVerificationCode: str(r.efrisVerificationCode), orderNumber: str(r.orderNumber),
  paid: num(r.paid ?? r.paidAmount), balance: r.balance == null ? Math.max(0, num(r.total) - num(r.paid ?? r.paidAmount)) : num(r.balance), issuedDay: str(r.issuedDay) ? dayOf(r.issuedDay) : undefined,
});

export const paymentFromRow = (r: Row): Payment => ({
  id: String(r.id), clientId: String(r.clientId), invoiceId: str(r.invoiceId), amount: num(r.amount), currency: (r.currency as Currency) ?? 'UGX',
  method: String(r.method ?? ''), reference: String(r.reference ?? ''), at: iso(r.at),
});

/** A client the user may not read (storekeepers see orders but not clients): enough to show the order, from what the order itself carries. */
export const clientStub = (o: Order, channel: unknown): Client => ({
  id: o.clientId, name: o.clientName ?? 'Client', country: '', channel: channel === 'export' ? 'export' : 'local', whatsapp: '', currencyPref: o.currency, creditLimit: 0, creditDays: 0, contact: '',
});

/** GET orders/{id}/dispatch-snapshot (snake_case) in the shape the dispatch start takes. */
export function dispatchSnapshotFromApi(r: Row): { orderRef: string; clientName: string; site: string; lines: { productId: string; bundles: number }[] } {
  const lines = (Array.isArray(r.lines) ? (r.lines as Row[]) : []).map((l) => ({ productId: String(l.product_id ?? l.productId ?? ''), bundles: num(l.bundles) }));
  return { orderRef: String(r.order_ref ?? r.orderRef ?? ''), clientName: String(r.client_name ?? r.clientName ?? ''), site: String(r.site ?? ''), lines };
}

/* ───────────── plain wording ───────────── */

/** Wording for a refused sales action. The server's detail is plain already; the known rules get a sentence of their own. */
export function salesErrorMessage(e: { status?: number; code?: string; detail?: string; details?: Record<string, unknown> } | null | undefined, what = 'This'): string {
  if (!e) return `${what} could not be saved.`;
  if (e.code === 'network') return 'No connection. This needs the server; try again when online.';
  if (e.code === 'day_locked') return 'The cash day for this is already closed. The Site Manager can reopen it with a reason.';
  if (e.status === 403 || e.code === 'forbidden') return e.detail || 'Your role may not do this here.';
  if (e.details?.rule === 'overpay') return e.detail || 'That is more than the invoice still owes.';
  if (e.code === 'state_conflict') return e.detail || `${what} no longer matches the books. Refresh and check again.`;
  if (e.code === 'duplicate_fact') return e.detail || `${what} is already recorded.`;
  if (e.code === 'not_found') return e.detail || 'The server does not have this record yet. Wait for it to sync.';
  if (e.code === 'stale_config') return e.detail || 'A setting this needs is missing on the server.';
  if (e.code === 'invalid_payload') return e.detail || `${what} was not accepted: check the figures.`;
  return e.detail || `${what} could not be saved.`;
}
