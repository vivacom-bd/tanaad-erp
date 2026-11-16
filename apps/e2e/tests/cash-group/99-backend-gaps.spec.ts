import { expect, test } from '../../support/helpers';
import { apiAs } from '../../support/cashgroup';

// Runs last: it writes a page on a future day, which would show up in the cash book day picker.
test('a cash entry dated in the future is rejected', async ({ request }) => {
  const a = await apiAs(request, 'cashmats');
  const r = await a.post('/sites/mats/cash/entries', { direction: 'out', category: 'other', payee: 'Future', day: '2026-11-20', amount: 100 });
  expect(r.status()).toBeGreaterThanOrEqual(400);
});

test('a supervisor cannot read the cash ledger through the snapshots', async ({ request }) => {
  const a = await apiAs(request, 'supmats');
  for (const p of ['cash_days', 'cash_entries']) expect((await a.get(`/sites/mats/snapshots/${p}`)).status(), p).toBe(403);
});
