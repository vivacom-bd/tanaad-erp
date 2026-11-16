import type { Page } from '@playwright/test';
import { expect, goOffline, goOnline, test } from '../../support/helpers';
import { go, openAs, shoot, api } from '../../support/stock';

test.use({ actionTimeout: 15_000 }); // a missing control fails in 15s, not at the test timeout

type P = { id: string; reference: string; supplier: string; material: string; qtyKg: number; status: string; receivedKg: number | null; landed: unknown; costs: unknown[] };
const list = async (a: Awaited<ReturnType<typeof api>>) => (await a.json('/sites/mats/purchases')).purchases as P[];
const newPurchase = async (page: Page, o: { supplier: string; origin?: string; material?: RegExp; qty: string; price: string; fx?: string; costs?: [string, string][] }) => {
  await page.getByRole('button', { name: 'New purchase' }).first().click();
  const dlg = page.getByRole('dialog');
  await dlg.getByPlaceholder('Supplier name').fill(o.supplier);
  if (o.origin) await dlg.getByPlaceholder('Country of origin').fill(o.origin);
  await dlg.getByPlaceholder('Search materials').click();
  await page.getByRole('option', { name: o.material ?? /./ }).first().click();
  await dlg.getByPlaceholder('0').first().fill(o.qty);
  await dlg.getByPlaceholder('0').nth(1).fill(o.price);
  let i = 0;
  for (const [label, amount] of o.costs ?? []) {
    if (i > 0) await dlg.getByRole('button', { name: 'Add cost' }).click();
    i++;
    await dlg.getByLabel(`Extra cost ${i} name`).fill(label);
    await dlg.getByLabel(`Extra cost ${i} amount`).fill(amount);
    await dlg.getByRole('radiogroup', { name: `Extra cost ${i} currency` }).getByRole('radio', { name: 'UGX' }).click();
  }
  return dlg;
};

test.describe('purchases', () => {
  test('list renders the API; filter tabs and search; CEO and cashier read it', async ({ page, request, browser, w }) => {
    test.setTimeout(240_000);
    const sm = await api(request, 'smmats');
    const all = await list(sm);
    await openAs(page, 'smmats');
    await go(page, 'purchases');
    await shoot(page, 'smmats-purchases');
    const rows = page.locator('table tbody tr');
    await expect(rows).toHaveCount(all.length);
    for (const p of all) await expect(rows.filter({ hasText: p.reference }).first()).toContainText(p.supplier);
    await page.getByRole('tab', { name: /^Received/ }).click();
    await expect(rows).toHaveCount(all.filter((p) => p.status === 'received').length);
    await page.getByRole('tab', { name: /^All/ }).click();
    await page.getByPlaceholder('Search reference, supplier, material').fill('zzzz');
    await expect(page.getByText('No purchase matches this search or status.')).toBeVisible();
    await page.getByPlaceholder('Search reference, supplier, material').fill(all[0].reference);
    await expect(rows).toHaveCount(1);
    // received purchases carry their landed cost from the server
    const rec = all.find((p) => p.status === 'received')!;
    await page.getByPlaceholder('Search reference, supplier, material').fill(rec.reference);
    await rows.first().click();
    await expect(page.getByRole('dialog')).toContainText('Total landed');
    await expect(page.getByRole('dialog').getByRole('button', { name: /Receive/ })).toHaveCount(0); // already received
    await shoot(page, 'smmats-purchases-received');
    w.assertClean();
    for (const [user, site] of [['ceo1', 'mats'], ['cashmats', undefined], ['storemats', undefined]] as const) {
      const ctx = await browser.newContext(); const pg = await ctx.newPage();
      const errs: string[] = []; pg.on('response', (r) => { if (r.url().includes('/api/v1') && r.status() >= 400) errs.push(`${r.status()} ${r.url()}`); });
      await openAs(pg, user, { site: site as 'mats' | undefined, role: user === 'storemats' ? 'Storekeeper' : undefined });
      await go(pg, 'purchases');
      await expect(pg.locator('table tbody tr')).toHaveCount(all.length);
      await shoot(pg, `${user}-purchases`);
      expect(errs, user).toEqual([]);
      await ctx.close();
    }
  });

  test('record: validation, landed cost maths, PO reference from the server, edit, advance forward only, receive once', async ({ page, request, w }) => {
    test.setTimeout(300_000);
    const sm = await api(request, 'smmats');
    await openAs(page, 'smmats');
    await go(page, 'purchases');
    await page.getByRole('button', { name: 'New purchase' }).first().click();
    let dlg = page.getByRole('dialog');
    const save = dlg.getByRole('button', { name: 'Save purchase' });
    await expect(save).toBeDisabled();
    await expect(dlg.getByText(/Still needed: a supplier, a material, the kilos, the unit price/)).toBeVisible();
    await dlg.getByPlaceholder('Supplier name').fill('Zeta Polymers');
    await dlg.getByPlaceholder('0').first().fill('abc1000x'); // non-numerics stripped
    await expect(dlg.getByPlaceholder('0').first()).toHaveValue('1000');
    await expect(save).toBeDisabled();
    await page.keyboard.press('Escape');
    // 1000 kg at 1.00 USD (fx 3700) + 2,000,000 UGX freight = 5,700,000 -> 5,700 per kg
    dlg = await newPurchase(page, { supplier: 'Zeta Polymers', origin: 'Kenya', qty: '1000', price: '1', costs: [['Freight', '2000000']] });
    const fx = (await sm.json('/sites/mats/config')).fx;
    const rate = Number((fx as { usdUgx?: number; rate?: number })?.usdUgx ?? (fx as { rate?: number })?.rate ?? 3700);
    const expectPerKg = Math.round((1000 * 1 * rate + 2_000_000) / 1000);
    await expect(dlg.getByText(new RegExp(`${expectPerKg.toLocaleString('en-US')}.*per kg landed`))).toBeVisible();
    await dlg.getByRole('button', { name: 'Save purchase' }).click();
    await expect(page.getByText('Purchase recorded').first()).toBeVisible();
    // the server assigns the PO number (a provisional one is shown until it syncs)
    await expect.poll(async () => (await list(sm)).find((p) => p.supplier === 'Zeta Polymers')?.reference ?? '', { timeout: 30_000 }).toMatch(/^PO-\d{4}$/);
    const rec = (await list(sm)).find((p) => p.supplier === 'Zeta Polymers')!;
    expect(rec.status).toBe('ordered'); expect(rec.qtyKg).toBe(1000);
    await expect(page.getByRole('dialog')).toContainText(rec.reference, { timeout: 30_000 });
    // edit: change the kilos
    await page.getByRole('dialog').getByRole('button', { name: 'Edit' }).click();
    await page.getByRole('dialog').getByPlaceholder('0').first().fill('1200');
    await page.getByRole('dialog').getByRole('button', { name: 'Save purchase' }).click();
    await expect(page.getByText(`Purchase ${rec.reference} updated`).first()).toBeVisible();
    await expect.poll(async () => (await list(sm)).find((p) => p.id === rec.id)!.qtyKg, { timeout: 30_000 }).toBe(1200);
    // advance forward only: ordered -> in transit -> at border
    await expect(page.getByRole('dialog').getByRole('button', { name: 'Mark in transit' })).toBeVisible();
    await page.getByRole('dialog').getByRole('button', { name: 'Mark in transit' }).click();
    await expect.poll(async () => (await list(sm)).find((p) => p.id === rec.id)!.status, { timeout: 30_000 }).toBe('in_transit');
    await expect(page.getByRole('dialog').getByRole('button', { name: 'Mark in transit' })).toHaveCount(0);
    await page.getByRole('dialog').getByRole('button', { name: 'Mark at border' }).click();
    await expect.poll(async () => (await list(sm)).find((p) => p.id === rec.id)!.status, { timeout: 30_000 }).toBe('at_border');
    // the server refuses to go backwards
    const back = await sm.post(`/sites/mats/purchases/${rec.id}/change`, { status: 'ordered' });
    expect(back.status(), await back.text()).toBeGreaterThanOrEqual(400);
    expect((await list(sm)).find((p) => p.id === rec.id)!.status).toBe('at_border');
    // receive 1150 of 1200 (short): a warning toast, landed cost recomputed on the received kilos
    const recv = page.getByRole('dialog').getByLabel('Kg received');
    await recv.fill('1150');
    await expect(page.getByRole('dialog').getByText(/short/i).first()).toBeVisible();
    await page.getByRole('dialog').getByRole('button', { name: /^Receive/ }).click();
    await expect(page.getByText(new RegExp(`${rec.reference} received`)).first()).toBeVisible();
    await expect.poll(async () => (await list(sm)).find((p) => p.id === rec.id)!.receivedKg, { timeout: 30_000 }).toBe(1150);
    const done = (await list(sm)).find((p) => p.id === rec.id)!;
    expect(done.status).toBe('received'); expect(done.landed).toBeTruthy();
    // receive twice is a 409
    const twice = await sm.post(`/sites/mats/purchases/${rec.id}/receive`, { receivedKg: 1150, receivedOn: new Date().toISOString().slice(0, 10) });
    expect([409, 422]).toContain(twice.status());
    await shoot(page, 'smmats-purchases-recorded');
    w.assertClean();
  });

  test('OFFLINE: a purchase recorded offline shows a provisional PO reference and syncs with the real one', async ({ page, request, w }) => {
    test.setTimeout(240_000);
    const sm = await api(request, 'smmats');
    await openAs(page, 'smmats');
    await go(page, 'purchases');
    await goOffline(page);
    const dlg = await newPurchase(page, { supplier: 'Offline Resins', qty: '500', price: '2', costs: [['Haulage', '300000']] });
    await dlg.getByRole('button', { name: 'Save purchase' }).click();
    await expect(page.getByText(/Purchase recorded/).first()).toBeVisible();
    await expect(page.getByText(/PO number is assigned when it reaches the server/).first()).toBeVisible();
    await expect(page.getByRole('dialog')).toContainText(/PENDING|PO-[0-9A-F]{4,}|provisional/i);
    expect((await list(sm)).some((p) => p.supplier === 'Offline Resins')).toBe(false);
    await shoot(page, 'smmats-purchases-offline');
    await goOnline(page);
    await expect.poll(async () => (await list(sm)).find((p) => p.supplier === 'Offline Resins')?.reference ?? '', { timeout: 60_000 }).toMatch(/^PO-\d{4}$/);
    const real = (await list(sm)).find((p) => p.supplier === 'Offline Resins')!;
    await expect(page.getByText(real.reference).first()).toBeVisible({ timeout: 30_000 });
    w.allow(/Failed to fetch|net::ERR/);
    w.assertClean();
  });

  test('roles: cashier cannot write (server 403, no New purchase button); the CEO may (not refused by role)', async ({ page, request, w }) => {
    test.setTimeout(240_000);
    const body = { id: '0192f7a0-aaaa-7000-8000-000000000001', supplier: 'Nobody', material: 'x', qtyKg: 1, unitPrice: 1, currency: 'UGX' };
    const cashier = await api(request, 'cashmats');
    expect((await cashier.post('/sites/mats/purchases', body)).status()).toBe(403);
    const ceo = await api(request, 'ceo1');
    expect((await ceo.post('/sites/mats/purchases', body)).status()).not.toBe(403);
    await openAs(page, 'cashmats');
    await go(page, 'purchases');
    await expect(page.getByRole('heading').first()).toBeVisible();
    await expect(page.getByRole('button', { name: 'New purchase' })).toHaveCount(0);
    w.assertClean();
  });
});
