import { expect, test } from '../../support/helpers';
import { enter } from '../../support/nav';
import { TODAY, apiAs, cashDay, shot } from '../../support/cashgroup';

test.use({ actionTimeout: 20_000 });
test.describe.configure({ mode: 'serial', timeout: 180_000 });
let personId = ''; let personName = '';

test('day lock: an advance on a closed cash day is refused with a plain message; a future one too', async ({ request }) => {
  const sm = await apiAs(request, 'smmats'); const cashier = await apiAs(request, 'cashmats');
  const d = await cashDay(request, 'smmats', 'mats');
  if (d.day?.status !== 'closed') {
    const exp = d.totals.expectedClosing as number;
    expect((await sm.post(`/sites/mats/cash/days/${TODAY}/close`, { counted: Math.floor(exp / 500) * 500, denominations: { '500': Math.floor(exp / 500) } })).ok()).toBeTruthy();
  }
  const eng = (await sm.json('/sites/mats/snapshots/engagements')).data.find((e: { status: string }) => e.status === 'active');
  personId = eng.personId;
  const person = (await sm.json('/sites/mats/snapshots/people')).data.find((p: { id: string }) => p.id === personId);
  personName = person.fullName;
  const closed = await cashier.post('/sites/mats/advances', { personId, amount: 20000, reason: 'Lock test' });
  expect(closed.status()).toBe(409);
  expect((await closed.json()).detail).toMatch(/closed|locked/i);
  const future = await cashier.post('/sites/mats/advances', { personId, amount: 20000, reason: 'Future', givenOn: '2026-12-31' });
  expect(future.status()).toBe(422);
});

test('after the manager reopens the day, an advance shows in the cash book under wages and payouts', async ({ page, request, w }) => {
  const sm = await apiAs(request, 'smmats'); const cashier = await apiAs(request, 'cashmats');
  if (!personId) personId = (await sm.json('/sites/mats/snapshots/engagements')).data.find((e: { status: string }) => e.status === 'active').personId;
  if ((await cashDay(request, 'smmats', 'mats')).day?.status === 'closed') expect((await sm.post(`/sites/mats/cash/days/${TODAY}/reopen`, { reason: 'Advance after hours' })).ok()).toBeTruthy();
  const before = await cashDay(request, 'cashmats', 'mats');
  const r = await cashier.post('/sites/mats/advances', { personId, amount: 20000, reason: 'Medical' });
  expect(r.status(), await r.text()).toBe(201);
  const after = await cashDay(request, 'cashmats', 'mats');
  expect(after.totals.wages).toBe(before.totals.wages + 20000);
  expect(after.totals.expectedClosing).toBe(before.totals.expectedClosing - 20000);
  await enter(page, 'cashmats', '/s/mats/cash');
  await expect(page.getByText(/Wages and payouts/i).first()).toBeVisible();
  await expect(page.getByText(`UGX ${after.totals.expectedClosing.toLocaleString('en-UG')}`).first()).toBeVisible();
  await expect(page.getByText(/advance/i).first()).toBeVisible();
  await shot(page, 'cash-book-with-advance');
  w.assertClean();
});
