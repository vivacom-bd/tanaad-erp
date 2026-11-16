import { describe, expect, it } from 'vitest';
import { voucherLine } from './voucher';

describe('stock voucher summary', () => {
  it('names the label for a scrap even though no product is chosen (this used to crash the screen)', () => {
    expect(voucherLine({ ready: true, missing: [], scrapLabelCode: ' MAT-BUN-000010 ', qty: '0', unit: 'bundles', fromZone: '—' })).toBe('Write off label MAT-BUN-000010.');
  });
  it('lists what is still needed before the voucher is ready', () => {
    expect(voucherLine({ ready: false, missing: ['product', 'quantity'], qty: '0', unit: 'bundles', fromZone: '—' })).toBe('Still needed: product, quantity.');
  });
  it('reads as a zone move once ready', () => {
    expect(voucherLine({ ready: true, missing: [], qty: '3', unit: 'bundles', productName: 'Domona', fromZone: 'Raw store', destination: 'Store B' })).toBe('3 bundles of Domona, Raw store to Store B.');
  });
});
