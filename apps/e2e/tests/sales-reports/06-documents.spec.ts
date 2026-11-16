import { expect, loginAs, test } from '../../support/helpers';
import { A, enter, goto, matsData, money, shoot } from './util';

// Print sheets open from the server's document endpoint: server data plus the content hash.
const HASH = /Document hash [0-9a-f]{16,}/;

test('document: client statement (sales) shows the server invoices and a content hash', async ({ page, w }) => {
  await loginAs(page, 'salesmats'); await enter(page); await goto(page, '/s/mats/sales/clients');
  const { clients, invoices } = await matsData();
  const inv0 = invoices.find((i: any) => i.balance > 0) ?? invoices[0];
  const kigali = clients.find((c: any) => c.id === inv0.clientId);
  await page.locator('tr, [role=row]').filter({ hasText: kigali.name }).first().click();
  const stmt = await (await A('ceo2')).get(`/sites/mats/clients/${kigali.id}/statement`);
  expect(stmt.ok).toBeTruthy();
  await page.getByRole('dialog').getByRole('button', { name: /Print statement/ }).click();
  const sheet = page.locator('.print-area');
  await expect(sheet).toContainText('Statement of account');
  await expect(sheet).toContainText(inv0.number);
  await expect(sheet).toContainText(HASH);
  await shoot(page, 'document-statement-print-sheet');
  w.assertClean();
});

test('document: invoice print sheet from the order inspector (cashier) matches the server invoice', async ({ page, w }) => {
  await loginAs(page, 'cashmats'); await enter(page); await goto(page, '/s/mats/sales/orders');
  const { invoices } = await matsData();
  const inv = invoices.find((i: any) => i.balance > 0)!;
  await page.getByText(inv.orderNumber, { exact: true }).first().click();
  await page.getByRole('dialog').getByRole('button', { name: /Print invoice/ }).click();
  const sheet = page.locator('.print-area');
  await expect(sheet).toContainText(inv.number); await expect(sheet).toContainText(inv.total.toLocaleString('en-US')); await expect(sheet).toContainText(inv.balance.toLocaleString('en-US')); // total and balance
  await expect(sheet).toContainText(HASH);
  await expect(sheet).toContainText(/bank details to be provided|Bank/i); // blank company details print as a visible gap, not invented text
  w.assertClean();
});

test('document: delivery note print sheet (storekeeper) shows the gate figures and a hash', async ({ page, w }) => {
  await loginAs(page, 'smmats'); await enter(page); await goto(page, '/s/mats/stock/deliveries'); // storemats signs in as Gate (no Deliveries); sales may not read dispatches
  const first = page.getByText(/DN-\d{5}/).first();
  await expect(first).toBeVisible();
  const dn = ((await first.innerText()).match(/DN-\d{5}/) as RegExpMatchArray)[0];
  await first.click();
  await page.getByRole('button', { name: /Print delivery note/ }).first().click();
  const sheet = page.locator('.print-area');
  await expect(sheet).toContainText('Delivery note'); await expect(sheet).toContainText(dn);
  await expect(sheet).toContainText('Gate scale'); await expect(sheet).toContainText(HASH);
  await shoot(page, 'document-delivery-note-print-sheet');
  w.assertClean();
});

test('document: payslip print sheet (site manager) shows the worker, the net pay and a hash', async ({ page, w }) => {
  await loginAs(page, 'smmats'); await enter(page); await goto(page, '/s/mats/people/settlement');
  await page.getByRole('button', { name: 'Payslip' }).first().click();
  const sheet = page.locator('.print-area');
  await expect(sheet).toContainText('Payslip'); await expect(sheet).toContainText(/Net pay UGX/); await expect(sheet).toContainText(HASH);
  await shoot(page, 'document-payslip-print-sheet');
  w.assertClean();
});

test('document: an unknown document id is refused in words, not a blank sheet', async () => {
  const r = await (await A('salesmats')).post('/documents/invoice/00000000-0000-0000-0000-000000000000/render');
  expect([403, 404, 422]).toContain(r.status);
  expect(JSON.stringify(r.json)).toMatch(/not found|forbidden|detail/i);
});
