import { expect, loginAs, test } from '../../support/helpers';
import { A, enter, fresh, goto, shoot } from './util';

const n = Date.now().toString().slice(-6);
const section = (page: any, name: string) => page.getByRole('button', { name: new RegExp(`^${name}`) }).first().click();
const cfg = async (site = 'mats') => (await A('ceo1')).json(`/sites/${site}/config`);

test('settings (site manager): every section opens and shows server config', async ({ page, w }) => {
  await loginAs(page, 'smmats'); await enter(page);
  await goto(page, '/s/mats/settings');
  await expect(page.getByRole('heading', { name: 'Settings', exact: true })).toBeVisible();
  const c = await cfg();
  await shoot(page, 'settings-master-data');
  await section(page, 'Company details'); await expect(page.getByText(/URA EFRIS/).first()).toBeVisible(); await shoot(page, 'settings-company');
  await section(page, 'Products and prices'); for (const p of c.products) await expect(page.getByText(p.internalName, { exact: true }).first()).toBeVisible(); await shoot(page, 'settings-products');
  await section(page, 'Machines and benchmarks'); await expect(page.getByText('Benchmarks').first()).toBeVisible(); await shoot(page, 'settings-machines');
  await section(page, 'Store zones'); for (const z of c.zones) await expect(page.locator(`input[value="${z.name}"]`).first()).toBeVisible(); await shoot(page, 'settings-zones');
  await section(page, 'Rules and limits'); await expect(page.locator(`input[value="${c.settings.gateTolerancePct}"]`).first()).toBeVisible(); await shoot(page, 'settings-rules');
  await section(page, 'People and roles'); await shoot(page, 'settings-people-roles');
  await section(page, 'Sites and process'); await shoot(page, 'settings-sites');
  w.assertClean();
});

test('settings: master data list add, rename, reorder, deactivate, reactivate; validation', async ({ page, w }) => {
  await loginAs(page, 'smmats'); await enter(page);
  await goto(page, '/s/mats/settings');
  await page.getByRole('button', { name: /^Brands/ }).click();
  const add = page.getByRole('button', { name: 'Add', exact: true });
  await expect(add).toBeDisabled();
  const a = `E2E Brand A ${n}`, b = `E2E Brand B ${n}`;
  for (const name of [a, b]) { await page.getByLabel('Name', { exact: true }).fill(name); await add.click(); await expect(page.getByText(name, { exact: true }).first()).toBeVisible({ timeout: 15_000 }); }
  const refs = async () => (await cfg()).refs.filter((r: any) => r.list === 'brand' || String(r.label).includes('E2E Brand'));
  let r = await refs(); expect(r.map((x: any) => x.label)).toEqual(expect.arrayContaining([a, b]));
  await shoot(page, 'settings-masterdata-added');
  // duplicate name: the server's wording is shown, nothing is added
  const before = (await refs()).length;
  await page.getByLabel('Name', { exact: true }).fill(a); await add.click();
  await page.waitForTimeout(1500);
  expect((await refs()).length, 'a duplicate name is refused or merged, never doubled').toBeLessThanOrEqual(before + 0);
  await page.getByLabel('Name', { exact: true }).fill('');
  // rename
  const rowA = page.locator('li,div,tr').filter({ hasText: a }).filter({ has: page.getByRole('button', { name: 'Edit' }) }).last();
  await rowA.getByRole('button', { name: 'Edit' }).click();
  await page.getByRole('textbox').filter({ hasNot: page.locator('[aria-label=Name]') }).last().fill(`${a} renamed`);
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText(`${a} renamed`)).toBeVisible({ timeout: 15_000 });
  // reorder: move B up above A
  const idx = async () => (await refs()).sort((x: any, y: any) => x.order - y.order).map((x: any) => x.label);
  const rowB = page.locator('li,div,tr').filter({ hasText: b }).filter({ has: page.getByRole('button', { name: 'Move up' }) }).last();
  await rowB.getByRole('button', { name: 'Move up' }).click();
  await expect.poll(async () => { const l = await idx(); return l.indexOf(b) < l.indexOf(`${a} renamed`); }, { intervals: [1000], timeout: 15_000 }).toBe(true);
  // deactivate / reactivate
  const rowB2 = page.locator('li,div,tr').filter({ hasText: b }).filter({ has: page.getByRole('button', { name: 'Deactivate' }) }).last();
  await rowB2.getByRole('button', { name: 'Deactivate' }).click();
  await expect.poll(async () => (await refs()).find((x: any) => x.label === b).active, { intervals: [1000], timeout: 15_000 }).toBe(false);
  await shoot(page, 'settings-masterdata-deactivated');
  await page.locator('li,div,tr').filter({ hasText: b }).filter({ has: page.getByRole('button', { name: 'Reactivate' }) }).last().getByRole('button', { name: 'Reactivate' }).click();
  await expect.poll(async () => (await refs()).find((x: any) => x.label === b).active, { intervals: [1000], timeout: 15_000 }).toBe(true);
  w.assertClean();
});

test('settings: products (add with prices, edit price, validation) and the order form uses the new price', async ({ page, w }) => {
  await loginAs(page, 'smmats'); await enter(page);
  await goto(page, '/s/mats/settings');
  await section(page, 'Products and prices');
  await page.getByRole('button', { name: 'New product' }).click();
  const dlg = page.getByRole('dialog');
  await expect(dlg.getByRole('button', { name: 'Save', exact: true })).toBeDisabled();
  const name = `E2E Mat ${n}`;
  await dlg.getByPlaceholder('The name used on the floor').fill(name);
  await dlg.getByPlaceholder('Not sold on this channel').first().fill('123456'); // local, UGX
  await shoot(page, 'settings-product-sheet');
  await dlg.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.getByText(name).first()).toBeVisible({ timeout: 15_000 });
  const p = (await cfg()).products.find((x: any) => x.internalName === name);
  expect(p).toBeTruthy();
  expect(JSON.stringify(p.prices)).toContain('123456');
  // change the price: the server's list is what an order is priced from
  await page.getByText(name).first().click();
  await dlg.getByPlaceholder('Not sold on this channel').first().fill('130000');
  await dlg.getByRole('button', { name: 'Save', exact: true }).click();
  await expect.poll(async () => JSON.stringify((await cfg()).products.find((x: any) => x.internalName === name).prices), { intervals: [1000], timeout: 15_000 }).toContain('130000');
  const sales = await A('salesmats'); const ceo = await A('ceo1');
  const owino = (await ceo.json('/sites/mats/clients')).clients.find((c: any) => c.name === 'Owino Market Traders');
  const o = (await sales.post('/sites/mats/orders', { client_id: owino.id, lines: [{ product_id: p.id, bundles: 2 }] })).json.order;
  expect(o.total).toBe(260000);
  w.assertClean();
});

test('settings: machines, zones and rules (site manager)', async ({ page, w }) => {
  await loginAs(page, 'smmats'); await enter(page);
  await goto(page, '/s/mats/settings');
  // machine
  await section(page, 'Machines and benchmarks');
  await page.getByRole('button', { name: 'New machine' }).click();
  const dlg = page.getByRole('dialog');
  await expect(dlg.getByRole('button', { name: 'Save', exact: true })).toBeDisabled();
  const code = `E2E Loom ${n}`;
  await dlg.getByPlaceholder('As it is known on the floor').fill(code);
  await dlg.getByRole('button', { name: 'Save', exact: true }).click();
  await expect.poll(async () => !!(await cfg()).machines.find((m: any) => m.code === code), { intervals: [1000], timeout: 15_000 }).toBe(true);
  // zone add + rename
  await section(page, 'Store zones');
  const zn = `E2E Zone ${n}`;
  await page.getByPlaceholder(/New zone/).fill(zn); await page.getByRole('button', { name: 'Add', exact: true }).click();
  await expect.poll(async () => !!(await cfg()).zones.find((z: any) => z.name === zn), { intervals: [1000], timeout: 15_000 }).toBe(true);
  const zi = page.locator(`input[value="${zn}"]`); await zi.fill(`${zn} B`); await zi.press('Enter');
  await expect.poll(async () => !!(await cfg()).zones.find((z: any) => z.name === `${zn} B`), { intervals: [1000], timeout: 15_000 }).toBe(true);
  // rules: gate tolerance
  await section(page, 'Rules and limits');
  const tol = page.getByText('Gate weight tolerance', { exact: true }).locator('xpath=ancestor::div[.//input][1]').locator('input').first(); await tol.fill('3'); await tol.press('Enter');
  await expect.poll(async () => (await cfg()).settings.gateTolerancePct, { intervals: [1000], timeout: 15_000 }).toBe(3);
  await tol.fill('2'); await tol.press('Enter');
  await expect.poll(async () => (await cfg()).settings.gateTolerancePct, { intervals: [1000], timeout: 15_000 }).toBe(2);
  w.assertClean();
});

test('settings: online-only. Offline a change is refused in words and nothing is saved', async ({ page, w }) => {
  await loginAs(page, 'smmats'); await enter(page);
  await goto(page, '/s/mats/settings');
  await page.getByRole('button', { name: /^Brands/ }).click();
  await page.context().setOffline(true);
  const name = `E2E Offline ${n}`;
  await page.getByLabel('Name', { exact: true }).fill(name); await page.getByRole('button', { name: 'Add', exact: true }).click();
  await expect(page.getByText('Not saved').first()).toBeVisible({ timeout: 15_000 });
  await shoot(page, 'settings-offline-refused');
  await page.context().setOffline(false);
  expect((await cfg()).refs.some((r: any) => r.label === name)).toBe(false);
  w.allow(/ERR_INTERNET_DISCONNECTED|Failed to fetch|net::/);
  w.assertClean();
});

test('settings: sales, cashier and supervisor have no Settings; group admin has the group settings', async ({ browser, w }) => {
  for (const u of ['salesmats', 'cashmats', 'supmats']) {
    const { ctx, page } = await fresh(browser);
    await loginAs(page, u); await enter(page);
    await expect(page.locator('nav[aria-label="Main"]').first().getByText('Settings', { exact: true })).toHaveCount(0);
    await ctx.close();
  }
  const { page } = await fresh(browser);
  await loginAs(page, 'admin'); await enter(page);
  await goto(page, '/g/settings');
  await expect(page.getByRole('heading', { name: 'Settings', exact: true })).toBeVisible();
  await shoot(page, 'settings-group-admin');
  w.assertClean();
});

test('settings: exchange reference and company profile are Group Admin only (group admin edits; CEO and site manager read)', async ({ browser, page, w }) => {
  await loginAs(page, 'admin'); await enter(page);
  await goto(page, '/g/settings');
  await section(page, 'Rules and limits');
  // fx
  const fx = page.locator('main input').last(); await expect(fx).toHaveValue(String(Number((await cfg()).fx)));
  // the server accepts the new rate (2xx). It is dated with the business day, so config.fx may still show a rate dated later by the seed.
  const setFx = async (v: string) => { await fx.fill(v); const [r] = await Promise.all([page.waitForResponse((x) => x.url().endsWith('/fx') && x.request().method() === 'POST'), page.getByRole('button', { name: 'Set', exact: true }).click()]); expect(r.status(), `POST /fx ${v}`).toBeLessThan(300); };
  await setFx('3750'); await shoot(page, 'settings-rules-fx-changed');
  // company profile
  await section(page, 'Company details');
  const addr = page.getByPlaceholder('Not provided yet').first(); await addr.fill(`Plot 1, E2E Road ${n}`); await addr.blur();
  await expect.poll(async () => (await cfg()).companyProfile.address, { intervals: [1000], timeout: 15_000 }).toContain('E2E Road');
  await shoot(page, 'settings-group-admin-company');
  w.assertClean();
  // the CEO reads every site but has no edit controls; the site manager cannot touch FX or company details
  for (const [u, portal] of [['ceo2', /mats/i], ['smmats', undefined]] as const) {
    const { ctx, page: p } = await fresh(browser);
    await loginAs(p, u); await enter(p, portal as RegExp | undefined);
    await goto(p, '/s/mats/settings');
    await section(p, 'Products and prices');
    await expect(p.getByRole('button', { name: 'New product' }), `${u} New product`).toHaveCount(u === 'smmats' ? 1 : 0);
    await section(p, 'Rules and limits');
    // The reference the server shows now: 3750 once the business day has flipped past the seed's rate, else the seed's rate (dated later).
    const curFx = String(Number((await cfg()).fx));
    await expect(p.locator(`input[value="${curFx}"]`).first(), `${u} FX input`).toBeDisabled();
    await section(p, 'Company details');
    await expect(p.getByPlaceholder('Not provided yet').first().or(p.locator('input[disabled]').first())).toBeDisabled();
    await shoot(p, `settings-readonly-${u}`);
    await ctx.close();
  }
});
