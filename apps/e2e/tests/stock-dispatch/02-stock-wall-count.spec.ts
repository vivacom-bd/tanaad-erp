import { expect, test } from '../../support/helpers';
import { go, openAs, shoot, api } from '../../support/stock';

test.use({ actionTimeout: 15_000 }); // a missing control fails in 15s, not at the test timeout

const num = (t: string) => Number(t.replace(/[^0-9.-]/g, ''));

test.describe('stock wall', () => {
  test('storekeeper: figures match the API, search / kind filter / product sheet / shortcuts work', async ({ page, request, w }) => {
    test.setTimeout(180_000);
    const sk = await api(request, 'storemats');
    const stock = (await sk.json('/sites/mats/stock')).stock as { product: { name: string }; bundles: number; kg: number }[];
    const total = stock.reduce((t, r) => t + r.bundles, 0);
    await openAs(page, 'storemats', { role: 'Storekeeper' });
    await go(page, 'stock');
    const head = page.locator('main h1').first().locator('xpath=ancestor::*[self::header or self::div][1]');
    await expect(page.getByText(new RegExp(`${total} bundles in ${stock.length} products`))).toBeVisible();
    // Every API product is a grid row with the same bundle count.
    const grid = page.locator('table').first();
    for (const r of stock) {
      const row = grid.locator('tbody tr').filter({ hasText: r.product.name }).first();
      await expect(row, r.product.name).toBeVisible();
      await expect(row.locator('td').nth(1)).toHaveText(String(r.bundles));
    }
    await expect(grid.locator('tfoot')).toContainText(String(total));
    // search narrows, clear restores
    const search = page.getByPlaceholder('Search product');
    await search.fill('zzz-nothing');
    await expect(page.getByText('No product matches')).toBeVisible();
    await page.getByRole('button', { name: 'Clear filters' }).click();
    await search.fill('Domona');
    await expect(grid.locator('tbody tr')).toHaveCount(1);
    await search.fill('');
    await expect(grid.locator('tbody tr')).toHaveCount(stock.length);
    // product sheet
    await grid.locator('tbody tr').filter({ hasText: 'Domona' }).first().click({ timeout: 15_000 });
    const sheet = page.getByRole('dialog');
    await expect(sheet).toContainText('Where it sits');
    await expect(sheet).toContainText('Newest stickers in stock');
    await shoot(page, 'storemats-stock-product-sheet');
    await sheet.getByRole('button', { name: /Count its main zone/ }).click();
    await expect(page).toHaveURL(/\/stock\/count$/);
    await go(page, 'stock');
    // store map: count this zone presets the zone
    await page.getByRole('button', { name: 'Count this zone' }).nth(1).click();
    await expect(page).toHaveURL(/\/stock\/count$/);
    await expect(page.getByRole('radio', { name: /Store A · back/ })).toBeChecked();
    // header shortcuts
    await go(page, 'stock');
    await page.getByRole('button', { name: /^Move/ }).first().click();
    await expect(page).toHaveURL(/\/stock\/movements$/);
    await go(page, 'stock');
    await page.getByRole('button', { name: /^Tag/ }).first().click();
    await expect(page).toHaveURL(/\/stock\/tagging$/);
    w.assertClean();
  });

  for (const [user, site] of [['smmats', 'mats'], ['salesmats', 'mats'], ['ceo1', 'mats']] as const) {
    test(`${user}: reads the same wall as the API`, async ({ page, request, w }) => {
      test.setTimeout(120_000);
      await openAs(page, user, { site });
      await go(page, 'stock');
      const a = await api(request, user);
      const res = await a.get('/sites/mats/stock');
      if (res.status() === 403) {
        // DEFECT (reported): the nav gives this role a Stock wall but the server refuses it; the screen then shows false zeros.
        test.info().annotations.push({ type: 'defect', description: `${user}: GET /sites/mats/stock is 403, wall shows 0 bundles / Never counted` });
        await expect(page.getByText(/ 0 bundles in 0 products/)).toBeVisible();
      } else {
        const stock = (await res.json()).stock as { bundles: number }[];
        const total = stock.reduce((t, r) => t + r.bundles, 0);
        await expect(page.getByText(new RegExp(`${total} bundles in`))).toBeVisible();
      }
      await shoot(page, `${user}-stock-wall`);
      w.assertClean();
    });
  }

  test('cross-site isolation: a Mats storekeeper cannot read Bags stock, dispatches or purchases', async ({ request }) => {
    const sk = await api(request, 'storemats');
    for (const p of ['stock', 'dispatches', 'purchases', 'spares', 'stock/movements']) expect((await sk.get(`/sites/bags/${p}`)).status(), p).toBe(403);
    const bags = await api(request, 'storebags');
    expect((await bags.get('/sites/mats/stock')).status()).toBe(403);
  });
});

// The app's "today" and the API's UTC day can differ around midnight (the box runs on UTC+6), so read three days.
type CountRow = { status: string; reopenReason: string | null; countedBundles: number; systemBundles: number; current: boolean; reason: string | null };
async function countsAround(a: Awaited<ReturnType<typeof api>>): Promise<CountRow[]> {
  const out: CountRow[] = [];
  for (const off of [-1, 0, 1]) { const d = new Date(Date.now() + off * 86_400_000).toISOString().slice(0, 10); out.push(...((await a.json(`/sites/mats/stock/count-variance?day=${d}`)).counts as CountRow[])); }
  return out;
}

async function addAndCount(page: import('@playwright/test').Page, zone: RegExp, product: RegExp, n: string) {
  await page.getByRole('radio', { name: zone }).click();
  await page.getByPlaceholder('Add a product to this count').click();
  await page.getByRole('option', { name: product }).click();
  await page.getByRole('textbox', { name: new RegExp(`Counted ${product.source}`) }).fill(n);
}

test.describe('stock count', () => {
  test('storekeeper: count, reason rule, second count 409 day_locked, no reopen button', async ({ page, request, w }) => {
    test.setTimeout(180_000);
    await openAs(page, 'storemats', { role: 'Storekeeper' });
    await go(page, 'stock/count');
    await shoot(page, 'storemats-stock_count-initial');
    const dom = page.getByRole('textbox', { name: 'Counted Domona' });
    await dom.fill('4abc'); // non-digits are stripped
    await expect(dom).toHaveValue('4');
    await page.getByRole('button', { name: 'Clear' }).click();
    await expect(dom).toHaveValue('');

    // A surprise product in an empty zone: a difference cannot be recorded without a reason of 3+ characters.
    await addAndCount(page, /Store A · front/, /Sajaada heavy/, '3');
    const rec = page.getByRole('button', { name: 'Record count' });
    await expect(page.getByLabel('Reason for the difference')).toBeVisible();
    await expect(rec).toBeDisabled();
    await page.getByLabel('Reason for the difference').fill('ab');
    await expect(rec).toBeDisabled();
    await page.getByLabel('Reason for the difference').fill('Found behind the pallets');
    await expect(rec).toBeEnabled();
    await rec.click();
    await expect(page.getByText('Just recorded')).toBeVisible();
    await expect(page.getByText(/Count recorded · 1 variance/).first()).toBeVisible();
    const sk = await api(request, 'storemats');
    const mine = (await countsAround(sk)).filter((c) => c.reason === 'Found behind the pallets');
    expect(mine).toHaveLength(1);
    expect(mine[0]).toMatchObject({ countedBundles: 3, systemBundles: 0, current: true });
    await shoot(page, 'storemats-stock_count-recorded');

    // Counting the same product and zone again today is a 409 day_locked, shown in plain words.
    w.allow(/409 POST \/sites\/mats\/stock\/counts/);
    await page.getByRole('radio', { name: /Store A · front/ }).click();
    await page.getByRole('textbox', { name: /Counted Sajaada heavy/ }).fill('3');
    await rec.click();
    const fail = page.getByText(/Sajaada heavy:/).first();
    await expect(fail).toBeVisible();
    await expect(fail.locator('xpath=..')).toContainText(/locked|already|counted/i);
    await expect(page.getByRole('button', { name: 'Reopen this count' })).toHaveCount(0); // only the site manager reopens
    await shoot(page, 'storemats-stock_count-locked');
    w.assertClean();
  });

  test('site manager: count, locked, reopen needs a reason, recount succeeds, audit trail has both', async ({ page, request, w }) => {
    test.setTimeout(180_000);
    await openAs(page, 'smmats');
    await go(page, 'stock/count');
    await addAndCount(page, /Store B/, /2\.7m/, '2');
    await page.getByLabel('Reason for the difference').fill('Pallet moved here last night');
    await page.getByRole('button', { name: 'Record count' }).click();
    await expect(page.getByText('Just recorded')).toBeVisible();

    w.allow(/409 POST \/sites\/mats\/stock\/counts/);
    await page.getByRole('radio', { name: /Store B/ }).click();
    await page.getByRole('textbox', { name: /Counted 2\.7m/ }).fill('1');
    await page.getByLabel('Reason for the difference').fill('Recount after a pallet left');
    await page.getByRole('button', { name: 'Record count' }).click();
    const reopen = page.getByRole('button', { name: 'Reopen this count' }).first();
    await expect(reopen).toBeVisible();
    await shoot(page, 'smmats-stock_count-locked');
    // reopen needs a reason of 3+ chars
    await page.getByLabel('Reason for the difference').fill('x');
    await expect(reopen).toBeDisabled();
    await page.getByLabel('Reason for the difference').fill('Recount after a pallet left');
    await reopen.click();
    await expect(page.getByText(/Count reopened/).first()).toBeVisible();
    await page.getByRole('button', { name: 'Record count' }).click();
    await expect(page.getByText(/Count recorded/).first()).toBeVisible();
    const sm = await api(request, 'smmats');
    const mineRows = async () => (await countsAround(sm)).filter((c) => c.reason === 'Pallet moved here last night' || c.reopenReason === 'Recount after a pallet left' || c.reason === 'Recount after a pallet left');
    await expect.poll(async () => (await mineRows()).some((r) => r.current && r.countedBundles === 1), { timeout: 20_000 }).toBe(true);
    expect((await mineRows()).some((r) => r.status === 'reopened')).toBeTruthy();
    await shoot(page, 'smmats-stock_count-reopened');
    w.assertClean();
  });
});
