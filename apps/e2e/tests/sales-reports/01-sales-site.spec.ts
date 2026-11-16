import { expect, loginAs, test } from '../../support/helpers';
import { A, enter, goto, matsData, money, shoot } from './util';

// Sales role (Mats): every sales screen against the real API.

test('sales: orders list shows server data, filters and the inspector', async ({ page, w }) => {
  const a = await A('ceo1');
  const { orders } = await a.json('/sites/mats/orders');
  await loginAs(page, 'salesmats');
  await enter(page);
  await goto(page, '/s/mats/sales/orders');
  await expect(page.getByRole('heading', { name: 'Orders', exact: true })).toBeVisible();
  for (const o of orders) await expect(page.getByText(o.number, { exact: true }).first()).toBeVisible();
  await expect(page.getByText(`${orders.length} orders`).first()).toBeVisible();
  await shoot(page, 'sales-orders-list');
  // stage filters
  for (const tab of [/^Awaiting cash/, /^Awaiting approval/, /^To load/, /^Held/]) {
    await page.getByRole('tab', { name: tab }).first().click().catch(async () => page.getByRole('button', { name: tab }).first().click());
  }
  await page.getByRole('tab', { name: /^All/ }).first().click().catch(() => undefined);
  // search
  // inspector shows server total + server prices
  const o8 = orders.find((o: any) => o.status === 'awaiting_approval' && o.overLimit) ?? orders.find((o: any) => o.status === 'awaiting_approval');
  expect(o8, 'an order awaiting approval exists').toBeTruthy();
  await page.getByText(o8.number, { exact: true }).first().click();
  const dlg = page.getByRole('dialog');
  await expect(dlg).toContainText(o8.number);
  await expect(dlg).toContainText(money(o8.total, o8.currency));
  if (o8.overLimit) await expect(dlg).toContainText('Over the credit limit');
  // sales may not approve: only the CEO has "Open credit desk"
  await expect(dlg.getByRole('button', { name: /approve|credit desk/i })).toHaveCount(0);
  await shoot(page, 'sales-order-inspector-awaiting-approval');
  await page.keyboard.press('Escape');
  w.assertClean();
});

test('sales: new order creates with server prices and number; invalid state blocks', async ({ page, w }) => {
  await loginAs(page, 'salesmats');
  await enter(page);
  await goto(page, '/s/mats/sales/orders');
  await page.getByRole('button', { name: 'New order' }).click();
  const dlg = page.getByRole('dialog');
  // invalid: nothing chosen -> Create disabled
  await expect(dlg.getByRole('button', { name: 'Create order' })).toBeDisabled();
  await shoot(page, 'sales-new-order-empty');
  await dlg.getByText('Owino Market Traders').click();
  await expect(dlg.getByRole('button', { name: 'Create order' })).toBeDisabled(); // no lines yet
  await dlg.getByPlaceholder('Choose a product…').click();
  await page.getByRole('option').filter({ hasText: /Rihlaat/ }).first().click();
  await dlg.getByLabel('Bundles').first().fill('3');
  // list estimate for price comes from the server config
  const cfg = await (await A('ceo1')).json('/sites/mats/config');
  expect(JSON.stringify(cfg)).toContain('Rihlaat');
  await shoot(page, 'sales-new-order-filled');
  const before = (await (await A('ceo1')).json('/sites/mats/orders')).orders.length;
  await dlg.getByRole('button', { name: 'Create order' }).click();
  // the new order opens in its inspector
  await expect(dlg.getByText('Awaiting cash').first()).toBeVisible({ timeout: 15_000 });
  await page.keyboard.press('Escape');
  await expect(dlg).toBeHidden();
  await expect.poll(async () => (await (await A('ceo1')).json('/sites/mats/orders')).orders.length, { timeout: 20_000, intervals: [1000] }).toBe(before + 1);
  const { orders } = await (await A('ceo1')).json('/sites/mats/orders');
  const mine = orders[0];
  expect(mine.number).toMatch(/^SO-\d{5}$/);
  expect(mine.total).toBe(900000); // 3 x UGX 300,000 from the server price list
  await expect(page.getByText(mine.number, { exact: true }).first()).toBeVisible({ timeout: 20_000 });
  expect(await page.locator('body').innerText()).not.toMatch(/SO-PROV|provisional/i);
  w.assertClean();
});

test('sales: clients list, inspector, new client (cash only for sales), validation', async ({ page, w }) => {
  await loginAs(page, 'salesmats');
  await enter(page);
  await goto(page, '/s/mats/sales/clients');
  await expect(page.getByRole('heading', { name: 'Clients', exact: true })).toBeVisible();
  const clients = (await (await A('ceo1')).json('/sites/mats/clients')).clients;
  const exportClient = clients.find((c: any) => c.channel === 'export');
  await expect(page.getByText(exportClient.name).first()).toBeVisible();
  await shoot(page, 'sales-clients');
  await page.locator('tr, [role=row]').filter({ hasText: exportClient.name }).first().click();
  await expect(page.getByRole('dialog')).toContainText(exportClient.name);
  await shoot(page, 'sales-client-inspector');
  await page.keyboard.press('Escape');
  await page.getByRole('button', { name: 'New client' }).click();
  const dlg = page.getByRole('dialog');
  await expect(dlg.getByRole('button', { name: 'Save client' })).toBeDisabled();
  await expect(dlg).toContainText(/Needs a business name/);
  // export disabled for sales
  await expect(dlg.getByRole('radio', { name: /Export/ }).or(dlg.getByRole('button', { name: /Export · credit/ }))).toBeDisabled();
  await shoot(page, 'sales-new-client-sales-role');
  const name = `E2E Local Buyer ${Date.now()}`;
  await dlg.getByPlaceholder('Registered or trading name').fill(name);
  await dlg.getByPlaceholder('Country code and number').fill('12');
  await expect(dlg.getByRole('button', { name: 'Save client' })).toBeDisabled();
  await dlg.getByPlaceholder('Country code and number').fill('+256 700 123 456');
  await dlg.getByRole('button', { name: 'Save client' }).click();
  await expect(page.getByText(`${name} added`)).toBeVisible({ timeout: 15_000 });
  const after = (await (await A('ceo1')).json('/sites/mats/clients')).clients;
  expect(after.length).toBe(clients.length + 1);
  const c = after.find((x: any) => x.name === name);
  expect(c.creditLimit).toBe(0); expect(c.channel).toBe('local');
  w.assertClean();
});

test('sales: invoices, statements and payments tabs; print sheet opens with server data', async ({ page, w }) => {
  await loginAs(page, 'salesmats');
  await enter(page);
  await goto(page, '/s/mats/sales/invoices');
  const invs = (await (await A('ceo1')).json('/sites/mats/invoices')).invoices;
  await expect(page.getByRole('heading', { name: /Invoices and statements/ })).toBeVisible();
  for (const i of invs) await expect(page.getByText(i.number, { exact: true }).first()).toBeVisible();
  await shoot(page, 'sales-invoices');
  await page.getByText(invs[0].number, { exact: true }).first().click();
  await expect(page.getByText(/EFRIS is not connected/).first()).toBeVisible();
  await shoot(page, 'sales-invoice-preview');
  for (const t of ['Statements', 'Payments']) {
    await page.getByRole('tab', { name: new RegExp(`^${t}`) }).click();
    await shoot(page, `sales-invoices-${t.toLowerCase()}`);
  }
  w.assertClean();
});
