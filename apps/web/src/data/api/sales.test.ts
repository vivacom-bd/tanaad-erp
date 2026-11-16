import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import type { Order } from '@tanaad/domain';
import { useStore } from '../store';
import { clientBalance } from '../selectors';
import { mergeSalesRows } from './sales';
import {
  approveBody, clientFromRow, clientPayload, confirmCashBody, dispatchSnapshotFromApi, holdBody, invoiceFromRow, isProvisionalOrder, orderCreatedPayload, orderFromRow,
  paymentBody, paymentFromRow, provisionalOrderRef, salesErrorMessage,
} from './salesMap';

const site = () => 'mats' as const;
const OID = '0190a1b2-0000-7000-8000-0000000000a1', CID = '0190a1b2-0000-7000-8000-0000000000c1', PID = '0190a1b2-0000-7000-8000-0000000000b1', IID = '0190a1b2-0000-7000-8000-0000000000d1';
const orderRow = (o: Record<string, unknown> = {}) => ({
  id: OID, number: 'SO-2401', siteId: 'mats', clientId: CID, clientName: 'Owino Traders', channel: 'local', status: 'awaiting_cash', currency: 'UGX', fxReference: '3710.000000',
  total: '4800000.00', overLimit: 0, lines: [{ product_id: PID, bundles: 12, unit_price: 400000, amount: 4800000 }], at: '2026-11-10 08:00:00.000000', ...o,
});

describe('payloads', () => {
  it('sends an order as client, products and bundles only; prices and totals stay with the server', () => {
    const o = { id: OID, clientId: CID, notes: ' rush ', lines: [{ productId: PID, bundles: 12, unitPrice: 1 }] } as Pick<Order, 'id' | 'clientId' | 'lines' | 'notes'>;
    const p = orderCreatedPayload(o);
    expect(p).toEqual({ order_id: OID, client_id: CID, lines: [{ product_id: PID, bundles: 12 }], notes: 'rush' });
    expect(JSON.stringify(p)).not.toMatch(/price|total|number|status/);
    expect(orderCreatedPayload({ ...o, notes: undefined })).not.toHaveProperty('notes');
  });
  it('sends a client without credit terms when it pays cash', () => {
    const c = { id: CID, name: ' Owino ', country: 'Uganda', channel: 'local' as const, currencyPref: 'UGX' as const, creditLimit: 5000, creditDays: 30, whatsapp: '+256700', contact: 'Ann' };
    expect(clientPayload(c)).toMatchObject({ client_id: CID, name: 'Owino', active: true });
    expect(clientPayload(c)).not.toHaveProperty('credit_limit');
    expect(clientPayload({ ...c, channel: 'export' })).toMatchObject({ credit_limit: 5000, credit_days: 30 });
  });
  it('sends credit terms only when the user changed them', () => {
    const c = { id: CID, name: 'Owino', country: 'Uganda', channel: 'export' as const, currencyPref: 'USD' as const, creditLimit: 5000, creditDays: 30, whatsapp: '+256700', contact: 'Ann' };
    const same = clientPayload({ ...c, name: 'Owino Ltd' }, c);
    for (const k of ['channel', 'currency_pref', 'credit_limit', 'credit_days']) expect(same).not.toHaveProperty(k);
    expect(same).toMatchObject({ name: 'Owino Ltd' });
    expect(clientPayload({ ...c, creditLimit: 9000 }, c)).toMatchObject({ credit_limit: 9000 });
    expect(clientPayload({ ...c, creditLimit: 9000 }, c)).not.toHaveProperty('credit_days');
    const fresh = clientPayload({ ...c, channel: 'local', creditLimit: 0, creditDays: 0, currencyPref: 'UGX' });
    expect(fresh).not.toHaveProperty('credit_limit'); expect(fresh).toMatchObject({ channel: 'local', currency_pref: 'UGX' });
  });
  it('builds the action bodies', () => {
    expect(confirmCashBody(4800000)).toEqual({ amount: 4800000 });
    expect(approveBody(' ok ')).toEqual({ reason: 'ok' }); expect(approveBody(' ')).toEqual({});
    expect(holdBody(' no credit ')).toEqual({ reason: 'no credit' });
    expect(paymentBody({ amount: 10, currency: 'USD', method: ' Bank ', reference: ' R1 ' })).toEqual({ amount: 10, currency: 'USD', method: 'Bank', reference: 'R1' });
  });
  it('marks an order made offline until the server numbers it', () => {
    const ref = provisionalOrderRef(OID); expect(isProvisionalOrder(ref)).toBe(true); expect(isProvisionalOrder('SO-2401')).toBe(false); expect(ref).not.toMatch(/^SO-\d/);
  });
});

describe('plain wording', () => {
  it('explains a closed cash day, an overpayment, a second invoice, a credit hold, offline and a forbidden role', () => {
    expect(salesErrorMessage({ status: 409, code: 'day_locked', detail: 'x' })).toMatch(/cash day/);
    expect(salesErrorMessage({ status: 409, code: 'state_conflict', detail: 'Payment exceeds the open balance 100 of invoice INV-1.', details: { rule: 'overpay' } })).toBe('Payment exceeds the open balance 100 of invoice INV-1.');
    expect(salesErrorMessage({ status: 409, code: 'state_conflict', detail: 'Order SO-1 is already invoiced.' })).toBe('Order SO-1 is already invoiced.');
    expect(salesErrorMessage({ status: 422, code: 'invalid_payload', detail: 'amount: must equal the order total 4800000' })).toMatch(/order total/);
    expect(salesErrorMessage({ status: 0, code: 'network', detail: 'x' })).toMatch(/No connection/);
    expect(salesErrorMessage({ status: 403, code: 'forbidden', detail: '' })).toMatch(/role/);
  });
});

describe('server rows', () => {
  it('maps an order with the server price, number, status and over-limit flag', () => {
    const o = orderFromRow(orderRow({ status: 'awaiting_approval', overLimit: 1, holdReason: null }), site, 3700);
    expect(o).toMatchObject({ id: OID, number: 'SO-2401', siteId: 'mats', status: 'awaiting_approval', currency: 'UGX', fxReference: 3710, overLimit: true, clientName: 'Owino Traders' });
    expect(o.lines).toEqual([{ productId: PID, bundles: 12, unitPrice: 400000 }]);
    expect(o.createdAt).toBe('2026-11-10T08:00:00.000Z');
    expect(orderFromRow(orderRow({ fxReference: null }), site, 3700).fxReference).toBe(3700);
  });
  it('maps clients, invoices and payments', () => {
    expect(clientFromRow({ id: CID, name: 'A', country: 'Kenya', channel: 'export', whatsapp: '1', currencyPref: 'USD', creditLimit: '5000.00', creditDays: 30, contact: 'x', active: 1 })).toMatchObject({ channel: 'export', creditLimit: 5000, creditDays: 30, active: true });
    expect(invoiceFromRow({ id: IID, orderId: OID, number: 'INV-2401', total: 100, currency: 'USD', paid: 40, balance: 60, issuedAt: '2026-11-10T09:00:00Z', issuedDay: '2026-11-10' })).toMatchObject({ paid: 40, balance: 60, number: 'INV-2401' });
    expect(paymentFromRow({ id: 'p1', clientId: CID, invoiceId: IID, amount: '40.00', currency: 'USD', method: 'Bank', reference: 'R', at: '2026-11-10T10:00:00Z' })).toMatchObject({ amount: 40, invoiceId: IID });
  });
  it('reads the dispatch snapshot the server builds from the order', () => {
    expect(dispatchSnapshotFromApi({ order_ref: 'SO-2401', client_name: 'Owino', site: 'mats', lines: [{ product_id: PID, bundles: 12 }] }))
      .toEqual({ orderRef: 'SO-2401', clientName: 'Owino', site: 'mats', lines: [{ productId: PID, bundles: 12 }] });
  });
});

describe('merging into the store', () => {
  beforeEach(() => { useStore.setState({ clients: [], orders: [], invoices: [], payments: [] }); });
  it('replaces a provisional order with the server row of the same id', () => {
    useStore.setState({ orders: [{ id: OID, number: provisionalOrderRef(OID), siteId: 'mats', clientId: CID, status: 'awaiting_cash', currency: 'UGX', fxReference: 1, lines: [{ productId: PID, bundles: 12, unitPrice: 1 }], createdAt: 'x' }] });
    mergeSalesRows('orders', [orderRow()], site, new Set());
    const got = useStore.getState().orders; expect(got).toHaveLength(1);
    expect(got[0]).toMatchObject({ number: 'SO-2401', lines: [{ unitPrice: 400000 }] });
  });
  it('gives an order whose client the role cannot read a client of the name the order carries, and lets the real client replace it', () => {
    mergeSalesRows('orders', [orderRow({ channel: 'export' })], site, new Set());
    expect(useStore.getState().clients).toEqual([expect.objectContaining({ id: CID, name: 'Owino Traders', channel: 'export' })]);
    mergeSalesRows('clients', [{ id: CID, name: 'Owino Traders', country: 'Kenya', channel: 'export', currencyPref: 'UGX', creditLimit: 9, creditDays: 14, contact: 'Ann', whatsapp: '1', active: 1 }], site, new Set());
    expect(useStore.getState().clients).toHaveLength(1); expect(useStore.getState().clients[0].country).toBe('Kenya');
  });
  it('drops a deleted row', () => {
    mergeSalesRows('orders', [orderRow()], site, new Set()); mergeSalesRows('orders', [orderRow({ deletedAt: '2026-11-10' })], site, new Set());
    expect(useStore.getState().orders).toHaveLength(0);
  });
  it('takes the client balance from what the server says each invoice has received, including cash paid up front', () => {
    mergeSalesRows('clients', [{ id: CID, name: 'A', country: 'K', channel: 'export', currencyPref: 'USD', creditLimit: 1000, creditDays: 30, contact: '', whatsapp: '', active: 1 }], site, new Set());
    mergeSalesRows('orders', [orderRow({ status: 'invoiced', currency: 'USD' })], site, new Set());
    mergeSalesRows('invoices', [{ id: IID, orderId: OID, number: 'INV-1', total: 100, currency: 'USD', paid: 40, balance: 60, issuedAt: '2026-11-10T09:00:00Z', issuedDay: '2026-11-10' }], site, new Set());
    const b = clientBalance(useStore.getState(), CID);
    expect(b).toMatchObject({ invoiced: 100, paid: 40, balance: 60 }); expect(b.openInvoices).toHaveLength(1);
  });
});
