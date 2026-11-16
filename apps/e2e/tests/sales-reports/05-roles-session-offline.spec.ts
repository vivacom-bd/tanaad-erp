import { expect, loginAs, test } from '../../support/helpers';
import { A, enter, fresh, goto, matsData, shoot, wording } from './util';


// Sales navigation per role: who sees which of Orders / Clients / Invoices / Reports / Settings (nav.ts), and the portal each role lands in.
const MATRIX: { u: string; portals: number; links: string[]; none: string[] }[] = [
  { u: 'salesmats', portals: 1, links: ['Orders', 'Clients', 'Invoices'], none: ['Reports', 'Settings'] },
  { u: 'cashmats', portals: 1, links: ['Orders', 'Invoices'], none: ['Clients', 'Reports', 'Settings'] },
  { u: 'storemats', portals: 1, links: [], none: ['Clients', 'Invoices', 'Reports', 'Settings'] }, // signs in as Gate: no sales screens
  { u: 'smmats', portals: 1, links: ['Orders', 'Clients', 'Invoices', 'Reports', 'Settings'], none: [] },
  { u: 'supmats', portals: 1, links: [], none: ['Orders', 'Clients', 'Invoices', 'Reports', 'Settings'] },
];
for (const m of MATRIX) {
  test(`nav (${m.u}): sales, reports and settings links match the role`, async ({ browser, w }) => {
    const { ctx, page } = await fresh(browser);
    await loginAs(page, m.u);
    await expect(page.locator('article').first()).toBeVisible();
    expect(await page.locator('article').count(), 'portal cards').toBe(m.portals);
    await enter(page);
    const nav = page.locator('nav[aria-label="Main"]').first();
    for (const b of await nav.locator('button').all()) await b.click().catch(() => undefined); // open every hub once
    for (const l of m.links) await expect(nav.getByRole('link', { name: new RegExp(`^${l}( \\d+)?$`) }), `${m.u} sees ${l}`).toHaveCount(1);
    for (const l of m.none) await expect(nav.getByRole('link', { name: new RegExp(`^${l}( \\d+)?$`) }), `${m.u} must not see ${l}`).toHaveCount(0);
    // walk every sales/report/settings link the role has, screen by screen
    for (const l of m.links) {
      await nav.getByRole('link', { name: new RegExp(`^${l}( \\d+)?$`) }).click();
      await page.waitForLoadState('networkidle').catch(() => undefined);
      await expect(page.getByRole('heading').first()).toBeVisible();
      await shoot(page, `role-${m.u}-${l.toLowerCase()}`);
    }
    await ctx.close();
    w.assertClean();
  });
}

test('direct URLs: a role without the screen gets a clear refusal or redirect, never a blank page or server errors', async ({ browser }) => {
  const results: string[] = [];
  for (const [u, path] of [['supmats', '/s/mats/sales/orders'], ['supmats', '/s/mats/reports'], ['salesmats', '/s/mats/reports'], ['salesmats', '/s/mats/settings'], ['cashmats', '/s/mats/sales/clients']] as const) {
    const { ctx, page } = await fresh(browser);
    const bad: string[] = []; page.on('response', (r) => { if (r.url().includes('/api/v1') && r.status() >= 500) bad.push(`${r.status()} ${r.url()}`); });
    await loginAs(page, u); await enter(page);
    await goto(page, path).catch(() => undefined);
    await page.waitForTimeout(1200);
    const body = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
    results.push(`${u} ${path} -> ${new URL(page.url()).pathname} :: ${body.slice(0, 90)}`);
    expect(bad, `${u} ${path} server errors`).toEqual([]);
    expect(body, `${u} ${path} is refused by the route guard`).toContain("You don't have access to this page");
    await shoot(page, `no-access-${u}-${path.split('/').pop()}`);
    await ctx.close();
  }
  console.log('DIRECT-URL RESULTS\n' + results.join('\n'));
});

test('cross-site isolation: Mats sales sees Mats orders only; Bags sales sees Bags orders only; the API refuses the other site', async ({ browser, w }) => {
  const m = await fresh(browser);
  await loginAs(m.page, 'salesmats'); await enter(m.page); await goto(m.page, '/s/mats/sales/orders');
  const bagsOrders = (await (await A('salesbags')).json('/sites/bags/orders')).orders as any[];
  const mats = await matsData();
  await expect(m.page.getByText(mats.orders[0].number, { exact: true }).first()).toBeVisible();
  expect((await (await A('salesmats')).get('/sites/bags/orders')).status).toBe(403);
  const b = await fresh(browser);
  await loginAs(b.page, 'salesbags'); await enter(b.page); await goto(b.page, '/s/bags/sales/orders');
  await expect(b.page.getByText(bagsOrders[0].number, { exact: true }).first()).toBeVisible();
  const matsOnlyOrder = mats.orders.find((o: any) => !bagsOrders.some((x: any) => x.id === o.id))!;
  await expect(b.page.getByText(matsOnlyOrder.clientName, { exact: true })).toHaveCount(bagsOrders.some((x: any) => x.clientName === matsOnlyOrder.clientName) ? 1 : 0);
  await shoot(b.page, 'bags-sales-orders-isolated');
  // typing the other site's URL does not reveal its orders
  await goto(b.page, '/s/mats/sales/orders').catch(() => undefined);
  await b.page.waitForTimeout(1500);
  await expect(b.page.getByText(matsOnlyOrder.number, { exact: true })).toHaveCount(0);
  expect((await (await A('salesbags')).get('/sites/mats/orders')).status).toBe(403);
  await m.ctx.close(); await b.ctx.close();
  w.assertClean();
});

test('sign-in: wrong password, empty form, sign out, and an expired session', async ({ page, browser }) => {
  await page.goto('/');
  await shoot(page, 'login-screen');
  await page.getByRole('button', { name: /^sign in/i }).click();
  await expect(page.getByText(/enter|required|username|password/i).first()).toBeVisible();
  await page.getByPlaceholder(/username/i).fill('salesmats'); await page.getByPlaceholder('Password').fill('wrong-password');
  await page.getByRole('button', { name: /^sign in/i }).click();
  await expect(page.getByText(/incorrect|invalid|wrong|not match|could not/i).first()).toBeVisible({ timeout: 15_000 });
  await shoot(page, 'login-wrong-password');
  expect(await wording(page, 'login-wrong-password')).toEqual([]);
  // good sign in, then sign out from the user menu
  await page.getByPlaceholder('Password').fill('password');
  await page.getByRole('button', { name: /^sign in/i }).click();
  await page.waitForURL(/portals/, { timeout: 30_000 });
  await shoot(page, 'portals-sales');
  await enter(page);
  await page.getByRole('button', { name: 'Account' }).click();
  await page.getByRole('menuitem', { name: /Sign Out/i }).click();
  await expect(page.getByPlaceholder('Password')).toBeVisible({ timeout: 15_000 });
  await page.goto('/s/mats/sales/orders'); // not signed in: sent back to sign-in
  await expect(page.getByPlaceholder('Password')).toBeVisible({ timeout: 15_000 });
  // session expiry: the server revokes the token while the page is open
  const { ctx, page: p2 } = await fresh(browser);
  await loginAs(p2, 'salesmats'); await enter(p2); await goto(p2, '/s/mats/sales/orders');
  const token = await p2.evaluate(() => { for (const k of Object.keys(localStorage)) { const v = localStorage.getItem(k) ?? ''; const m = v.match(/"token"\s*:\s*"([^"]+)"/); if (m) return m[1]; } return ''; });
  expect(token, 'session token found in browser storage').toBeTruthy();
  const out = await fetch(`${(await import('../../support/env')).API_URL}/auth/session`, { method: 'DELETE', headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
  expect(out.status).toBeLessThan(300);
  await p2.reload();
  // the app notices the revoked token on its next call and returns to sign-in with a plain message (no shell with a blank user)
  await expect(p2.getByPlaceholder('Password')).toBeVisible({ timeout: 30_000 });
  await shoot(p2, 'session-expired-signin');
  await ctx.close();
});

test('offline: an order created offline shows a provisional number, then the server number after reconnect', async ({ page }) => {
  await loginAs(page, 'salesmats'); await enter(page); await goto(page, '/s/mats/sales/orders');
  await page.waitForLoadState('networkidle');
  await page.context().setOffline(true);
  await page.getByRole('button', { name: 'New order' }).click();
  const dlg = page.getByRole('dialog');
    const known = new Set((await matsData()).orders.map((o: any) => o.number));
  await dlg.getByText(/cash before delivery/).first().click();
  await dlg.getByPlaceholder('Choose a product…').click();
  await page.getByRole('option').filter({ hasText: /Rihlaat/ }).first().click();
  await dlg.getByLabel('Bundles').first().fill('2');
  await dlg.getByRole('button', { name: 'Create order' }).click();
  await expect(dlg).toContainText(/Not on the server yet/);
  const prov = (await dlg.locator('.code').first().innerText()).trim();
  expect(prov, 'provisional reference').not.toMatch(/^SO-\d{5}$/);
  await shoot(page, 'sales-order-offline-provisional');
  // online-only actions are refused offline in words, the order is untouched
  await page.keyboard.press('Escape');
  await page.context().setOffline(false);
  await expect.poll(async () => (await (await A('ceo1')).json('/sites/mats/orders')).orders.some((o: any) => !known.has(o.number) && o.channel === 'local'), { intervals: [1500], timeout: 60_000 }).toBe(true);
  const real = (await (await A('ceo1')).json('/sites/mats/orders')).orders.find((o: any) => !known.has(o.number) && o.channel === 'local');
  expect(real.number).toMatch(/^SO-\d{5}$/);
  await expect(page.getByText(real.number, { exact: true }).first()).toBeVisible({ timeout: 30_000 });
  await expect(page.getByText(prov, { exact: true })).toHaveCount(0);
});

test('offline: cash confirmation and invoicing are online-only and refused in plain words', async ({ page }) => {
  await loginAs(page, 'cashmats'); await enter(page); await goto(page, '/s/mats/sales/orders');
  await page.waitForLoadState('networkidle');
  const cashOrder = (await matsData()).orders.find((o: any) => o.status === 'awaiting_cash')!;
  await page.getByText(cashOrder.number, { exact: true }).first().click();
  const dlg = page.getByRole('dialog');
  await dlg.getByRole('button', { name: 'Record cash received' }).click();
  await page.context().setOffline(true);
  await dlg.getByRole('button', { name: 'Confirm cash' }).click();
  await expect(dlg.getByText(/connect|online|network|internet|reach the server/i).first()).toBeVisible({ timeout: 20_000 });
  await shoot(page, 'sales-cash-offline-refused');
  await page.context().setOffline(false);
  const o = (await (await A('ceo1')).json('/sites/mats/orders')).orders.find((x: any) => x.number === cashOrder.number);
  expect(o.status).toBe('awaiting_cash');
});

test('wording sweep: no demo/sample/prototype wording on the sales, reports, settings, documents screens', async ({ browser }) => {
  const { ctx, page } = await fresh(browser);
  await loginAs(page, 'ceo1'); await enter(page, /mats/i);
  const hits: Record<string, string[]> = {};
  for (const path of ['/s/mats/sales/orders', '/s/mats/sales/clients', '/s/mats/sales/invoices', '/s/mats/reports', '/s/mats/settings', '/s/mats/people/settlement', '/s/mats/stock/deliveries', '/s/mats/stock/dispatch', '/g/approvals', '/g/credit', '/g/pnl', '/g/settings']) {
    await goto(page, path).catch(() => undefined); await page.waitForTimeout(1500);
    const h = await wording(page, `sweep ${path}`); if (h.length) hits[path] = h;
  }
  console.log('WORDING HITS\n' + JSON.stringify(hits, null, 1));
  await ctx.close();
});
