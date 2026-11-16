import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import type { Product, Purchase } from '@tanaad/domain';
import { useStore } from '../store';
import { stockByProduct } from '../selectors';
import { mergeStockRows } from './stock';
import {
  adjustPayload, countFromApi, countFromEvent, countPayload, isProvisionalPo, movementFromApi, onHandFromRow, provisionalPoRef, purchaseChangedPayload, purchaseFromRow,
  purchaseReceivedPayload, purchaseRecordedPayload, qtyFields, receiptPayload, receiveTransferPayload, reopenPayload, scrapPayload, sendTransferPayload, stockErrorMessage,
  stockLinesFromRows, transferFromApi, zoneMovePayload,
} from './stockMap';

const site = () => 'mats' as const;
const PID = '0190a1b2-0000-7000-8000-0000000000a1', ZA = '0190a1b2-0000-7000-8000-0000000000b1', ZB = '0190a1b2-0000-7000-8000-0000000000b2';
const prod = (o: Partial<Product> = {}): Product => ({ id: PID, siteId: 'mats', internalName: 'Mat A', kind: 'mat', bundlePieces: 10, stdBundleWeightKg: 12.5, weightTolerancePct: 5, weightStatus: 'measured', prices: [], ...o } as Product);
const q = (bundles: number) => ({ bundles, product: prod() });

describe('stock payloads', () => {
  it('sends bundles with their pieces and kg at the product standard', () => {
    expect(qtyFields(q(3))).toEqual({ bundles: 3, qty_units: 30, qty_kg: 37.5 });
    expect(zoneMovePayload({ productId: PID, fromZoneId: ZA, toZoneId: ZB, q: q(2), reason: ' ' })).toEqual({ kind: 'transfer', product_id: PID, from_zone_id: ZA, to_zone_id: ZB, bundles: 2, qty_units: 20, qty_kg: 25 });
    expect(receiptPayload({ receiptId: 'r1', productId: PID, zoneId: ZA, q: q(1) })).toMatchObject({ receipt_id: 'r1', product_id: PID, zone_id: ZA, bundles: 1 });
  });
  it('signs an adjustment and keeps its reason', () => {
    expect(adjustPayload({ productId: PID, zoneId: ZA, q: q(-2), reason: ' wet ' })).toMatchObject({ kind: 'adjust', zone_id: ZA, bundles: -2, qty_units: -20, qty_kg: -25, reason: 'wet' });
  });
  it('never sends the system figure with a count', () => {
    const p = countPayload({ countId: 'c1', zoneId: ZA, productId: PID, counted: 7, day: '2026-11-10', reason: 'two torn' });
    expect(p).toEqual({ count_id: 'c1', zone_id: ZA, product_id: PID, counted_bundles: 7, day: '2026-11-10', reason: 'two torn' });
    expect(countPayload({ countId: 'c1', zoneId: ZA, productId: PID, counted: 7, day: '2026-11-10' })).not.toHaveProperty('reason');
    expect(reopenPayload({ zoneId: ZA, productId: PID, day: '2026-11-10', reason: ' recount ' }).reason).toBe('recount');
  });
  it('builds scrap and inter-site transfers', () => {
    expect(scrapPayload(' torn ')).toEqual({ reason: 'torn' });
    expect(sendTransferPayload({ transferId: 't1', toSite: 'bags', productId: PID, fromZoneId: ZA, q: q(1), note: 'urgent' })).toMatchObject({ transfer_id: 't1', to_site_id: 'bags', from_zone_id: ZA, note: 'urgent' });
    expect(receiveTransferPayload({ toZoneId: ZB, productId: '0190a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2b' })).toEqual({ to_zone_id: ZB, product_id: '0190a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2b' });
    expect(() => receiveTransferPayload({ toZoneId: ZB, productId: '' })).toThrow();
    expect(() => receiveTransferPayload({ toZoneId: ZB, productId: 'p_demo' })).toThrow();
    expect(receiveTransferPayload({ toZoneId: ZB, productId: '0190a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2b', bundles: 2 })).toEqual({ to_zone_id: ZB, product_id: '0190a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2b', bundles: 2 });
  });
});

describe('plain wording for refusals', () => {
  it('explains a locked count, a state conflict, a missing connection and a validation message', () => {
    expect(stockErrorMessage({ status: 409, code: 'day_locked', detail: 'x' })).toMatch(/already counted/);
    expect(stockErrorMessage({ status: 409, code: 'state_conflict', detail: 'Not enough stock in that zone: 2 bundles on hand.' })).toBe('Not enough stock in that zone: 2 bundles on hand.');
    expect(stockErrorMessage({ status: 0, code: 'network', detail: 'x' })).toMatch(/No connection/);
    expect(stockErrorMessage({ status: 422, code: 'invalid_payload', detail: 'reason: required when the count differs' })).toBe('reason: required when the count differs');
    expect(stockErrorMessage({ status: 403, code: 'forbidden', detail: '' })).toMatch(/role/);
  });
});

describe('server rows', () => {
  it('turns on-hand rows into per-product lines and skips empty rows and unknown products', () => {
    const rows = [
      onHandFromRow({ id: 'a', siteId: 'mats', productId: PID, zoneId: ZA, bundles: '5', pieces: '50.000', kg: '62.500' }, site),
      onHandFromRow({ id: 'b', siteId: 'mats', productId: PID, zoneId: ZB, bundles: 2, pieces: 20, kg: 25 }, site),
      onHandFromRow({ id: 'c', siteId: 'mats', productId: PID, zoneId: null, bundles: 0, pieces: 0, kg: 0 }, site),
      onHandFromRow({ id: 'd', siteId: 'mats', productId: 'unknown', zoneId: ZA, bundles: 9, pieces: 9, kg: 9 }, site),
    ];
    const lines = stockLinesFromRows(rows, 'mats', [prod()]);
    expect(lines).toHaveLength(1);
    expect(lines[0]).toMatchObject({ bundles: 7, pieces: 70, kg: 87.5 });
    expect(lines[0].zones.get(ZA)).toBe(5); expect(lines[0].zones.get(ZB)).toBe(2);
  });
  it('maps ledger rows with bundles as units, and sends inter-site transfers out with the destination site', () => {
    const m = movementFromApi({ id: 'm1', siteId: 'mats', kind: 'transfer_out', toSiteId: 'bags', productId: PID, bundles: 3, qtyKg: '37.5', by: 'u1', at: '2026-11-10 08:00:00.000000' }, site);
    expect(m.payload).toMatchObject({ kind: 'transfer', qtyUnits: 3, qtyKg: 37.5, toSiteId: 'mats' });
    expect(m.occurredAt).toBe('2026-11-10T08:00:00.000Z');
    expect(movementFromApi({ id: 'm2', kind: 'label_move', labelCode: 'MAT-1', bundles: 1, qtyKg: 0, at: '2026-11-10T08:00:00Z' }, site).payload).toMatchObject({ kind: 'transfer', reason: 'Label MAT-1' });
    expect(movementFromApi({ id: 'm3', kind: 'scrap', reason: 'torn', bundles: 1, qtyKg: 0, at: '2026-11-10T08:00:00Z' }, site).payload.kind).toBe('scrap');
  });
  it('uses the system figure the server read, not the screen', () => {
    const c = countFromEvent({ count_id: 'c1', zone_id: ZA, product_id: PID, day: '2026-11-10', counted_bundles: 7, system_bundles: 9, variance: -2 }, 'mats', '2026-11-10T09:00:00.000Z', 'u1');
    expect(c).toMatchObject({ id: 'c1', countedBundles: 7, systemBundles: 9 });
    expect(countFromApi({ id: 'c2', siteId: 'mats', zoneId: ZA, productId: PID, day: '2026-11-10', countedBundles: 4, systemBundles: 4, by: 'u1', at: '2026-11-10T09:00:00+00:00' }, site).day).toBe('2026-11-10');
    expect(transferFromApi({ id: 't1', fromSiteId: 's1', toSiteId: 's2', productId: PID, status: 'sent', bundles: 2, qtyKg: '25', sentAt: '2026-11-10T09:00:00+00:00' })).toMatchObject({ bundles: 2, qtyKg: 25, receivedBundles: undefined });
  });
});

const purchase = (o: Partial<Purchase> = {}): Purchase => ({
  id: '0190a1b2-0000-7000-8000-0000000000c1', siteId: 'mats', reference: provisionalPoRef('0190a1b2-0000-7000-8000-0000000000c1'), supplier: ' Acme ', originCountry: '', material: 'PP granules', qtyKg: 1000, unitPrice: 1.2, currency: 'USD',
  fxReference: 3700, costs: [{ label: ' Freight ', amount: 200, currency: 'USD' }, { label: 'blank', amount: 0, currency: 'UGX' }], status: 'ordered', orderedOn: '2026-11-10', ...o,
});

describe('purchase payloads', () => {
  it('leaves the reference and the exchange reference to the server', () => {
    const p = purchaseRecordedPayload(purchase());
    expect(p).not.toHaveProperty('reference'); expect(p).not.toHaveProperty('fx_reference'); expect(p).not.toHaveProperty('origin_country');
    expect(p).toMatchObject({ purchase_id: '0190a1b2-0000-7000-8000-0000000000c1', supplier: 'Acme', material: 'PP granules', qty_kg: 1000, unit_price: 1.2, currency: 'USD', costs: [{ label: 'Freight', amount: 200, currency: 'USD' }] });
  });
  it('carries the stage on a change and the kilos on a receipt', () => {
    expect(purchaseChangedPayload(purchase({ status: 'in_transit' })).status).toBe('in_transit');
    expect(purchaseReceivedPayload('p1', 990, '2026-11-12')).toEqual({ purchase_id: 'p1', received_kg: 990, received_on: '2026-11-12' });
  });
  it('shows a provisional PO number until the server answers', () => {
    expect(isProvisionalPo(purchase().reference)).toBe(true); expect(isProvisionalPo('PO-0007')).toBe(false);
  });
  it('reads a purchase row with the server landed cost and variance', () => {
    const r = purchaseFromRow({ id: 'p1', siteId: 'mats', reference: 'PO-0007', supplier: 'Acme', material: 'PP', qtyKg: '1000.000', unitPrice: '1.2000', currency: 'USD', fxReference: '3700.000000', costs: '[{"label":"Freight","amount":200,"currency":"USD"}]', status: 'received', orderedOn: '2026-11-01 00:00:00', receivedOn: '2026-11-05', receivedKg: '990.000', variance: 0, goodsUgx: '4440000.00', extrasUgx: '740000.00', totalUgx: '5180000.00', perKgUgx: '5232.32', upliftPct: '16.67' }, site);
    expect(r).toMatchObject({ reference: 'PO-0007', qtyKg: 1000, fxReference: 3700, orderedOn: '2026-11-01', receivedKg: 990, variance: false });
    expect(r.costs).toEqual([{ label: 'Freight', amount: 200, currency: 'USD' }]);
    expect(r.landed).toEqual({ goodsUgx: 4440000, extrasUgx: 740000, totalUgx: 5180000, perKgUgx: 5232.32, upliftPct: 16.67 });
    expect(purchaseFromRow({ id: 'p2', siteId: 'mats', qtyKg: 5, unitPrice: 1, currency: 'UGX', costs: [], status: 'ordered', orderedOn: '2026-11-01', totalUgx: null }, site).landed).toBeUndefined();
  });
});

describe('store merge', () => {
  beforeEach(() => { useStore.setState({ purchases: [], serverStock: undefined, labels: [], products: [prod()] }); });
  it('replaces the provisional purchase with the server row of the same id', () => {
    const p = purchase(); useStore.setState({ purchases: [p] });
    mergeStockRows('purchases', [{ id: p.id, siteId: 'mats', reference: 'PO-0008', supplier: 'Acme', material: 'PP granules', qtyKg: 1000, unitPrice: 1.2, currency: 'USD', fxReference: 3710, costs: [], status: 'ordered', orderedOn: '2026-11-10' }], site, new Set());
    const got = useStore.getState().purchases;
    expect(got).toHaveLength(1); expect(got[0].reference).toBe('PO-0008'); expect(got[0].fxReference).toBe(3710);
  });
  it('merges on-hand rows by id and the stock selector reads them, adding labels not yet synced', () => {
    mergeStockRows('stock_on_hand', [{ id: 'k1', siteId: 'mats', productId: PID, zoneId: ZA, bundles: 4, pieces: 40, kg: 50 }], site, new Set());
    mergeStockRows('stock_on_hand', [{ id: 'k1', siteId: 'mats', productId: PID, zoneId: ZA, bundles: 6, pieces: 60, kg: 75 }], site, new Set());
    expect(useStore.getState().serverStock).toHaveLength(1);
    let lines = stockByProduct(useStore.getState(), 'mats');
    expect(lines[0]).toMatchObject({ bundles: 6, kg: 75 });
    useStore.setState({ labels: [{ id: 'l1', code: 'PENDING-ABC123', siteId: 'mats', kind: 'bundle', productId: PID, stageCode: 'store', day: '2026-11-10', qtyUnits: 10, qtyKg: 12.5, status: 'in_stock', zoneId: ZA, parentIds: [] }] });
    lines = stockByProduct(useStore.getState(), 'mats');
    expect(lines[0]).toMatchObject({ bundles: 7, kg: 87.5 }); expect(lines[0].zones.get(ZA)).toBe(7);
  });
});
