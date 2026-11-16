// Generates contracts/fixtures/arithmetic.json from the TypeScript arithmetic.
// The TS implementation is the reference; Pest asserts the PHP port agrees.
// Run from the repo root: node tools/fixtures.mjs
import { writeFileSync } from 'node:fs';
import * as A from '../packages/domain/src/arithmetic.ts';

const cases = {
  expectedCargoKg: {
    'two lines': [[{ bundles: 100, stdBundleWeightKg: 38 }, { bundles: 50, stdBundleWeightKg: 25.2 }]],
    'fractional weights': [[{ bundles: 7, stdBundleWeightKg: 12.345 }, { bundles: 3, stdBundleWeightKg: 0.1 }]],
    'empty': [[]],
  },
  variancePct: {
    'under': [5060, 5010], 'over': [1000, 1025], 'zero expected': [0, 10], 'half cent rounding': [3000, 2999.955],
  },
  gateDecision: {
    'exact': [{ expectedKg: 5060, actualKg: 5060, tolerancePct: 2, scannedBundles: 150, orderedBundles: 150 }],
    'edge of tolerance': [{ expectedKg: 1000, actualKg: 980, tolerancePct: 2, scannedBundles: 10, orderedBundles: 10 }],
    'just over tolerance': [{ expectedKg: 1000, actualKg: 979, tolerancePct: 2, scannedBundles: 10, orderedBundles: 10 }],
    'weight ok but a bundle unscanned': [{ expectedKg: 1000, actualKg: 1000, tolerancePct: 2, scannedBundles: 9, orderedBundles: 10 }],
    'rwanda 200 short': [{ expectedKg: 5060, actualKg: 4420, tolerancePct: 2, scannedBundles: 140, orderedBundles: 150 }],
    'overweight hold': [{ expectedKg: 1000, actualKg: 1030, tolerancePct: 2, scannedBundles: 10, orderedBundles: 10 }],
  },
  massBalance: {
    'balanced': [{ kgIn: 2500, kgOut: 2250, wipDeltaKg: 120, wasteKg: 180, allowedLossPct: 2 }],
    'explainable': [{ kgIn: 2500, kgOut: 2300, wipDeltaKg: 0, wasteKg: 120, allowedLossPct: 2 }],
    'missing': [{ kgIn: 2500, kgOut: 2100, wipDeltaKg: 0, wasteKg: 50, allowedLossPct: 2 }],
    'nothing in': [{ kgIn: 0, kgOut: 0, wipDeltaKg: 0, wasteKg: 0, allowedLossPct: 2 }],
    'negative wip': [{ kgIn: 1000, kgOut: 1100, wipDeltaKg: -150, wasteKg: 30, allowedLossPct: 1.5 }],
  },
  settlementNet: {
    'weekly casual': [{ rateBasis: 'day', rate: 15000, unitsWorked: 6, advances: 20000 }],
    'monthly with overtime': [{ rateBasis: 'month', rate: 450000, unitsWorked: 0.5, overtimeHours: 6.5, overtimeRatePerHour: 2500, bonus: 10000 }],
    'piece rate': [{ rateBasis: 'piece', rate: 350, unitsWorked: 213 }],
    'advances exceed': [{ rateBasis: 'shift', rate: 12000, unitsWorked: 2, advances: 30000 }],
  },
  cashClose: {
    '18 August page': [{ opening: 1200000, sales: 4500000, receipts: 0, expenses: 2382000, wages: 1068000, counted: 2250000 }],
    'short by 10,000': [{ opening: 1200000, sales: 4500000, receipts: 0, expenses: 2382000, wages: 1068000, counted: 2240000 }],
    'over with receipts': [{ opening: 100000.5, sales: 0, receipts: 2500.25, expenses: 999.99, wages: 0, counted: 101700 }],
  },
  contractDaysRemaining: { 'three days': ['2026-10-13', '2026-10-10'], 'yesterday': ['2026-10-09', '2026-10-10'], 'across new year': ['2027-01-02', '2026-12-30'] },
  contractState: { 'open': ['2026-11-30', '2026-10-10'], 'expiring': ['2026-10-13', '2026-10-10'], 'expired': ['2026-10-09', '2026-10-10'], 'no end': [undefined, '2026-10-10'], 'custom warning': ['2026-10-20', '2026-10-10', 10] },
  yieldPct: { 'ninety one': [10000, 9100], 'zero input': [0, 5], 'rounding': [3, 2] },
  intakeNet: {
    'standard': [{ grossKg: 1200, tareKg: 40, deductionPct: 8, pricePerKg: 900 }],
    'tare exceeds gross': [{ grossKg: 30, tareKg: 40, deductionPct: 8, pricePerKg: 900 }],
    'thirds': [{ grossKg: 100, tareKg: 0, deductionPct: 33.333, pricePerKg: 1.5 }],
  },
  bundlesFromPieces: { 'loose one': [127, 6], 'exact': [120, 6], 'none': [0, 6] },
  landedCost: {
    'usd purchase with mixed costs': [{ qtyKg: 25500, unitPrice: 1.1, currency: 'USD', fx: 3700, costs: [{ amount: 2400, currency: 'USD' }, { amount: 3500000, currency: 'UGX' }] }],
    'ugx purchase, short delivery': [{ qtyKg: 1000, unitPrice: 4200, currency: 'UGX', fx: 3700, costs: [{ amount: 150000, currency: 'UGX' }], receivedKg: 980 }],
    'no extras': [{ qtyKg: 10, unitPrice: 2, currency: 'USD', fx: 3650.5, costs: [] }],
  },
  round2: { 'half up': [2.345], 'negative half': [-2.345], 'float noise': [1.005] },
  round3: { 'half up': [2.3455], 'negative half': [-2.3455] },
};

const out = {};
for (const [fn, byName] of Object.entries(cases)) {
  out[fn] = Object.entries(byName).map(([name, input]) => ({ name, input, expected: A[fn](...input) }));
}
writeFileSync(new URL('../contracts/fixtures/arithmetic.json', import.meta.url), JSON.stringify(out, null, 2) + '\n');
console.log(Object.entries(out).map(([k, v]) => `${k}:${v.length}`).join(' '));
