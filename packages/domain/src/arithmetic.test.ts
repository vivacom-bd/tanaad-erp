import { describe, it, expect } from 'vitest';
import * as A from './arithmetic';
import fixtures from '../../../contracts/fixtures/arithmetic.json';

// contracts/fixtures/arithmetic.json is the contract shared with the PHP port.
// Regenerate it with `node --experimental-strip-types tools/fixtures.mjs` after changing arithmetic.
type Case = { name: string; input: unknown[]; expected: unknown };
const table = fixtures as Record<string, Case[]>;

describe('arithmetic fixtures', () => {
  for (const [fn, cases] of Object.entries(table)) {
    const impl = (A as Record<string, unknown>)[fn];
    it(`${fn} exists`, () => expect(typeof impl).toBe('function'));
    for (const c of cases) {
      it(`${fn}: ${c.name}`, () => {
        expect((impl as (...a: unknown[]) => unknown)(...c.input)).toEqual(c.expected);
      });
    }
  }
});

describe('rounding is half-up away from zero', () => {
  it('ignores float noise', () => {
    expect(A.round2(1.005)).toBe(1.01);
    expect(A.round2(-2.345)).toBe(-2.35);
    expect(A.round3(2.3455)).toBe(2.346);
    expect(A.round3(-2.3455)).toBe(-2.346);
  });
});

describe('worked examples', () => {
  it('holds when 10 bundles are missing (the Rwanda case)', () => {
    const r = A.gateDecision({ expectedKg: 5060, actualKg: 4420, tolerancePct: 2, scannedBundles: 140, orderedBundles: 150 });
    expect(r.decision).toBe('hold');
    expect(r.reasons.length).toBe(2);
  });
  it('spreads freight and clearing over the kilos received', () => {
    const r = A.landedCost({ qtyKg: 25500, unitPrice: 1.1, currency: 'USD', fx: 3700, costs: [{ amount: 2400, currency: 'USD' }, { amount: 3_500_000, currency: 'UGX' }] });
    expect(r.goodsUgx).toBe(103_785_000);
    expect(r.extrasUgx).toBe(12_380_000);
    expect(r.perKgUgx).toBe(4555.49);
  });
});
