import { describe, expect, it } from 'vitest';
import type { Order } from '@tanaad/domain';
import { DN_PENDING, advancesOrder, deliveryBody, deliveryMessage, dispatchFromServer, gateResult, orderSnapshot, orderStatusFor, overrideMessage, scanMessage, startBody, startMessage, weighMessage } from './dispatchMap';

const site = () => 'mats' as const;
const P1 = '0190a1b2-0000-7000-8000-0000000000c1', P2 = '0190a1b2-0000-7000-8000-0000000000c2';
const order = { number: 'SO-2310', lines: [{ productId: 'p_a', bundles: 10, unitPrice: 1 }, { productId: 'p_b', bundles: 5, unitPrice: 1 }, { productId: 'p_a', bundles: 2, unitPrice: 1 }] } as Pick<Order, 'number' | 'lines'>;
const resolve = (id: string) => ({ p_a: P1, p_b: P2 })[id];

describe('order snapshot for dispatch.started', () => {
  it('builds the contract shape with server product ids, one line per product', () => {
    expect(orderSnapshot(order, ' Owino Traders ', 'mats', resolve)).toEqual({ ok: true, order: { orderRef: 'SO-2310', clientName: 'Owino Traders', site: 'mats', lines: [{ productId: P1, bundles: 12 }, { productId: P2, bundles: 5 }] } });
  });
  it('refuses a product the server does not know instead of inventing one', () => {
    const r = orderSnapshot(order, 'C', 'mats', (id) => (id === 'p_a' ? P1 : undefined)); expect(r.ok).toBe(false);
    expect(!r.ok && r.message).toMatch(/not in the server catalogue/);
  });
  it('needs a client name and whole bundles', () => {
    expect(orderSnapshot(order, '  ', 'mats', resolve).ok).toBe(false);
    expect(orderSnapshot({ number: 'SO-1', lines: [{ productId: 'p_a', bundles: 1.5, unitPrice: 1 }] }, 'C', 'mats', resolve).ok).toBe(false);
  });
  it('sends the start body with the dispatch id', () => {
    expect(startBody({ dispatchId: 'd1', driver: ' Simon ', vehicle: ' UBK 442X ', order: { orderRef: 'SO-1', clientName: 'C', site: 'mats', lines: [] } })).toMatchObject({ dispatchId: 'd1', driver: 'Simon', vehicle: 'UBK 442X' });
  });
  it('sends proof of delivery with media hashes only, photo optional', () => {
    const h = 'a'.repeat(64);
    expect(deliveryBody({ receivedBy: ' Mama ', bundlesReceived: 10, signatureHash: h })).toEqual({ receivedBy: 'Mama', bundlesReceived: 10, signatureHash: h });
    expect(deliveryBody({ receivedBy: 'M', bundlesReceived: 10, signatureHash: h, photoHash: 'b'.repeat(64) })).toHaveProperty('photoHash');
  });
});

describe('the server dispatch in the store shape', () => {
  const raw = { id: 'd1', siteId: 'uuid-site', orderRef: 'SO-2310', deliveryNoteNo: null, scannedLabelIds: ['l1', 'l2'], scannedCodes: ['MAT-BUN-1', 'MAT-BUN-2'], expectedKg: 1200.5, gateKg: null, variancePct: null, gateStatus: 'pending', gateReasons: [], driver: 'Simon', vehicle: 'UBK 442X', createdAt: '2026-10-10T08:00:00+00:00', gatePassedAt: null, pod: null };
  it('keeps the delivery note as a label, never a number, until the gate allocates it', () => {
    const d = dispatchFromServer(raw, site, (ref) => (ref === 'SO-2310' ? 'ord1' : undefined));
    expect(d).toMatchObject({ id: 'd1', orderId: 'ord1', orderRef: 'SO-2310', siteId: 'mats', deliveryNoteNo: DN_PENDING, scannedCodes: ['MAT-BUN-1', 'MAT-BUN-2'], expectedKg: 1200.5, gateStatus: 'pending' });
    expect(d.gateKg).toBeUndefined(); expect(d.variancePct).toBeUndefined();
  });
  it('carries the server gate decision, variance, reasons, note number and proof of delivery as sent', () => {
    const d = dispatchFromServer({ ...raw, deliveryNoteNo: 'DN-00007', gateKg: 1190, variancePct: -0.88, gateStatus: 'hold', gateReasons: ['Short load: 2 of 3 bundles scanned'], pod: { receiverName: 'Mama', bundlesCounted: 9, bundlesExpected: 10, deliveredAt: '2026-10-10T10:00:00+00:00', hasSignature: true, signatureHash: 'a'.repeat(64), photoHash: null } }, site, () => undefined);
    expect(d).toMatchObject({ orderId: '', deliveryNoteNo: 'DN-00007', gateKg: 1190, variancePct: -0.88, gateStatus: 'hold', gateReasons: ['Short load: 2 of 3 bundles scanned'] });
    expect(d.pod).toMatchObject({ receiverName: 'Mama', bundlesCounted: 9, bundlesExpected: 10, hasSignature: true });
    expect(gateResult(d)).toEqual({ decision: 'hold', variancePct: -0.88, reasons: ['Short load: 2 of 3 bundles scanned'], note: 'DN-00007' });
    expect(gateResult(dispatchFromServer(raw, site, () => undefined)).note).toBeUndefined();
  });
  it('moves the local order forward only', () => {
    expect(orderStatusFor({ gateStatus: 'pending', pod: undefined })).toBe('picking');
    expect(orderStatusFor({ gateStatus: 'overridden', pod: undefined })).toBe('dispatched');
    expect(orderStatusFor({ gateStatus: 'pass', pod: { receiverName: '', bundlesCounted: 1, bundlesExpected: 1, deliveredAt: '', hasSignature: true } })).toBe('delivered');
    expect(advancesOrder('picking', 'dispatched')).toBe(true); expect(advancesOrder('delivered', 'picking')).toBe(false); expect(advancesOrder('invoiced', 'dispatched')).toBe(false);
  });
});

describe('refusals in plain words', () => {
  const e = (status: number, detail: string, extra: Record<string, unknown> = {}) => ({ status, code: 'x', detail, ...extra });
  it('scan errors use the prototype wording', () => {
    expect(scanMessage(e(409, 'Already scanned: MAT-BUN-1.'))).toBe('Already scanned: MAT-BUN-1');
    expect(scanMessage(e(409, 'MAT-BUN-2 is dispatched, not in stock.'))).toBe('MAT-BUN-2 is dispatched, not in stock');
    expect(scanMessage(e(409, 'Order has only 10 bundles of this product.'))).toBe('Order has only 10 bundles of this product');
    expect(scanMessage(e(422, 'The payload failed validation.', { details: { errors: { code: 'unknown label XYZ at this site' } } }))).toBe('Unknown label XYZ');
    expect(scanMessage(e(422, 'x', { details: { errors: { code: 'MAT-BUN-3: its product is not on this order' } } }))).toBe('MAT-BUN-3 is not on this order');
    expect(scanMessage({ status: 0, code: 'network', detail: '' })).toMatch(/No connection/);
  });
  it('shows a refused override plainly, whoever was refused', () => {
    expect(overrideMessage(e(403, 'Only the Site Manager of this site may override the gate.'))).toBe('Only the Site Manager of this site may override the gate.');
    expect(overrideMessage(e(403, 'The person who recorded the gate weight may not override it.'))).toMatch(/may not override/);
    expect(overrideMessage(e(422, 'x', { details: { errors: { reason: 'is required' } } }))).toBe('is required');
    expect(overrideMessage(e(409, 'Dispatch is pass; only a held gate can be overridden.'))).toMatch(/only a held gate/);
  });
  it('covers start, weigh and delivery', () => {
    expect(startMessage(e(409, 'An open dispatch already exists for SO-1.'))).toMatch(/already exists/);
    expect(weighMessage(e(403, ''))).toMatch(/Storekeeper, Gate or Site Manager/);
    expect(deliveryMessage(e(409, 'Delivery is already confirmed for this dispatch.'))).toMatch(/already confirmed/);
    expect(deliveryMessage({ status: 0, code: 'network', detail: '' })).toMatch(/No connection/);
  });
});
