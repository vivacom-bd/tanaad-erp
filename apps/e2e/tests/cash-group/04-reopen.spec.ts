import { expect, test } from '../../support/helpers';
import { enter } from '../../support/nav';
import { amountBox, cashDay, countNotes, ensureClosed, roundingReceipt, shot } from '../../support/cashgroup';

test.use({ actionTimeout: 20_000 });
test.describe.configure({ mode: 'serial', timeout: 180_000 });

async function startReopen(page: import('@playwright/test').Page, reason: string) {
  await page.getByRole('button', { name: /Reopen/ }).first().click();
  const sheet = page.getByRole('dialog');
  await sheet.getByPlaceholder('Reason').fill(reason);
  return sheet;
}

test('reopen needs a reason of a few words before the PIN step', async ({ page, request, w }) => {
  await ensureClosed(request, 'mats');
  await enter(page, 'smmats', '/s/mats/cash');
  const sheet = await startReopen(page, 'no');
  await expect(sheet.getByRole('button', { name: 'Enter the PIN' })).toBeDisabled();
  await expect(sheet.getByText('Give a reason first.')).toBeVisible();
  await shot(page, 'cash-reopen-reason');
  await sheet.getByPlaceholder('Reason').fill('Fuel receipt found in the drawer');
  await sheet.getByRole('button', { name: 'Enter the PIN' }).click();
  await sheet.getByText('Site Manager PIN', { exact: true }).last().click();
  await page.keyboard.type('1111');
  await expect(sheet.getByText('Wrong PIN. Try again.')).toBeVisible();
  w.assertClean();
});

test('a cashier needs a Site Manager to approve: a wrong PIN is refused by the server in plain words and the day stays closed', async ({ page, request, w }) => {
  w.allow(/403|forbidden/i); // the approval is checked by the server (a refusal is expected)
  await enter(page, 'cashmats', '/s/mats/cash');
  const sheet = await startReopen(page, 'Cashier asking for a reopen');
  // The Site Manager is picked from the site's directory (chosen for them when only one is listed).
  const pick = sheet.getByPlaceholder('Choose the Site Manager');
  if (await pick.count()) { await pick.click(); await page.getByRole('option').first().click(); }
  await sheet.getByRole('button', { name: 'Enter the PIN' }).click();
  await sheet.getByText('Site Manager PIN', { exact: true }).last().click();
  await page.keyboard.type('9999');
  await expect(sheet.getByText('Manager approval failed. Check the manager and PIN.')).toBeVisible();
  expect((await cashDay(request, 'cashmats', 'mats')).day.status).toBe('closed');
  await shot(page, 'cash-reopen-approval-refused');
  w.assertClean();
});

test('site manager reopens with a reason; the cashier adds the missing line and closes again within tolerance', async ({ page, request, w }) => {
  await enter(page, 'smmats', '/s/mats/cash');
  const sheet = await startReopen(page, 'Fuel receipt found in the drawer');
  await sheet.getByRole('button', { name: 'Enter the PIN' }).click();
  await sheet.getByText('Site Manager PIN', { exact: true }).last().click();
  await page.keyboard.type('2468');
  await expect(page.getByText(/reopened/).first()).toBeVisible();
  await expect.poll(async () => (await cashDay(request, 'smmats', 'mats')).day.status, { timeout: 20_000 }).toBe('reopened');
  expect((await cashDay(request, 'smmats', 'mats')).day.reopenReason).toBe('Fuel receipt found in the drawer');
  await expect(page.getByText(/Reopened · sign again/).first()).toBeVisible();
  await shot(page, 'cash-book-reopened');
  w.assertClean();
});

test('after a reopen the cashier can add a line again, then re-close balanced', async ({ page, request, w }) => {
  await enter(page, 'cashmats', '/s/mats/cash');
  await expect(page.getByText(/Reopened/).first()).toBeVisible();
  await page.getByRole('button', { name: 'Add receipt' }).first().click();
  const sheet = page.getByRole('dialog');
  const amt = roundingReceipt((await cashDay(request, 'cashmats', 'mats')).totals.expectedClosing);
  await sheet.getByPlaceholder(/Client deposit/).fill('Late scrap sale');
  await amountBox(sheet).fill(String(amt));
  await sheet.getByRole('button', { name: /^(Add|Save|Record)/ }).last().click();
  await expect.poll(async () => (await cashDay(request, 'cashmats', 'mats')).entries.some((e: { payee: string }) => e.payee === 'Late scrap sale'), { timeout: 20_000 }).toBe(true);
  const expected = (await cashDay(request, 'cashmats', 'mats')).totals.expectedClosing as number;
  await page.getByRole('button', { name: 'Count and close day' }).first().click();
  const close = page.getByRole('dialog');
  const rest = await countNotes(close, expected);
  expect(rest).toBe(0);
  await expect(close.getByText('Balanced to the shilling')).toBeVisible();
  await close.getByRole('button', { name: /^Sign as/ }).click();
  await close.getByRole('button', { name: 'Confirm and sign off' }).click();
  await expect(page.getByText('Day closed and signed · balanced')).toBeVisible();
  await expect.poll(async () => (await cashDay(request, 'cashmats', 'mats')).day.status, { timeout: 20_000 }).toBe('closed');
  expect((await cashDay(request, 'cashmats', 'mats')).day.variance).toBe(0);
  await shot(page, 'cash-book-closed-balanced');
  w.assertClean();
});
