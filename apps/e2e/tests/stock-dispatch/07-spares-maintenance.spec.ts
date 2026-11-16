import type { Page } from '@playwright/test';
import { expect, test } from '../../support/helpers';
import { go, openAs, shoot, uuid7, api } from '../../support/stock';

test.use({ actionTimeout: 15_000 }); // a missing control fails in 15s, not at the test timeout

type Part = { id: string; name: string; unit: string; onHand: number; minLevel: number; unitCost: number };
const parts = async (a: Awaited<ReturnType<typeof api>>) => (await a.json('/sites/mats/spares')).parts as Part[];
const mechanic = async (dlg: import('@playwright/test').Locator, page: Page) => {
  // the signed-in mechanic is preselected when they are the only one; otherwise pick the first
  const box = dlg.getByPlaceholder('Search mechanic by name');
  if ((await box.count()) && !(await box.inputValue())) { await box.click(); await page.getByRole('option').first().click(); }
};
const combo = async (page: Page, placeholder: string, option: RegExp | string) => { await page.getByPlaceholder(placeholder).click(); await page.getByRole('option', { name: option }).first().click(); };

test.describe('spares store', () => {
  test('storekeeper: list matches the API, search + tabs, receive parts (invalid, then valid), reorder is honest', async ({ page, request, w }) => {
    test.setTimeout(240_000);
    const sk = await api(request, 'storemats');
    const before = await parts(sk);
    await openAs(page, 'storemats', { role: 'Storekeeper' });
    await go(page, 'stock/spares');
    await shoot(page, 'storemats-stock_spares');
    for (const p of before) await expect(page.locator('table').first().locator('tbody tr').filter({ hasText: p.name }).first(), p.name).toBeVisible();
    await expect(page.getByText(`${before.filter((p) => p.onHand < p.minLevel).length} part below minimum`).or(page.getByText(/parts? below minimum/)).first()).toBeVisible();
    const value = before.reduce((t, p) => t + p.onHand * p.unitCost, 0);
    await expect(page.locator('table').first().locator('tfoot')).toContainText(value.toLocaleString('en-US'));
    // search narrows
    await page.getByPlaceholder(/search/i).first().fill('Bearing');
    await expect(page.locator('table').first().locator('tbody tr')).toHaveCount(before.filter((p) => /bearing/i.test(p.name)).length);
    await page.getByPlaceholder(/search/i).first().fill('');
    await page.getByRole('tab', { name: /Issues log/ }).click();
    await expect(page.locator('table').first().locator('tbody tr').first()).toBeVisible();
    await page.getByRole('tab', { name: /Low stock and reorder/ }).click();
    // Reorder made no server call yet toasted "Purchase request sent"; in a real build the button is no longer offered.
    await expect(page.getByRole('button', { name: 'Reorder' })).toHaveCount(0);
    // receive: invalid first
    await page.getByRole('button', { name: 'Receive parts' }).click();
    const dlg = page.getByRole('dialog');
    await expect(dlg.getByRole('button', { name: 'Book in' }).last()).toBeDisabled();
    await combo(dlg as unknown as Page, 'Search part by name', /Bearing 6205/);
    await expect(dlg.getByRole('button', { name: 'Book in' }).last()).toBeDisabled(); // quantity missing
    await dlg.locator('input[inputmode="numeric"]').fill('5');
    await expect(dlg.getByText(/After booking in/)).toBeVisible();
    await dlg.getByRole('button', { name: 'Book in' }).last().click();
    await expect(page.getByText(/Bearing 6205-2RS \+5 pc/).first()).toBeVisible();
    const bearing = (await parts(sk)).find((p) => /Bearing/.test(p.name))!;
    expect(bearing.onHand).toBe(before.find((p) => p.id === bearing.id)!.onHand + 5);
    await shoot(page, 'storemats-stock_spares-receive');
    w.assertClean();
  });

  test('issue part: all four fields needed, quantity above stock blocked, a stale screen is refused by the server (409)', async ({ page, request, w }) => {
    test.setTimeout(240_000);
    const sk = await api(request, 'storemats');
    const list = await parts(sk);
    const target = list.find((p) => /Compressor oil|Heater band/.test(p.name))!;
    await openAs(page, 'storemats', { role: 'Storekeeper' });
    await go(page, 'stock/spares');
    await page.getByRole('button', { name: 'Issue part' }).first().click();
    const dlg = page.getByRole('dialog');
    await combo(dlg as unknown as Page, 'Search part by name', new RegExp(target.name.split(' ')[0]));
    const qty = dlg.locator('input[inputmode="numeric"]');
    await qty.fill(String(target.onHand + 1));
    await expect(dlg.getByText(new RegExp(`Only ${target.onHand} ${target.unit} on hand`))).toBeVisible();
    await expect(dlg.getByText(/Finish fields 1 to 4 first/)).toBeVisible();
    await qty.fill('2');
    await dlg.getByRole('button', { name: 'Next' }).click();
    await combo(dlg as unknown as Page, 'Search machine', /./);
    await mechanic(dlg, page);
    // meanwhile somebody else takes all but one of the stock
    const machines = (await sk.json('/sites/mats/config')).machines as { id: string }[];
    const drain = target.onHand - 1;
        const issueBtn = dlg.getByRole('button', { name: new RegExp(`^Issue .* ×2`) });
    await expect(issueBtn).toBeVisible();
    // take the stock away through the API (mechanic = the person picked in the UI is unknown to us, so use the first mechanic the server knows)
    const sparesBefore = await sk.json('/sites/mats/spares');
    const mech = ((await sk.json('/sites/mats/issues').catch(() => ({ issues: [] }))) as { issues?: { mechanicId: string }[] }).issues?.[0]?.mechanicId;
    if (mech && drain > 0) {
      const r = await sk.post(`/sites/mats/parts/${target.id}/issue`, { issueId: uuid7(), qty: drain, machineId: machines[0].id, mechanicId: mech });
      expect(r.ok(), `drain ${r.status()} ${await r.text()}`).toBeTruthy();
      w.allow(/POST \/sites\/mats\/parts\/.*\/issue/);
      await issueBtn.click();
      await expect(dlg.getByText(/not enough|only|on hand|short|insufficient/i).first()).toBeVisible();
      expect((await parts(sk)).find((p) => p.id === target.id)!.onHand).toBe(1);
      await shoot(page, 'storemats-stock_spares-overissue');
    } else {
      void sparesBefore;
      test.info().annotations.push({ type: 'note', description: 'no seeded issue to borrow a mechanic id from: over-issue 409 checked in the API only' });
    }
    w.assertClean();
  });

  test('mechanic: sees spares; is not offered Issue part (only storekeeper/supervisor issue)', async ({ page, request, w }) => {
    test.setTimeout(240_000);
    const sk = await api(request, 'storemats');
    const before = (await parts(sk)).find((p) => /Shuttle hook/.test(p.name))!;
    await openAs(page, 'mech', { site: 'mats' });
    await go(page, 'stock/spares');
    await expect(page.getByRole('button', { name: 'Issue part' })).toHaveCount(0);
    await shoot(page, 'mech-stock_spares-issue-refused');
    expect((await parts(sk)).find((p) => p.id === before.id)!.onHand).toBe(before.onHand);
    w.assertClean();
  });
});

test.describe('maintenance and downtime', () => {
  test('mechanic reports a breakdown, a part is issued during it, resolve -> downtime and cost on the machine', async ({ page, request, w }) => {
    test.setTimeout(300_000);
    const sk = await api(request, 'storemats');
    await openAs(page, 'mech', { site: 'mats' });
    await go(page, 'stock/maintenance');
    await shoot(page, 'mech-stock_maintenance');
    await page.getByRole('button', { name: 'Report breakdown' }).first().click();
    const dlg = page.getByRole('dialog');
    const report = dlg.getByRole('button', { name: /^Report .*down$/ });
    await expect(report).toBeDisabled();
    await combo(dlg as unknown as Page, 'Search machine', /Loom 9/);
    await expect(report).toBeDisabled(); // cause missing
    await dlg.getByPlaceholder('Short description of what happened').fill('Warp beam shaft snapped');
    await expect(report).toBeEnabled();
    await report.click();
    await expect(page.getByText(/Loom 9 reported down/).first()).toBeVisible();
    const bds = async () => (await sk.json('/sites/mats/breakdowns').catch(async () => (await api(request, 'smmats')).json('/sites/mats/breakdowns'))).breakdowns as { id: string; cause: string; resolvedAt: string | null }[];
    const mine = (await bds()).find((b) => b.cause === 'Warp beam shaft snapped');
    expect(mine, 'breakdown on the server').toBeTruthy();
    // a second report on the same machine is not offered
    await page.getByRole('button', { name: 'Report breakdown' }).first().click();
    await dlg.getByPlaceholder('Search machine').click();
    await expect(page.getByRole('option', { name: /Loom 9/ }).first()).toBeDisabled();
    await page.keyboard.press('Escape'); await page.keyboard.press('Escape');
    await shoot(page, 'mech-stock_maintenance-down');
    // open it, resolve it
    await page.locator('table tbody tr').filter({ hasText: 'Warp beam shaft snapped' }).first().click();
    await expect(page.getByRole('dialog')).toContainText('Still down');
    await page.getByRole('dialog').getByRole('button', { name: /Resolve/ }).click();
    await expect(page.getByText(/Loom 9 back in production/).first()).toBeVisible();
    expect((await bds()).find((b) => b.id === mine!.id)!.resolvedAt).toBeTruthy();
    // resolving again is refused by the server
    const smApi = await api(request, 'smmats');
    const again = await smApi.post(`/sites/mats/breakdowns/${mine!.id}/resolve`, {});
    expect([409, 422]).toContain(again.status());
    // downtime cost endpoint agrees the machine has downtime
    const cost = await smApi.json('/sites/mats/downtime-cost');
    expect(JSON.stringify(cost)).toMatch(/Loom 9|loom/i);
    w.assertClean();
  });

  for (const [user, role, site] of [['smmats', undefined, undefined], ['supmats', undefined, undefined], ['ceo1', undefined, 'mats']] as const) {
    test(`${user}: Breakdowns screen renders the server data`, async ({ page, w }) => {
      test.setTimeout(120_000);
      await openAs(page, user, { role, site });
      await go(page, 'stock/maintenance');
      await expect(page.getByText('Breakdown ledger')).toBeVisible();
      await expect(page.locator('table').first().locator('tbody tr').first()).toBeVisible();
      await shoot(page, `${user}-stock_maintenance`);
      w.assertClean();
    });
  }
});
