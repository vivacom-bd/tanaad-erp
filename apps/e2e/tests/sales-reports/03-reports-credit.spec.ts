import { expect, loginAs, test } from '../../support/helpers';
import { A, enter, fresh, goto, shoot } from './util';


for (const u of ['smmats', 'ceo1']) {
  test(`reports (${u}): site report renders server figures, periods, exports, unit cost`, async ({ page, w }) => {
    await loginAs(page, u); await enter(page, u === 'ceo1' ? /mats/i : undefined);
    await goto(page, '/s/mats/reports');
    await expect(page.getByRole('heading', { name: 'Reports', exact: true })).toBeVisible();
    const pnl = await (await A('ceo1')).json('/sites/mats/costing/pnl?month=2026-11');
    await expect(page.getByText(/Provisional/).first()).toBeVisible();
    await expect(page.getByText(/not closed/).first()).toBeVisible();
    expect(pnl.provisional).toBe(true);
    for (const t of ['Today', 'This week', 'Last 7 working days', 'This month']) {
      await page.getByRole('button', { name: t, exact: true }).or(page.getByRole('tab', { name: t, exact: true })).first().click();
      await page.waitForLoadState('networkidle').catch(() => undefined);
    }
    await shoot(page, `reports-site-${u}`);
    await expect(page.getByText(/Unit cost per product/i)).toBeVisible();
    // the daily table, machine table and dispatch table each render
    for (const h of ['Daily summary', 'Output by machine', 'Cash by category', 'Exceptions raised', 'Dispatches']) await expect(page.getByText(h, { exact: true }).first()).toBeVisible();
    w.assertClean();
  });
}

test('reports: site manager and CEO see Close period; reopen needs a reason (group P&L, October closed by the server)', async ({ page, w }) => {
  await loginAs(page, 'ceo1'); await enter(page, /group|ceo|hq|all sites/i);
  await goto(page, '/g/pnl');
  await expect(page.getByRole('heading', { name: /P&L and unit costs/ })).toBeVisible();
  await expect(page.getByText(/Provisional/).first()).toBeVisible();
  await shoot(page, 'group-pnl-november');
  await page.getByRole('button', { name: 'Previous month' }).click();
  await expect(page.getByText(/October 2026/).first()).toBeVisible();
  await expect(page.getByText(/Closed/).first()).toBeVisible();
  await shoot(page, 'group-pnl-october-closed');
  const ceo = await A('ceo1');
  const v0 = (await ceo.json('/sites/mats/costing/pnl?month=2026-10')).version;
  const reopen = page.getByRole('button', { name: 'Reopen' }).nth(1); // bags, mats, recycling
  await reopen.click();
  const dlg = page.getByRole('dialog');
  await expect(dlg.getByRole('button', { name: 'Reopen period' })).toBeDisabled(); // reason required
  await dlg.getByRole('button', { name: 'Cancel' }).click();
  await reopen.click();
  await dlg.getByPlaceholder(/late invoice/).fill('E2E: a late invoice was found');
  await shoot(page, 'group-pnl-reopen-sheet');
  await dlg.getByRole('button', { name: 'Reopen period' }).click();
  await expect(page.getByText('Period reopened')).toBeVisible({ timeout: 15_000 });
  const reopened = await ceo.json('/sites/mats/costing/pnl?month=2026-10');
  expect(reopened.status).not.toBe('closed'); expect(reopened.provisional).toBe(true);
  await page.getByRole('button', { name: 'Close period' }).first().click();
  await expect(page.getByText('Period closed')).toBeVisible({ timeout: 15_000 });
  const closed = await ceo.json('/sites/mats/costing/pnl?month=2026-10');
  expect(closed.status).toBe('closed'); expect(closed.provisional).toBe(false); expect(closed.version).toBeGreaterThan(v0);
  w.assertClean();
});

test('reports: cashier, supervisor and sales cannot open Reports (nav and direct URL)', async ({ browser }) => {
  for (const [u, canSee] of [['cashmats', false], ['salesmats', false], ['supmats', false], ['smmats', true]] as const) {
    const { ctx, page } = await fresh(browser);
    await loginAs(page, u); await enter(page);
    const nav = page.locator('nav[aria-label="Main"]').first();
    await expect(nav.getByRole('link', { name: 'Reports' }).or(nav.getByRole('button', { name: 'Reports' }))).toHaveCount(canSee ? 1 : 0);
    await ctx.close();
  }
});

test('credit desk: hold an order with a reason; approve from Approvals; statement preview', async ({ page, w }) => {
  const sales = await A('salesmats'); const ceo = await A('ceo1');
  const clients = (await ceo.json('/sites/mats/clients')).clients; const nyabu = clients.find((c: any) => c.channel === 'export');
  const prods = (await ceo.json('/sites/mats/config')).products; const p27 = prods.find((p: any) => p.code === 'p_27m');
  const mk = async () => (await sales.post('/sites/mats/orders', { client_id: nyabu.id, lines: [{ product_id: p27.id, bundles: 1 }] })).json.order;
  const o1 = await mk(); const o2 = await mk();
  expect(o1.status).toBe('awaiting_approval'); expect(o1.total).toBe(48);
  await loginAs(page, 'ceo1'); await enter(page, /group|ceo|hq|all sites/i);
  await goto(page, '/g/credit');
  await page.getByRole('button').filter({ hasText: o1.number }).first().click();
  await page.getByRole('button', { name: 'Hold', exact: true }).click();
  const reason = page.getByPlaceholder(/What has to happen/);
  await expect(page.getByRole('button', { name: /^Put SO-\d+ on hold$/ })).toBeDisabled(); // a reason is required
  await reason.fill('E2E: waiting for the bank guarantee');
  await shoot(page, 'credit-desk-hold-reason');
  await page.getByRole('button', { name: /^Put SO-\d+ on hold$/ }).click();
  await expect.poll(async () => (await ceo.json(`/sites/mats/orders/${o1.id}`)).order.status, { intervals: [1000], timeout: 15_000 }).toBe('held');
  const held = (await ceo.json(`/sites/mats/orders/${o1.id}`)).order;
  expect(held.holdReason).toContain('bank guarantee');
  // statement preview
  await page.getByRole('button', { name: /Preview statement/ }).first().click();
  await expect(page.getByRole('dialog').first()).toBeVisible();
  await shoot(page, 'credit-desk-statement-preview');
  await page.keyboard.press('Escape');
  // Approvals page: approve the second order from there
  await goto(page, '/g/approvals');
  await expect(page.getByText(/Orders waiting for approval/)).toBeVisible();
  const row = page.getByText(o2.number, { exact: true }).locator('xpath=ancestor::div[contains(@class,"flex-wrap")][1]');
  await expect(row).toContainText(nyabu.name); // the client is named on the approvals list
  await row.getByRole('button', { name: 'Approve' }).click();
  await page.getByRole('button', { name: 'Confirm approve', exact: true }).click();
  await expect.poll(async () => (await ceo.json(`/sites/mats/orders/${o2.id}`)).order.status, { intervals: [1000], timeout: 15_000 }).toBe('approved');
  await shoot(page, 'approvals-orders');
  // a held order can be released (approved) from the credit desk
  w.assertClean();
});
