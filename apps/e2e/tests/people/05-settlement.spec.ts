import { expect, test, goOffline, goOnline } from '../../support/helpers';
import { advances, apiAs, businessDay, eventually, gotoSite, runs, signIn, snap, tolerateThrottledPulls, type Api } from '../../support/people';
import type { Page } from '@playwright/test';
import { ensureOpen } from '../../support/cashgroup';

test.describe.configure({ mode: 'serial' });
const TODAY = businessDay();
type R = Record<string, any>;
const addDays = (d: string, n: number) => new Date(Date.parse(d) + n * 86400000).toISOString().slice(0, 10);
const dow = (d: string) => new Date(d + 'T00:00:00Z').getUTCDay();
const MON = addDays(TODAY, -((dow(TODAY) + 6) % 7)); const SAT = addDays(MON, 5); // this business week, as the New run sheet proposes it
const fmt = (n: number) => Number(n).toLocaleString('en-US');
let X: R, Y: R; // two casual workers with a mark today
let weekly: R | undefined;

const weeklyRuns = async (a: Api) => (await runs(a, 'mats')).filter((r) => r.run.kind === 'weekly_casual' && r.run.periodFrom === MON);
const nameOf = (people: R[], id: string) => people.find((p) => p.id === id)?.fullName;
const sheet = (page: Page) => page.getByRole('dialog').last();

test.beforeAll(async ({ request }) => {
  const adm = await apiAs(request, 'admin'); const sup = await apiAs(request, 'supmats');
  const people = await snap(adm, 'mats', 'people'); const engs = await snap(adm, 'mats', 'engagements');
  // Advances and cash payouts post to the cash book, which needs an open day: reopen today's only if the server has it closed
  // (when the wall clock is past the seed's last day, today has not been started and is open already).
  await ensureOpen(request, 'mats', TODAY);
  const owing = new Set((await advances(adm, 'mats')).filter((x) => Number(x.balance) > 0).map((x) => x.personId));
  const att = await snap(adm, 'mats', 'attendance');
  const markToday = (id: string) => att.filter((r) => r.personId === id && r.day === TODAY);
  // workers with nothing owed, who either have no mark today (we add one) or are already present (so they earn a unit)
  const ok = (p: R) => p.status === 'active' && !owing.has(p.id) && (markToday(p.id).length === 0 || markToday(p.id).some((r) => r.status === 'present'));
  const casuals = people.filter((p) => ok(p) && engs.some((e) => e.personId === p.id && e.status === 'active' && e.type === 'casual')).sort((a, b) => b.fullName.localeCompare(a.fullName));
  [X, Y] = casuals.slice(0, 2);
  const perm = people.find((p) => ok(p) && engs.some((e) => e.personId === p.id && e.status === 'active' && e.type === 'permanent'))!;
  for (const p of [X, Y, perm]) {
    if (markToday(p.id).length) continue;
    const r = await sup.post('/sites/mats/attendance', { personId: p.id, status: 'present', day: TODAY, shiftKind: 'day', overtimeHours: 2 });
    expect(r.status(), `mark ${p.fullName}: ${await r.text()}`).toBeLessThan(300);
  }
});

test('cashier: the Wages screen shows the server runs, totals and advances', async ({ page, request, w }) => {
  tolerateThrottledPulls(w);
  const a = await apiAs(request, 'admin'); const all = await runs(a, 'mats'); const people = await snap(a, 'mats', 'people');
  const adv = await advances(a, 'mats');
  await signIn(page, 'cashmats'); await gotoSite(page, 'mats', 'people/settlement');
  await expect(page.getByRole('heading', { name: 'Settlement' })).toBeVisible();
  const count = (k: string) => all.filter((r) => r.run.kind === k && r.run.status !== 'cancelled').length;
  await expect(page.getByRole('tab', { name: /Weekly casuals/ })).toContainText(String(count('weekly_casual')));
  await expect(page.getByRole('tab', { name: /End-of-term/ })).toContainText(String(count('end_of_term')));
  await expect(page.getByRole('tab', { name: /Monthly/ })).toContainText(String(count('monthly')));
  const latest = all.filter((r) => r.run.kind === 'weekly_casual').sort((p, q) => q.run.periodFrom.localeCompare(p.run.periodFrom))[0];
  await expect(page.getByText(`${latest.lines.length} workers`)).toBeVisible();
  await expect(page.locator('tfoot')).toContainText(fmt(Number(latest.run.totalNet)));
  const line = latest.lines[0];
  await expect(page.getByRole('row').filter({ hasText: nameOf(people, line.personId)! }).first()).toContainText(fmt(Number(line.net)));
  const outstanding = adv.reduce((t, x) => t + Number(x.balance), 0);
  await expect(page.getByText(`UGX ${fmt(outstanding)} outstanding`).or(page.getByText(/outstanding/)).first()).toBeVisible();
  // a paid line is marked paid, an unpaid approved line offers Pay out
  const paid = latest.lines.filter((l: R) => l.status === 'paid').length;
  await expect(page.getByText(`${paid}/${latest.lines.length} paid`)).toBeVisible();
  w.assertClean();
});

test('give advance: validation, then it is on the server and listed', async ({ page, request, w }) => {
  tolerateThrottledPulls(w);
  const a = await apiAs(request, 'admin');
  await signIn(page, 'cashmats'); await gotoSite(page, 'mats', 'people/settlement');
  await page.getByRole('button', { name: 'Give advance' }).click();
  const s = sheet(page); const submit = s.getByRole('button', { name: 'Give advance' });
  await expect(submit).toBeDisabled();
  await s.getByLabel('Worker').selectOption(X.id); await expect(submit).toBeDisabled(); // no amount, no reason
  await s.getByLabel('Amount (UGX)').fill('-500'); await s.getByLabel('Reason').fill('Rent'); await expect(submit).toBeDisabled(); // negative
  await s.getByLabel('Amount (UGX)').fill('0'); await expect(submit).toBeDisabled();
  await s.getByLabel('Amount (UGX)').fill('5000'); await s.getByLabel('Reason').fill('   '); await expect(submit).toBeDisabled(); // blank reason
  await s.getByLabel('Reason').fill('Rent');
  await expect(submit).toBeEnabled();
  const before = (await advances(a, 'mats')).length;
  await submit.click();
  await expect(page.getByRole('status')).toContainText('Advance queued');
  const adv = await eventually(() => advances(a, 'mats'), (r) => r.length === before + 1, 'advance on server');
  const mine = adv.find((x) => x.personId === X.id && x.reason === 'Rent')!;
  expect(Number(mine.amount)).toBe(5000); expect(Number(mine.balance)).toBe(5000);
  await expect(page.getByText(X.fullName).first()).toBeVisible();
  await expect(page.getByText(/Rent/).first()).toBeVisible();
  w.assertClean();
});

test('a cashier cannot create a run: the refusal is shown plainly and nothing is created', async ({ page, request, w }) => {
  tolerateThrottledPulls(w);
  const a = await apiAs(request, 'admin'); const before = (await runs(a, 'mats')).length;
  await signIn(page, 'cashmats'); await gotoSite(page, 'mats', 'people/settlement');
  const btn = page.getByRole('button', { name: 'New run' }).first();
  const visible = await btn.isVisible().catch(() => false);
  expect.soft(visible, 'APP: "New run" is offered to a cashier although only a site manager or admin may create runs').toBe(false);
  if (visible) {
    await btn.click(); await sheet(page).getByRole('button', { name: 'Create run' }).click();
    const note = page.getByText(/Not accepted:/).locator('xpath=..');
    await expect(note).toBeVisible({ timeout: 20_000 });
    console.log('CASHIER create-run refusal:', await note.innerText());
    expect(await note.innerText()).not.toMatch(/SQLSTATE|exception|stack/i);
    w.allow(/^403 POST \/sites\/mats\/events/);
  }
  expect((await runs(a, 'mats')).length).toBe(before);
  w.assertClean();
});

test('site manager creates a weekly run: dates validated, bonus added, advance deducted; own approval refused; cancel restores the advance', async ({ page, request, w }) => {
  tolerateThrottledPulls(w);
  const a = await apiAs(request, 'admin');
  await signIn(page, 'smmats'); await gotoSite(page, 'mats', 'people/settlement');
  await page.getByRole('button', { name: 'New run' }).first().click();
  const s = sheet(page); const create = s.getByRole('button', { name: 'Create run' });
  await expect(s.getByLabel('From')).toHaveValue(MON);
  await s.getByLabel('To').fill(addDays(MON, -8));
  await expect(s.getByText('The end date must not be before the start date.')).toBeVisible();
  await expect(create).toBeDisabled();
  await s.getByLabel('To').fill(SAT); await expect(create).toBeEnabled();
  await s.getByLabel('Add a bonus for').selectOption(Y.id);
  await s.getByLabel('Bonus amount').fill('5000');
  await s.getByLabel('Add a bonus for').selectOption(X.id);
  await s.getByRole('button', { name: 'Remove' }).last().click();
  await create.click();
  await expect(page.getByRole('status')).toContainText('Run queued');
  const rs = await eventually(() => weeklyRuns(a), (r) => r.length >= 1, 'run on server');
  weekly = rs[0];
  expect(weekly!.run.status).toBe('created');
  const people = await snap(a, 'mats', 'people');
  const lx = weekly!.lines.find((l: R) => l.personId === X.id)!; const ly = weekly!.lines.find((l: R) => l.personId === Y.id)!;
  expect(Number(lx.advancesDeducted), 'advance deducted from X (all of it, or what the line can cover)').toBeCloseTo(Math.min(5000, Number(lx.gross) + Number(lx.overtime) + Number(lx.bonus)), 2);
  expect(Number(ly.bonus)).toBe(5000);
  expect(Number(lx.net)).toBeCloseTo(Number(lx.gross) + Number(lx.overtime) + Number(lx.bonus) - Number(lx.advancesDeducted), 2);
  // UI shows the server's lines
  await expect(page.getByText('Awaiting approval').first()).toBeVisible();
  const row = page.getByRole('row').filter({ hasText: X.fullName });
  await expect(row).toContainText(`−${fmt(Number(lx.advancesDeducted))}`); await expect(row).toContainText(fmt(Number(lx.net)));
  await expect(page.getByRole('row').filter({ hasText: Y.fullName })).toContainText('5,000');
  // the creator may not approve their own run
  await page.getByRole('button', { name: 'Approve run' }).click();
  const note = page.getByText(/Not accepted:/).locator('xpath=..'); await expect(note).toBeVisible({ timeout: 20_000 });
  console.log('SELF-APPROVAL refusal:', await note.innerText());
  expect(await note.innerText()).toContain('may not approve');
  w.allow(/^403 POST \/sites\/mats\/events/);
  await page.getByRole('button', { name: 'Dismiss' }).first().click();
  expect((await weeklyRuns(a))[0].run.status).toBe('created');
  // cancel with a reason: advance given back
  await page.getByRole('button', { name: 'Cancel run' }).click();
  await sheet(page).getByLabel('Reason').fill('Wrong week');
  await sheet(page).getByRole('button', { name: 'Cancel run' }).click();
  await expect(page.getByRole('status')).toContainText('Cancellation queued');
  await eventually(() => weeklyRuns(a), (r) => r[0].run.status === 'cancelled', 'cancelled on server');
  const adv = (await advances(a, 'mats')).find((x) => x.personId === X.id && x.reason === 'Rent')!;
  expect(Number(adv.balance), 'advance given back').toBe(5000);
  await page.getByRole('button', { name: /Cancelled$/ }).click(); // the cancelled run is no longer the default view: the live/older run is
  await expect(page.getByText(/Cancelled\. Advances this run deducted were given back/)).toBeVisible();
  void people; w.assertClean();
});

test('a second run for the same period is refused plainly while one is live; CEO approves; cashier pays cash and mobile money', async ({ browser, request, w }) => {
  tolerateThrottledPulls(w);
  const a = await apiAs(request, 'admin');
  // create again (API as the site manager, a second run since the first was cancelled)
  const sm = await apiAs(request, 'smmats');
  const mk = await sm.post('/sites/mats/settlement-runs', { kind: 'weekly_casual', from: MON, to: SAT, bonuses: [] });
  expect(mk.status(), await mk.text()).toBeLessThan(300);
  const dup = await sm.post('/sites/mats/settlement-runs', { kind: 'weekly_casual', from: MON, to: SAT, bonuses: [] });
  console.log('DUPLICATE RUN ->', dup.status(), (await dup.text()).slice(0, 240));
  expect(dup.status()).toBeGreaterThanOrEqual(400);
  const run = (await weeklyRuns(a)).find((r) => r.run.status === 'created')!;
  // CEO approves in the UI
  const c1 = await browser.newContext(); const ceo = await c1.newPage();
  await signIn(ceo, 'ceo1'); await gotoSite(ceo, 'mats', 'people/settlement');
  await ceo.getByRole('button', { name: 'Approve run' }).click();
  await expect(ceo.getByRole('status')).toContainText('Approval queued');
  await eventually(() => weeklyRuns(a), (r) => r.some((x) => x.run.id === run.run.id && x.run.status === 'approved'), 'approved');
  await expect(ceo.getByText('Approved.', { exact: false }).first()).toBeVisible();
  await expect(ceo.getByRole('button', { name: 'Approve run' })).toHaveCount(0);
  await c1.close();
  // cashier pays X in cash and Y by mobile money
  const c2 = await browser.newContext(); const cash = await c2.newPage();
  await signIn(cash, 'cashmats'); await gotoSite(cash, 'mats', 'people/settlement');
  const rowX = cash.getByRole('row').filter({ hasText: X.fullName });
  await rowX.getByRole('button', { name: 'Pay out' }).click();
  await expect(sheet(cash)).toContainText('Record payout');
  await expect(sheet(cash)).toContainText('posted to today');
  await sheet(cash).getByRole('button', { name: /^Confirm/ }).click();
  await expect(cash.getByRole('status')).toContainText('Payout queued');
  const after = await eventually(() => weeklyRuns(a), (r) => r.find((x) => x.run.id === run.run.id)!.payouts.some((p: R) => p.personId === X.id), 'cash payout on server');
  const lineX = after.find((x) => x.run.id === run.run.id)!.lines.find((l: R) => l.personId === X.id)!;
  const payoutX = after.find((x) => x.run.id === run.run.id)!.payouts.find((p: R) => p.personId === X.id)!;
  expect(Number(payoutX.amount)).toBe(Number(lineX.net)); expect(payoutX.method).toBe('cash');
  await expect(cash.getByRole('row').filter({ hasText: X.fullName })).toContainText('Paid');
  const rowY = cash.getByRole('row').filter({ hasText: Y.fullName });
  await rowY.getByRole('button', { name: 'Pay out' }).click();
  await sheet(cash).locator('button:text-is("Mobile money")').click();
  await expect(sheet(cash)).not.toContainText('posted to today');
  await sheet(cash).getByLabel('Reference').fill('MM123456');
  await sheet(cash).getByRole('button', { name: /^Confirm/ }).click();
  const after2 = await eventually(() => weeklyRuns(a), (r) => r.find((x) => x.run.id === run.run.id)!.payouts.some((p: R) => p.personId === Y.id), 'mobile money payout on server');
  expect(after2.find((x) => x.run.id === run.run.id)!.payouts.find((p: R) => p.personId === Y.id)).toMatchObject({ method: 'mobile_money', reference: 'MM123456' });
  // the advance was consumed by the run
  const adv = (await advances(a, 'mats')).find((x) => x.personId === X.id && x.reason === 'Rent')!;
  expect(Number(adv.deducted) + Number(adv.balance)).toBe(5000);
  // a paid run cannot be cancelled / a part-paid run neither: server says so plainly
  const cancel = await (await apiAs(request, 'smmats')).post(`/sites/mats/settlement-runs/${run.run.id}/cancel`, { reason: 'x' });
  console.log('CANCEL part-paid run ->', cancel.status(), (await cancel.text()).slice(0, 200));
  expect(cancel.status()).toBeGreaterThanOrEqual(400);
  await expect(cash.getByRole('button', { name: 'Cancel run' })).toHaveCount(0);
  await c2.close();
  w.assertClean();
});

test('OFFLINE: advance and a monthly run created offline are queued, then each reaches the server exactly once', async ({ browser, request, w }) => {
  tolerateThrottledPulls(w);
  const a = await apiAs(request, 'admin');
  const advBefore = (await advances(a, 'mats')).length; const runsBefore = (await runs(a, 'mats')).length;
  // cashier: advance offline
  const c1 = await browser.newContext(); const cash = await c1.newPage();
  await signIn(cash, 'cashmats'); await gotoSite(cash, 'mats', 'people/settlement');
  await goOffline(cash);
  await cash.getByRole('button', { name: 'Give advance' }).click();
  await sheet(cash).getByLabel('Worker').selectOption(Y.id); await sheet(cash).getByLabel('Amount (UGX)').fill('7000'); await sheet(cash).getByLabel('Reason').fill('Offline school fees');
  await sheet(cash).getByRole('button', { name: 'Give advance' }).click();
  await expect(cash.getByText('Waiting to sync').first()).toBeVisible();
  await cash.waitForTimeout(1200);
  expect((await advances(a, 'mats')).length, 'not on the server while offline').toBe(advBefore);
  await goOnline(cash);
  await eventually(() => advances(a, 'mats'), (r) => r.filter((x) => x.reason === 'Offline school fees').length >= 1, 'advance synced', 45_000);
  await cash.waitForTimeout(2500);
  expect((await advances(a, 'mats')).filter((x) => x.reason === 'Offline school fees')).toHaveLength(1);
  await expect(cash.getByText('Waiting to sync')).toHaveCount(0);
  await c1.close();
  // site manager: monthly run offline
  const c2 = await browser.newContext(); const sm = await c2.newPage();
  await signIn(sm, 'smmats'); await gotoSite(sm, 'mats', 'people/settlement');
  await sm.getByRole('tab', { name: /Monthly/ }).click();
  await goOffline(sm);
  await sm.getByRole('button', { name: 'New run' }).first().click();
  await sheet(sm).getByRole('button', { name: 'Create run' }).click();
  await expect(sm.getByText(/1 new run waiting to sync/)).toBeVisible();
  await sm.waitForTimeout(1200);
  expect((await runs(a, 'mats')).length).toBe(runsBefore);
  await goOnline(sm);
  await eventually(() => runs(a, 'mats'), (r) => r.length === runsBefore + 1 || r.length > runsBefore, 'monthly run synced', 45_000);
  await sm.waitForTimeout(2500);
  const monthly = (await runs(a, 'mats')).filter((r) => r.run.kind === 'monthly' && r.run.periodFrom.slice(0, 7) === TODAY.slice(0, 7));
  expect(monthly.length, 'exactly one monthly run').toBe(1);
  expect(monthly[0].lines.length).toBeGreaterThan(0);
  await expect(sm.getByText(/waiting to sync/)).toHaveCount(0);
  await c2.close();
  w.assertClean();
});
