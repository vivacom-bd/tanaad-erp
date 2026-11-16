import { expect, test } from '../../support/helpers';
import { enter } from '../../support/nav';
import { TODAY, amountBox, apiAs, cashDay, ensureOpen, shot } from '../../support/cashgroup';

const cat = (page: import('@playwright/test').Page, name: string) => page.getByRole('radiogroup', { name: 'Category' }).getByRole('radio', { name: new RegExp(name) });
const RUN = Date.now().toString(36).slice(-4); const BM = `Boda Moses ${RUN}`; const RT = `Resin Traders ${RUN}`;
test.use({ actionTimeout: 20_000 });
test.describe.configure({ mode: 'serial', timeout: 180_000 });

type Entry = { payee: string; category: string; subcategory: string | null; amount: number; direction: string; note: string | null; machineId: string | null; hasReceiptPhoto: boolean };
const entriesOf = async (request: import('@playwright/test').APIRequestContext, payee: string): Promise<Entry[]> =>
  (await cashDay(request, 'cashmats', 'mats')).entries.filter((e: Entry) => e.payee === payee);

test('expense voucher: categories and quick expenses come from the server config', async ({ page, request, w }) => {
  await ensureOpen(request, 'mats');
  const cfg = await (await apiAs(request, 'cashmats')).json('/sites/mats/config');
  const cats: string[] = cfg.refs.filter((r: { list: string; active: boolean }) => r.list === 'expense_category' && r.active).sort((a: { order: number }, b: { order: number }) => a.order - b.order).map((r: { label: string }) => r.label);
  const quick: string[] = cfg.refs.filter((r: { list: string; active: boolean }) => r.list === 'quick_expense' && r.active).map((r: { label: string }) => r.label);
  await enter(page, 'cashmats', '/s/mats/cash/expense');
  await expect(page.getByRole('heading', { name: 'New expense' })).toBeVisible();
  const chips = page.getByRole('radiogroup', { name: 'Category' }).getByRole('radio');
  await expect(chips).toHaveCount(cats.length);
  for (const [i, c] of cats.entries()) await expect(chips.nth(i)).toContainText(c);
  for (const q of quick) await expect(page.getByRole('button', { name: new RegExp(`^${q}`) })).toBeVisible();
  await shot(page, 'expense-voucher-empty');
  w.assertClean();
});

test('expense voucher: incomplete or zero blocked, one missing field at a time', async ({ page, request, w }) => {
  await ensureOpen(request, 'mats');
  await enter(page, 'cashmats', '/s/mats/cash/expense');
  const save = page.getByRole('button', { name: /^Save expense/ });
  await expect(save).toBeDisabled();
  await expect(page.getByText('Enter the amount').first()).toBeVisible();
  await amountBox(page).fill('0'); await expect(save).toBeDisabled();
  // A minus sign cannot be typed: the field only keeps digits.
  await amountBox(page).fill('-50000');
  await expect(amountBox(page)).toHaveValue('50,000');
  await expect(page.getByText('Pick a category').first()).toBeVisible();
  await cat(page, 'Transport').click();
  await expect(page.getByText(/Pick a transport type/i).first()).toBeVisible();
  await page.getByRole('radiogroup', { name: 'Kind' }).getByRole('radio', { name: 'Fuel' }).click();
  await expect(page.getByText('Who was paid?').first()).toBeVisible();
  await expect(save).toBeDisabled();
  await page.getByPlaceholder('Name of person or supplier').fill('   ');
  await expect(save).toBeDisabled();
  await page.getByPlaceholder('Name of person or supplier').fill('Shell Kampala Rd');
  await expect(save).toBeEnabled();
  // Spares need a machine.
  await cat(page, 'Spares').click();
  await expect(page.getByText(/Pick a spares type/i).first()).toBeVisible();
  await page.getByRole('radiogroup', { name: 'Kind' }).getByRole('radio', { name: 'Belts' }).click();
  await expect(page.getByText('Pick the machine the spare is for').first()).toBeVisible();
  await expect(save).toBeDisabled();
  await shot(page, 'expense-voucher-invalid');
  w.assertClean();
});

test('expense voucher: quick fill, save once, appears in today list and on the server', async ({ page, request, w }) => {
  await enter(page, 'cashmats', '/s/mats/cash/expense');
  await page.getByRole('button', { name: /^Boda/ }).click();
  await expect(page.getByPlaceholder('Name of person or supplier')).toHaveValue('Boda');
  await expect(cat(page, 'Transport')).toBeChecked();
  await amountBox(page).fill('15000');
  await page.getByPlaceholder('Name of person or supplier').fill(BM);
  await page.getByRole('button', { name: /Take a photo of the receipt/ }).click();
  await expect(page.getByText('Photo attached').first()).toBeVisible();
  await expect(page.getByText('Ready to save').first()).toBeVisible();
  await page.getByRole('button', { name: /^Save expense/ }).click();
  await expect(page.getByText('Recorded under Transport')).toBeVisible();
  await expect(page.getByText(BM).first()).toBeVisible();
  await expect.poll(async () => (await entriesOf(request, BM)).length, { timeout: 20_000 }).toBe(1);
  const [e] = await entriesOf(request, BM);
  expect(e).toMatchObject({ direction: 'out', category: 'transport', subcategory: 'Boda', amount: 15000, hasReceiptPhoto: true });
  await shot(page, 'expense-voucher-after-save');
  w.assertClean();
});

test('expense above the approval limit asks for the Site Manager PIN', async ({ page, request, w }) => {
  await enter(page, 'cashmats', '/s/mats/cash/expense');
  await amountBox(page).fill('600000');
  await cat(page, 'Materials').click();
  await page.getByPlaceholder('Name of person or supplier').fill(RT);
  await expect(page.getByText('Needs Site Manager approval').first()).toBeVisible();
  await page.getByRole('button', { name: /^Approve and save/ }).click();
  const sheet = page.getByRole('dialog');
  await expect(sheet.getByText(/above the UGX 500,000 limit/)).toBeVisible();
  await page.keyboard.type('0000');
  await expect(sheet.getByText('Wrong PIN. Try again.')).toBeVisible();
  expect(await entriesOf(request, RT)).toHaveLength(0);
  await shot(page, 'expense-pin-wrong');
  await sheet.getByText('Site Manager PIN', { exact: true }).click();
  await page.keyboard.type('2468');
  await expect(page.getByText('Recorded under Materials')).toBeVisible();
  await expect.poll(async () => (await entriesOf(request, RT)).length, { timeout: 20_000 }).toBe(1);
  const [e] = await entriesOf(request, RT);
  expect(e).toMatchObject({ category: 'materials', amount: 600000, direction: 'out' });
  w.assertClean();
});

test('server rejects bad entries (zero, negative, no category) with a plain message', async ({ request }) => {
  const a = await apiAs(request, 'cashmats');
  const base = { direction: 'out', category: 'other', payee: 'X' };
  for (const [name, body] of [['zero', { ...base, day: TODAY, amount: 0 }], ['negative', { ...base, day: TODAY, amount: -10 }], ['no category', { direction: 'out', day: TODAY, amount: 100 }]] as const) {
    const r = await a.post('/sites/mats/cash/entries', body);
    const j = await r.json().catch(() => ({}));
    expect.soft(r.status(), `${name}: ${JSON.stringify(j).slice(0, 200)}`).toBe(422);
    expect.soft(typeof j.detail, `${name} gives a readable detail`).toBe('string');
  }
});
