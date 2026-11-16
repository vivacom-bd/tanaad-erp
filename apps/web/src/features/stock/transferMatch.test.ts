import { describe, expect, it } from 'vitest';
import type { Product } from '@tanaad/domain';
import { matchReceivingProduct } from './transferMatch';

const P = (o: Partial<Product>): Product => ({ id: 'x', siteId: 'bags', internalName: 'Mat', kind: 'mat', bundlePieces: 10, stdBundleWeightKg: 1, weightTolerancePct: 1, weightStatus: 'measured', prices: [], unit: 'bundle', ...o } as Product);

describe('matchReceivingProduct', () => {
  const sent = P({ id: 's', siteId: 'mats', internalName: 'Floor mat' });
  it('finds the single product with the same name, kind and unit at the other site', () => {
    const m = matchReceivingProduct(sent, [P({ id: 'a', internalName: ' floor  MAT ' }), P({ id: 'b', internalName: 'Other' }), P({ id: 'c', internalName: 'Floor mat', unit: 'pack' })]);
    expect(m).toMatchObject({ status: 'one', product: { id: 'a' } });
  });
  it('is ambiguous when two fit and nothing tells them apart', () => {
    expect(matchReceivingProduct(sent, [P({ id: 'a', internalName: 'Floor mat', bundlePieces: 5 }), P({ id: 'b', internalName: 'Floor mat', bundlePieces: 6 })]).status).toBe('many');
  });
  it('narrows by pack size', () => {
    expect(matchReceivingProduct(sent, [P({ id: 'a', internalName: 'Floor mat', bundlePieces: 5 }), P({ id: 'b', internalName: 'Floor mat' })])).toMatchObject({ status: 'one', product: { id: 'b' } });
  });
  it('has none when unknown or the sent product is not loaded', () => {
    expect(matchReceivingProduct(sent, []).status).toBe('none');
    expect(matchReceivingProduct(undefined, [P({})]).status).toBe('none');
  });
});
