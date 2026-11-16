import { expect, goOffline, goOnline, test } from '../../support/helpers';
import { enter } from '../../support/nav';
import { TODAY, amountBox, apiAs, cashDay, shot } from '../../support/cashgroup';

// Bags site: its own cashier (cashbags) and manager (smbags). Everything the cashier does offline goes through the outbox.
test.use({ actionTimeout: 20_000 });
test.describe.configure({ mode: 'serial', timeout: 180_000 });
const R = Date.now().toString(36).slice(-4); // names stay unique when the suite is re-run on the same DB
const P = (s: string) => `${s} ${R}`;

async function ensureOpen(request: import('@playwright/test').APIRequestContext) {
  const d = await cashDay(request, 'cashbags', 'bags');
  if (d.day?.status === 'closed') { const r = await (await apiAs(request, 'smbags')).post(`/sites/bags/cash/days/${TODAY}/reopen`, { reason: 'Test setup reopen' }); expect(r.ok()).toBeTruthy(); }
}

type Entry = { payee: string; amount: number; supersededBy?: string | null };
const count = async (request: import('@playwright/test').APIRequestContext, payee: string) =>
  (await cashDay(request, 'cashbags', 'bags')).entries.filter((e: Entry) => e.payee === payee && !e.supersededBy).length;

async function addReceipt(page: import('@playwright/test').Page, payee: string, amount: string) {
  await page.getByRole('button', { name: 'Add receipt' }).first().click();
  const sheet = page.getByRole('dialog');
  await sheet.getByPlaceholder(/Client deposit/).fill(payee);
  await amountBox(sheet).fill(amount);
  await sheet.getByRole('button', { name: /^(Add|Save|Record)/ }).last().click();
  await expect(page.getByText(payee).first()).toBeVisible();
}

test('receipt and expense made offline reach the server exactly once after reconnecting', async ({ page, request, w }) => {
  await ensureOpen(request);
  await enter(page, 'cashbags', '/s/bags/cash');
  await addReceipt(page, P('Bags online receipt'), '100000'); // opens the page
  await expect.poll(() => count(request, P('Bags online receipt')), { timeout: 20_000 }).toBe(1);

  // the expense screen is a lazily loaded chunk: open it once while online (a installed app has it cached)
  await page.evaluate(() => { history.pushState({}, '', '/s/bags/cash/expense'); window.dispatchEvent(new PopStateEvent('popstate')); });
  await expect(page.getByRole('heading', { name: 'New expense' })).toBeVisible();
  await page.evaluate(() => { history.pushState({}, '', '/s/bags/cash'); window.dispatchEvent(new PopStateEvent('popstate')); });
  await expect(page.getByRole('heading', { name: 'Cash book' })).toBeVisible();
  await goOffline(page);
  await addReceipt(page, P('Bags offline receipt'), '40000');
  await expect(page.getByTitle('Waiting to sync')).toBeVisible({ timeout: 15_000 });
  expect(await count(request, P('Bags offline receipt'))).toBe(0);
  await shot(page, 'cash-offline-queued');

  // an expense through the voucher while still offline
  await page.getByRole('link', { name: 'Record expense' }).first().click().catch(async () => { await page.evaluate(() => { history.pushState({}, '', '/s/bags/cash/expense'); window.dispatchEvent(new PopStateEvent('popstate')); }); });
  await page.waitForURL(/\/cash\/expense/);
  await amountBox(page).fill('12000');
  await page.getByRole('radiogroup', { name: 'Category' }).getByRole('radio', { name: /Other/ }).click();
  await page.getByPlaceholder('Name of person or supplier').fill(P('Bags offline expense'));
  await page.getByRole('button', { name: /^Save expense/ }).click();
  await expect(page.getByText('Recorded under Other')).toBeVisible();

  await goOnline(page);
  await expect.poll(() => count(request, P('Bags offline receipt')), { timeout: 40_000 }).toBe(1);
  await expect.poll(() => count(request, P('Bags offline expense')), { timeout: 40_000 }).toBe(1);
  await expect(page.getByTitle('Waiting to sync')).toHaveCount(0, { timeout: 20_000 });
  await page.waitForTimeout(4000); // another sync cycle must not duplicate
  expect(await count(request, P('Bags offline receipt'))).toBe(1);
  expect(await count(request, P('Bags offline expense'))).toBe(1);
  w.assertClean();
});

test('closing the day offline over the tolerance: one close on the server, variance exception raised', async ({ page, request, w }) => {
  const cv0 = ((await (await apiAs(request, 'admin')).json('/exceptions')).data ?? []).filter((e: { kind: string; siteCode: string }) => e.kind === 'cash_variance' && e.siteCode === 'bags').length;
  const expected = (await cashDay(request, 'cashbags', 'bags')).totals.expectedClosing as number;
  const counted = Math.ceil((expected + 50_000) / 500) * 500; // over by more than the 20,000 tolerance, in notes the drawer can hold
  const variance = counted - expected;
  await enter(page, 'cashbags', '/s/bags/cash');
  await page.getByRole('button', { name: 'Count and close day' }).first().click();
  const sheet = page.getByRole('dialog');
  let rest = counted;
  for (const n of [50000, 20000, 10000, 5000, 2000, 1000, 500]) { const q = Math.floor(rest / n); if (q) await sheet.getByLabel(`How many ${n.toLocaleString('en-UG')}`).fill(String(q)); rest -= q * n; }
  expect(rest).toBe(0);
  await expect(sheet.getByText('Over beyond tolerance')).toBeVisible();
  await shot(page, 'cash-close-day-over');
  await sheet.getByRole('button', { name: /^Sign as/ }).click();
  await goOffline(page);
  await sheet.getByRole('button', { name: 'Confirm and sign off' }).click();
  await expect(page.getByText(/Day closed · over by UGX/)).toBeVisible();
  expect((await cashDay(request, 'cashbags', 'bags')).day.status).not.toBe('closed');
  await goOnline(page);
  await expect.poll(async () => (await cashDay(request, 'cashbags', 'bags')).day.status, { timeout: 40_000 }).toBe('closed');
  const d = await cashDay(request, 'cashbags', 'bags');
  expect(d.day).toMatchObject({ countedClosing: counted, variance });
  const ceo = await apiAs(request, 'admin');
  const cv = async () => ((await ceo.json('/exceptions')).data ?? []).filter((e: { kind: string; siteCode: string }) => e.kind === 'cash_variance' && e.siteCode === 'bags').length;
  await expect.poll(cv, { timeout: 20_000 }).toBe(cv0 + 1);
  w.assertClean();
});

test('an entry queued offline for a day that was closed meanwhile is refused with a plain message and removed from the ledger', async ({ page, request, w }) => {
  w.allow(/409|422|day_locked|locked/i);
  // the manager reopens the day (server side), the cashier loads it and goes offline
  const mgr = await apiAs(request, 'smbags');
  await ensureOpen(request);
  await enter(page, 'cashbags', '/s/bags/cash');
  await expect(page.getByRole('button', { name: 'Count and close day' }).first()).toBeVisible();
  await goOffline(page);
  await addReceipt(page, P('Too late receipt'), '5000');
  // meanwhile the manager closes the day on the server
  const exp = (await cashDay(request, 'smbags', 'bags')).totals.expectedClosing as number;
  const cl = await mgr.post(`/sites/bags/cash/days/${TODAY}/close`, { counted: Math.floor(exp / 500) * 500, denominations: { '500': Math.floor(exp / 500) } });
  expect(cl.ok(), await cl.text()).toBeTruthy();
  await goOnline(page);
  await page.getByRole('button', { name: 'Sync problems' }).waitFor({ timeout: 40_000 });
  await page.getByRole('button', { name: 'Sync problems' }).click();
  const panel = page.getByText('Not accepted by the server').locator('..').locator('..');
  await expect(panel.getByText('Record a cash entry')).toBeVisible();
  await expect(panel.getByText(/is closed/)).toBeVisible();
  await page.setViewportSize({ width: 390, height: 844 });
  const box = await page.getByText('Not accepted by the server').boundingBox();
  expect(box!.x, 'rejection list stays inside a 390px screen').toBeGreaterThanOrEqual(0);
  await page.setViewportSize({ width: 1440, height: 900 });
  await shot(page, 'cash-rejected-list');
  expect(await count(request, P('Too late receipt'))).toBe(0);
  await page.getByRole('button', { name: 'Dismiss' }).first().click();
  await expect(page.getByText(P('Too late receipt'))).toHaveCount(0);
  w.assertClean();
});
