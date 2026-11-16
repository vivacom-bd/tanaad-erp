import { expect, test } from '../../support/helpers';
import { enter, spaGo } from '../../support/nav';
import { TODAY, amountBox, apiAs, cashDay, shot } from '../../support/cashgroup';

test.use({ actionTimeout: 20_000 });
test.describe.configure({ mode: 'serial', timeout: 180_000 });

const WRITE_BUTTONS = ['Add receipt', 'Record expense', 'Count and close day', 'Reopen day'];

const NO_ACCESS = "You don't have access to this page";

test('supervisor: no Cash in the nav; the cash pages show a plain no-access screen; the API refuses too', async ({ page, request, w }) => {
  w.allow(/403/);
  await enter(page, 'supmats');
  await expect(page.locator('nav[aria-label="Main"] a[href$="/cash"]')).toHaveCount(0);
  await spaGo(page, '/s/mats/cash');
  await expect(page.getByText(NO_ACCESS)).toBeVisible();
  for (const b of WRITE_BUTTONS) await expect(page.getByRole('button', { name: b })).toHaveCount(0);
  await shot(page, 'cash-book-supervisor-no-access');
  await spaGo(page, '/s/mats/cash/expense');
  await expect(page.getByText(NO_ACCESS)).toBeVisible();
  await shot(page, 'expense-supervisor-no-access');
  const a = await apiAs(request, 'supmats');
  const r = await a.get(`/sites/mats/cash/days/${TODAY}`);
  expect(r.status()).toBe(403);
  expect((await r.json()).detail).toMatch(/Only the Cashier, the Site Manager or group roles/);
  for (const p of ['cash_days', 'cash_entries']) expect((await a.get(`/sites/mats/snapshots/${p}`)).status(), p).toBe(403);
});

test('CEO reads a site cash book but cannot write to it', async ({ page, w }) => {
  await enter(page, 'ceo1');
  await spaGo(page, '/s/mats/cash');
  await expect(page.getByRole('heading', { name: 'Cash book' })).toBeVisible();
  for (const b of WRITE_BUTTONS) await expect(page.getByRole('button', { name: b })).toHaveCount(0);
  await expect(page.getByText('Opening balance').first()).toBeVisible();
  await shot(page, 'cash-book-ceo-readonly');
  w.assertClean();
});

test('site manager writes to the cash book like the cashier (receipt and expense)', async ({ page, request, w }) => {
  const R = Date.now().toString(36).slice(-4);
  const d = await cashDay(request, 'smbags', 'bags');
  if (d.day?.status === 'closed') expect((await (await apiAs(request, 'smbags')).post(`/sites/bags/cash/days/${TODAY}/reopen`, { reason: 'Site manager test' })).ok()).toBeTruthy();
  await enter(page, 'smbags', '/s/bags/cash');
  await expect(page.getByRole('button', { name: 'Add receipt' }).first()).toBeEnabled();
  await page.getByRole('button', { name: 'Add receipt' }).first().click();
  const sheet = page.getByRole('dialog');
  await sheet.getByPlaceholder(/Client deposit/).fill(`SM receipt ${R}`);
  await amountBox(sheet).fill('7000');
  await sheet.getByRole('button', { name: /^(Add|Save|Record)/ }).last().click();
  await expect.poll(async () => (await cashDay(request, 'smbags', 'bags')).entries.filter((e: { payee: string }) => e.payee === `SM receipt ${R}`).length, { timeout: 20_000 }).toBe(1);
  w.assertClean();
});

test('cross-site isolation: the Mats cashier gets nothing from Bags, and the reverse', async ({ page, request, w }) => {
  w.allow(/403/);
  const mats = await apiAs(request, 'cashmats'); const bags = await apiAs(request, 'cashbags');
  for (const [a, site, label] of [[mats, 'bags', 'Mats cashier -> Bags'], [bags, 'mats', 'Bags cashier -> Mats']] as const) {
    for (const path of [`/sites/${site}/cash/days/${TODAY}`, `/sites/${site}/cash/months/${TODAY.slice(0, 7)}`, `/sites/${site}/snapshots/cash_days`, `/sites/${site}/snapshots/cash_entries`]) {
      const r = await a.get(path);
      expect.soft(r.status(), `${label} ${path}`).toBe(403);
      expect.soft((await r.json()).detail, `${label} ${path}`).toMatch(/no access to/i);
    }
    const wr = await a.post(`/sites/${site}/cash/entries`, { direction: 'in', category: 'receipt', amount: 1, payee: 'Intruder', day: TODAY });
    expect.soft(wr.status(), `${label} write`).toBe(403);
  }
  // In the browser the Mats cashier is told the Bags pages are not theirs
  await enter(page, 'cashmats', '/s/bags/cash');
  await expect(page.getByText(NO_ACCESS)).toBeVisible();
  await shot(page, 'cash-book-cross-site-no-access');
});
