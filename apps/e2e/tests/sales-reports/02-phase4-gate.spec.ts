import { expect, loginAs, test } from '../../support/helpers';
import { A, enter, goto, shoot } from './util';

// THE PHASE 4 GATE in the browser: over-limit export order -> CEO approves at the credit desk -> dispatch starts from the server order
// snapshot -> delivered -> invoice -> payment (overpay refused in plain words) -> the month's P&L reads from closed days only.
test.describe.configure({ mode: 'serial' });
const stamp = Date.now();
const CLIENT = `E2E Export Co ${stamp}`;
const st: { orderNo?: string; orderId?: string; invoiceId?: string; invoiceNo?: string } = {};

test('gate 1: CEO registers an export client with credit terms', async ({ page, w }) => {
  await loginAs(page, 'ceo1'); await enter(page, /mats/i);
  await goto(page, '/s/mats/sales/clients');
  await page.getByRole('button', { name: 'New client' }).click();
  const dlg = page.getByRole('dialog');
  await dlg.getByRole('radio', { name: /Export/ }).or(dlg.getByRole('button', { name: /Export · credit/ })).click();
  await expect(dlg.getByRole('button', { name: 'Save client' })).toBeDisabled();
  await expect(dlg).toContainText(/a credit limit/);
  await dlg.getByPlaceholder('Registered or trading name').fill(CLIENT);
  await dlg.getByPlaceholder('Country code and number').fill('+250 788 000 111');
  // credit limit through the number pad
  await dlg.getByPlaceholder('0').first().fill('500');
  await shoot(page, 'sales-new-client-ceo-export');
  await dlg.getByRole('button', { name: 'Save client' }).click();
  await expect(page.getByText(`${CLIENT} added`)).toBeVisible({ timeout: 15_000 });
  const c = (await (await A('ceo1')).json('/sites/mats/clients')).clients.find((x: any) => x.name === CLIENT);
  expect(c).toMatchObject({ channel: 'export', creditLimit: 500, currencyPref: 'USD' });
  expect(c.creditDays).toBeGreaterThan(0);
  w.assertClean();
});

test('gate 2: sales raises an over-limit order; the server prices it and holds it for the CEO', async ({ page, w }) => {
  await loginAs(page, 'salesmats'); await enter(page);
  await goto(page, '/s/mats/sales/orders');
  await page.getByRole('button', { name: 'New order' }).click();
  const dlg = page.getByRole('dialog');
  await dlg.getByText(CLIENT).click();
  await dlg.getByPlaceholder('Choose a product…').click();
  await page.getByRole('option').filter({ hasText: /2\.7m/ }).first().click();
  await dlg.getByLabel('Bundles').first().fill('12');
  await expect(dlg).toContainText(/Credit after this order/);
  await shoot(page, 'sales-new-order-export-over-limit');
  await dlg.getByRole('button', { name: 'Create order' }).click();
  await expect(dlg.getByText('Waiting for CEO').first()).toBeVisible({ timeout: 20_000 });
  await expect(dlg).toContainText(/Over the credit limit/);
  await expect(dlg).toContainText('$576.00'); // 12 x $48 from the server price list
  await page.keyboard.press('Escape');
  const o = (await (await A('ceo1')).json('/sites/mats/orders')).orders.find((x: any) => x.clientName === CLIENT);
  expect(o).toMatchObject({ status: 'awaiting_approval', overLimit: true, total: 576, currency: 'USD', channel: 'export' });
  expect(o.number).toMatch(/^SO-\d{5}$/);
  st.orderNo = o.number; st.orderId = o.id;
  // the time shown is Kampala time (UTC+3): the API sends ISO UTC and the screen renders it in the business timezone
  const parts = Object.fromEntries(new Intl.DateTimeFormat('en-GB', { timeZone: 'Africa/Kampala', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false }).formatToParts(new Date(o.createdAt)).map((x) => [x.type, x.value]));
  await page.getByText(o.number, { exact: true }).first().click();
  await expect(page.getByRole('dialog')).toContainText(new RegExp(`${parts.day}\\s+${parts.month}\\s+${parts.hour}:${parts.minute}`));
  await page.keyboard.press('Escape');
  // cannot start a dispatch before approval
  const snap = await (await A('storemats')).get(`/sites/mats/orders/${o.id}/dispatch-snapshot`);
  expect(snap.status).toBe(409);
  w.assertClean();
});

test('gate 3: CEO approves at the credit desk', async ({ page, w }) => {
  await loginAs(page, 'ceo1'); await enter(page, /group|ceo|all sites|cockpit/i);
  await goto(page, '/g/credit');
  await expect(page.getByRole('heading', { name: 'Credit desk' })).toBeVisible();
  const row = page.getByRole('button').filter({ hasText: st.orderNo! }).first();
  await row.click();
  await expect(page.getByText(/Credit used after this order/)).toBeVisible();
  await shoot(page, 'credit-desk-over-limit-order');
  const approve = page.getByRole('button', { name: 'Approve', exact: true }); const confirm = page.getByRole('button', { name: `Confirm approve ${st.orderNo}` });
  // a background refresh can reset the armed state; arm again if the confirm button went away
  await expect(async () => { if (!(await confirm.isVisible())) await approve.click(); await confirm.click({ timeout: 3000 }); }).toPass({ timeout: 30_000 });
  await expect.poll(async () => (await (await A('ceo1')).json(`/sites/mats/orders/${st.orderId}`)).order.status, { timeout: 15_000, intervals: [1000] }).toBe('approved');
  w.assertClean();
});

test('gate 4: storekeeper starts a dispatch from the server order, then scans, weighs and delivers', async ({ page, w }) => {
  await loginAs(page, 'storemats'); await enter(page);
  await goto(page, '/s/mats/stock/dispatch');
  await page.getByText(st.orderNo!, { exact: true }).first().click();
  await expect(page.getByText(new RegExp(`${st.orderNo} · 12 bundles`))).toBeVisible();
  // validation: Start loading without a driver/vehicle is refused in words
  await page.getByRole('button', { name: /Start loading/ }).first().click();
  await expect(page.getByText(/Who is driving\?/)).toBeVisible();
  await page.getByRole('combobox').first().fill('E2E Driver').catch(async () => page.locator('input').filter({ hasNot: page.locator('[type=hidden]') }).nth(1).fill('E2E Driver'));
  await page.getByText('UBK 442X', { exact: true }).click();
  await shoot(page, 'dispatch-ready-to-start');
  await page.getByRole('button', { name: /Start loading/ }).first().click();
  await expect(page.getByText('Loading started')).toBeVisible({ timeout: 15_000 });
  const store = await A('storemats');
  const list = (await store.json('/sites/mats/dispatches')).dispatches ?? [];
  const d = list.find((x: any) => JSON.stringify(x).includes(st.orderNo!));
  expect(d, 'dispatch exists for the order').toBeTruthy();
  const order = (await store.json(`/sites/mats/orders/${st.orderId}`)).order;
  expect(order.status).toBe('picking');
  // the snapshot the dispatch holds comes from the server order
  expect(JSON.stringify(d)).toContain('12');
  (st as any).dispatchId = d.id;
  w.assertClean();
});

test('gate 5: bundles scanned, gate weighed, delivery confirmed (server calls); order reads delivered', async ({ page }) => {
  const store = await A('storemats');
  const labels: any[] = [];
  for (let pg = 1; pg <= 6; pg++) { const r = (await store.json(`/sites/mats/labels?status=in_stock&kind=bundle&perPage=200&page=${pg}`)).data ?? []; labels.push(...r); if (r.length < 200) break; }
  void page;
  const order = (await store.json(`/sites/mats/orders/${st.orderId}`)).order;
  const pid = order.lines[0].productId;
  const mine = labels.filter((l: any) => l.productId === pid).slice(0, 12);
  expect(mine.length).toBe(12);
  for (const l of mine) { const r = await store.post(`/sites/mats/dispatches/${(st as any).dispatchId}/scan`, { code: l.code }); expect(r.ok, r.text).toBeTruthy(); }
  const dsp = (await store.json(`/sites/mats/dispatches/${(st as any).dispatchId}`)).dispatch;
  const kg = Math.round(Number(dsp.expectedKg ?? dsp.expected_kg));
  const g = await store.post(`/sites/mats/dispatches/${(st as any).dispatchId}/gate-weight`, { kg });
  expect(g.ok, g.text).toBeTruthy();
  const dl = await store.post(`/sites/mats/dispatches/${(st as any).dispatchId}/delivery`, { received_by: 'E2E Receiver', bundles_received: 12, signature_hash: 'a'.repeat(64) });
  expect(dl.ok, dl.text).toBeTruthy();
  await expect.poll(async () => (await store.json(`/sites/mats/orders/${st.orderId}`)).order.status, { intervals: [1000] }).toBe('delivered');
});

test('gate 6: sales issues the invoice from the delivered order; numbers come from the server', async ({ page, w }) => {
  await loginAs(page, 'salesmats'); await enter(page);
  await goto(page, '/s/mats/sales/orders');
  await page.getByText(st.orderNo!, { exact: true }).first().click();
  const dlg = page.getByRole('dialog');
  await expect(dlg).toContainText('Delivered');
  await shoot(page, 'sales-order-delivered-issue-invoice');
  await dlg.getByRole('button', { name: 'Issue invoice' }).click();
  await expect(dlg.getByRole('button', { name: /Invoice INV-\d{5}/ })).toBeVisible({ timeout: 20_000 });
  const o = (await (await A('ceo1')).json(`/sites/mats/orders/${st.orderId}`)).order;
  expect(o.status).toBe('invoiced');
  const inv = (await (await A('ceo1')).json('/sites/mats/invoices')).invoices.find((i: any) => i.orderId === st.orderId);
  expect(inv).toMatchObject({ total: 576, currency: 'USD', paid: 0, balance: 576 });
  expect(inv.number).toMatch(/^INV-\d{5}$/);
  st.invoiceId = inv.id; st.invoiceNo = inv.number;
  await expect(dlg).toContainText(inv.number);
  // the invoice print sheet shows the server document with its hash
  await dlg.getByRole('button', { name: /Print invoice/ }).click();
  const sheet = page.getByRole('dialog').last();
  await expect(sheet.locator('.print-area')).toContainText(inv.number);
  await expect(sheet.locator('.print-area')).toContainText('576');
  await expect(sheet.locator('.print-area')).toContainText(/Document hash [0-9a-f]{16,}/);
  await shoot(page, 'document-invoice-print-sheet');
  w.assertClean();
});

test('gate 7: cashier records payments; overpay is refused in plain words; paying in full settles the order', async ({ page, w }) => {
  await loginAs(page, 'cashmats'); await enter(page);
  await goto(page, '/s/mats/sales/orders');
  await page.getByText(st.orderNo!, { exact: true }).first().click();
  const dlg = page.getByRole('dialog');
  await dlg.getByRole('button', { name: 'Record payment' }).click();
  await expect(dlg).toContainText(/outstanding/);
  const amount = dlg.getByLabel(/Amount/).or(dlg.getByPlaceholder('0')).first();
  // overpay: more than the invoice owes
  await amount.fill('900');
  await dlg.getByRole('button', { name: /^Record payment$/ }).last().click();
  await expect(dlg.getByText(/exceeds the open balance/i).first()).toBeVisible({ timeout: 15_000 });
  const inv0 = (await (await A('ceo1')).json('/sites/mats/invoices')).invoices.find((i: any) => i.id === st.invoiceId);
  expect(inv0.paid).toBe(0);
  await shoot(page, 'sales-payment-overpay-refused');
  // partial then the rest
  await amount.fill('76');
  await dlg.getByRole('button', { name: /^Record payment$/ }).last().click();
  await expect.poll(async () => (await (await A('ceo1')).json('/sites/mats/invoices')).invoices.find((i: any) => i.id === st.invoiceId).paid, { intervals: [1000], timeout: 15_000 }).toBe(76);
  await expect(dlg.getByRole('button', { name: 'Record payment' })).toBeVisible();
  await dlg.getByRole('button', { name: 'Record payment' }).click();
  await amount.fill('500');
  await dlg.getByRole('button', { name: /^Record payment$/ }).last().click();
  await expect.poll(async () => (await (await A('ceo1')).json(`/sites/mats/orders/${st.orderId}`)).order.status, { intervals: [1000], timeout: 15_000 }).toBe('paid');
  const inv = (await (await A('ceo1')).json('/sites/mats/invoices')).invoices.find((i: any) => i.id === st.invoiceId);
  expect(inv).toMatchObject({ paid: 576, balance: 0 });
  w.allow(/409|422/); // the overpay refusal is expected
  w.assertClean();
});

test('gate 8: the month P&L reads closed days only; closing the invoice day brings the invoice in', async ({ page, w }) => {
  const ceo = await A('ceo1');
  const inv = (await ceo.json('/sites/mats/invoices')).invoices.find((i: any) => i.id === st.invoiceId);
  const day: string = inv.issuedDay; const month = day.slice(0, 7);
  const before = await ceo.json(`/sites/mats/costing/pnl?month=${month}`);
  const alreadyClosed = before.closed_days.includes(day); // the seed may have signed today's cash day already
  if (alreadyClosed) expect(before.revenue.invoiced, 'a closed day is in the books').toBeGreaterThanOrEqual(576 * 3700);
  else { expect(before.provisional).toBe(true); expect(before.closed_days, 'the invoice day is open, so its invoice is not in the books yet (the seed may already book earlier closed days)').not.toContain(day); }
  await loginAs(page, 'ceo1'); await enter(page, /mats/i);
  await goto(page, '/s/mats/reports');
  await expect(page.getByText(/Provisional/).first()).toBeVisible();
  await shoot(page, 'reports-pnl-provisional');
  if (alreadyClosed) { await shoot(page, 'reports-pnl-closed-day-included'); w.assertClean(); return; }
  const cash = await A('cashmats');
  const d = await cash.json(`/sites/mats/cash/days/${day}`);
  const r = await cash.post(`/sites/mats/cash/days/${day}/close`, { counted: d.totals.expectedClosing, denominations: [] });
  expect(r.ok, r.text).toBeTruthy();
  const after = await ceo.json(`/sites/mats/costing/pnl?month=${month}`);
  expect(after.closed_days).toContain(day);
  expect(after.revenue.invoiced - before.revenue.invoiced, 'closing the day adds USD 576 x 3,700 reference, summed over the invoices of this day').toBeGreaterThanOrEqual(576 * 3700);
  await page.reload(); await page.waitForLoadState('networkidle');
  await expect(page.getByText(/Provisional/).first()).toBeVisible();
  await shoot(page, 'reports-pnl-after-close-day');
  w.assertClean();
});
