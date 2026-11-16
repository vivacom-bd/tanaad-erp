import { expect, test } from '../../support/helpers';
import { enter } from '../../support/nav';
import { TODAY, amountBox, apiAs, cashDay, dayLabel, ensureOpen, num, pickDay, shot } from '../../support/cashgroup';

// Cashier of Mats: cash book render, opening balance, receipts. State comes from the seed and the API, never from constants.
test.use({ actionTimeout: 20_000 });
test.describe.configure({ mode: 'serial', timeout: 180_000 });

test('cash book renders the server day for the cashier (today and an earlier day)', async ({ page, request, w }) => {
  await enter(page, 'cashmats', '/s/mats/cash');
  await expect(page.getByRole('heading', { name: 'Cash book' })).toBeVisible();
  const today = await cashDay(request, 'cashmats', 'mats');
  if (today.day) {
    await expect(page.getByText(`UGX ${num(today.totals.expectedClosing)}`).first()).toBeVisible();
    await expect(page.getByText(`UGX ${num(today.totals.opening)}`).first()).toBeVisible();
    if (today.day.status === 'closed') await expect(page.getByText(/Closed by/).first()).toBeVisible();
  } else await expect(page.getByText(`No page for ${dayLabel(TODAY)}`)).toBeVisible();
  await expect(page.getByText('Unknown user')).toHaveCount(0);
  await shot(page, 'cash-book-today');

  // the newest earlier day with entries
  const a = await apiAs(request, 'cashmats');
  let past: { date: string; entries: { payee: string | null }[]; totals: { expectedClosing: number } } | null = null;
  for (let i = 1; i < 10 && !past; i++) {
    const d = new Date(TODAY + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() - i);
    const day = d.toISOString().slice(0, 10);
    const r = await a.json(`/sites/mats/cash/days/${day}`);
    if (r.entries.some((e: { payee: string | null }) => e.payee)) past = r;
  }
  expect(past, 'an earlier day with entries in the seed').toBeTruthy();
  await pickDay(page, dayLabel(past!.date).slice(4));
  await expect(page.getByText(past!.entries.find((e) => e.payee)!.payee!).first()).toBeVisible();
  await expect(page.getByText(num(past!.totals.expectedClosing)).first()).toBeVisible();
  await shot(page, 'cash-book-past-day');
  w.assertClean();
});

test('opening balance: invalid values blocked, a valid one lands on the server', async ({ page, request, w }) => {
  // The opening can only be set on a day with no entries that is not closed: find one among the sites' pages.
  let pick: { site: string; user: string } | null = null;
  for (const [site, user] of [['bags', 'cashbags'], ['recycling', 'cashrec'], ['mats', 'cashmats']] as const) {
    const d = await cashDay(request, user, site);
    if (d.entries.length === 0 && d.day?.status !== 'closed') { pick = { site, user }; break; }
  }
  test.skip(!pick, 'every site already has entries or a closed page for today (seed state)');
  await enter(page, pick!.user, `/s/${pick!.site}/cash`);
  const field = page.getByLabel('Opening balance (UGX)');
  const set = page.getByRole('button', { name: 'Set opening' });
  await expect(set).toBeDisabled();
  await field.fill('-5'); await expect(set).toBeDisabled();
  await field.fill('9000000');
  await expect(set).toBeEnabled();
  await set.click();
  await expect.poll(async () => (await cashDay(request, pick!.user, pick!.site)).day?.opening, { timeout: 20_000 }).toBe(9_000_000);
  await expect(page.getByText('UGX 9,000,000').first()).toBeVisible();
  await shot(page, 'cash-book-opening-set');
  w.assertClean();
});

test('add receipt: empty/zero blocked; a valid receipt reaches the ledger and the server once', async ({ page, request, w }) => {
  await ensureOpen(request, 'mats');
  const payee = `Walk-in ${Date.now().toString(36).slice(-4)}`;
  await enter(page, 'cashmats', '/s/mats/cash');
  await page.getByRole('button', { name: 'Add receipt' }).first().click();
  const sheet = page.getByRole('dialog');
  const save = sheet.getByRole('button', { name: /^(Add|Save|Record)/ }).last();
  await expect(sheet.getByText('Say who paid')).toBeVisible();
  await expect(save).toBeDisabled();
  await sheet.getByPlaceholder(/Client deposit/).fill(payee);
  await expect(sheet.getByText('Enter the amount')).toBeVisible();
  await expect(save).toBeDisabled();
  await amountBox(sheet).fill('0'); await expect(save).toBeDisabled();
  await shot(page, 'cash-receipt-sheet');
  await amountBox(sheet).fill('150000');
  await expect(sheet.getByText('Ready to record')).toBeVisible();
  await save.click();
  await expect(page.getByText(payee).first()).toBeVisible();
  const mine = async () => (await cashDay(request, 'cashmats', 'mats')).entries.filter((e: { payee: string }) => e.payee === payee);
  await expect.poll(async () => (await mine()).length, { timeout: 20_000 }).toBe(1);
  expect((await mine())[0]).toMatchObject({ direction: 'in', category: 'receipt', amount: 150000 });
  const d = await cashDay(request, 'cashmats', 'mats');
  await expect(page.getByText(`UGX ${num(d.totals.expectedClosing)}`).first()).toBeVisible();
  await shot(page, 'cash-book-with-receipt');
  w.assertClean();
});
