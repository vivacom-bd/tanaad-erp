import { expect, test } from '../../support/helpers';
import { enter } from '../../support/nav';
import { apiAs, cashDay, countNotes, ensureOpen, num, shot } from '../../support/cashgroup';

test.use({ actionTimeout: 20_000 });
test.describe.configure({ mode: 'serial', timeout: 180_000 });

type Entry = { id: string; payee: string; amount: number; supersededBy?: string | null };
const RUN = Date.now().toString(36).slice(-4);
const PAYEE = `Walk-in fix ${RUN}`;
const cvCount = async (request: import('@playwright/test').APIRequestContext, site: string) =>
  (await (await apiAs(request, 'admin')).json('/exceptions')).data.filter((e: { kind: string; siteCode: string; status: string }) => e.kind === 'cash_variance' && e.siteCode === site).length;

test('correct an entry: reason and a positive amount are required; the server keeps both versions', async ({ page, request, w }) => {
  await ensureOpen(request, 'mats');
  const r = await (await apiAs(request, 'cashmats')).post('/sites/mats/cash/entries', { direction: 'in', category: 'receipt', amount: 150000, payee: PAYEE, day: (await cashDay(request, 'cashmats', 'mats')).date });
  expect(r.status(), await r.text()).toBe(201);
  await enter(page, 'cashmats', '/s/mats/cash');
  await page.getByText(PAYEE).first().click();
  const sheet = page.getByRole('dialog');
  await expect(sheet.getByText('Received from')).toBeVisible();
  await shot(page, 'cash-line-inspector');
  await sheet.getByRole('button', { name: 'Correct entry' }).click();
  const save = sheet.getByRole('button', { name: /Save|Record|Correct|Apply/ }).last();
  const amount = sheet.getByLabel('Amount'); const reason = sheet.getByLabel('Reason for the correction');
  await expect(save).toBeDisabled();
  await amount.fill('160000');
  await expect(save).toBeDisabled(); // no reason yet
  await reason.fill('   '); await expect(save).toBeDisabled();
  await reason.fill('Typed the wrong amount'); await expect(save).toBeEnabled();
  await amount.fill('0'); await expect(save).toBeDisabled();
  await amount.fill('-5'); await expect(save).toBeDisabled();
  await amount.fill('160000');
  await shot(page, 'cash-line-correct-form');
  await save.click();
  await expect(page.getByText('Correction recorded')).toBeVisible();
  const live = async () => (await cashDay(request, 'cashmats', 'mats')).entries.filter((e: Entry) => e.payee === PAYEE && !e.supersededBy);
  await expect.poll(async () => (await live())[0]?.amount, { timeout: 20_000 }).toBe(160_000);
  expect(await live()).toHaveLength(1);
  w.assertClean();
});

test('close day: variance beyond tolerance raises a cash_variance exception the CEO can see', async ({ page, request, w }) => {
  const before = await cashDay(request, 'cashmats', 'mats');
  const expected: number = before.totals.expectedClosing;
  const counted = Math.floor((expected - 50_000) / 500) * 500; // tolerance is UGX 20,000
  const variance = counted - expected;
  const n0 = await cvCount(request, 'mats');
  await enter(page, 'cashmats', '/s/mats/cash');
  await page.getByRole('button', { name: 'Count and close day' }).first().click();
  const sheet = page.getByRole('dialog');
  const confirm = sheet.getByRole('button', { name: 'Confirm and sign off' });
  await expect(confirm).toBeDisabled();
  await expect(sheet.getByText(num(expected)).first()).toBeVisible();
  expect(await countNotes(sheet, counted)).toBe(0);
  await expect(sheet.getByText('Short beyond tolerance')).toBeVisible();
  await expect(confirm).toBeDisabled(); // not signed yet
  await shot(page, 'cash-close-day-short');
  await sheet.getByRole('button', { name: /^Sign as/ }).click();
  await expect(confirm).toBeEnabled();
  await confirm.click();
  await expect(page.getByText(/Day closed · short by UGX/)).toBeVisible();
  await expect(page.getByText(/^Closed by/).first()).toBeVisible();
  await expect.poll(async () => (await cashDay(request, 'cashmats', 'mats')).day?.status, { timeout: 20_000 }).toBe('closed');
  const d = await cashDay(request, 'cashmats', 'mats');
  expect(d.day).toMatchObject({ countedClosing: counted, variance, status: 'closed' });
  expect(Object.keys(d.day.denominations ?? {}).length).toBeGreaterThan(0);
  await expect.poll(() => cvCount(request, 'mats'), { timeout: 20_000 }).toBe(n0 + 1);
  await shot(page, 'cash-book-closed-short');
  w.assertClean();
});

test('a closed day is locked in the UI: no new receipt, no correction, opening hidden', async ({ page, w }) => {
  await enter(page, 'cashmats', '/s/mats/cash');
  await expect(page.getByRole('button', { name: 'Add receipt' }).first()).toBeDisabled();
  await expect(page.getByText(/The page is locked/)).toBeVisible();
  await expect(page.getByLabel('Opening balance (UGX)')).toHaveCount(0);
  await page.getByText(PAYEE).first().click();
  await expect(page.getByRole('dialog').getByRole('button', { name: 'Correct entry' })).toHaveCount(0);
  w.assertClean();
});
