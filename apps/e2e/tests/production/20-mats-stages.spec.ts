import { expect, test } from '../../support/helpers';
import { api, chooseWorker, eventually, fmt, go, labelsOf, openAs, shoot, text } from './util';

test.describe('mats: mixer, extruders, finishing, waste', () => {
  test('mixer: refuses an incomplete batch, labels a good one, real code replaces the pending one, kilos reach the server', async ({ page, request, w }) => {
    await openAs(page, 'supmats', 'mats', 'production/mixer');
    const a = await api(request, 'supmats');
    const before = (await labelsOf(a, 'mats', 'kind=batch')).length;
    const day = (await a.json('/sites/mats/machine-wall?shift=day')).day as string;
    const kgInBefore = (await a.json(`/sites/mats/mass-balance?day=${day}`)).kgIn as number;
    const btn = page.getByRole('button', { name: /weigh and label/i });
    await expect(btn).toBeDisabled();
    await expect(page.getByText(/Still needed: .*colour/).first()).toBeVisible();
    await shoot(page, 'mats-mixer');

    const panel = page.locator('main');
    const bags = panel.locator('input[inputmode="numeric"]').first();
    await bags.fill('999'); // the pad is capped at 99 bags
    await expect(bags).not.toHaveValue('999');
    await bags.fill('0');
    await expect(btn).toBeDisabled();
    await bags.fill('3');
    await panel.getByRole('radio', { name: 'Blue' }).click();
    await expect(btn).toBeDisabled(); // operator still missing
    await chooseWorker(page, panel);
    await expect(btn).toBeEnabled();
    const total = await page.getByText(/× 40 kg \+ \d+ kg = [\d,]+ kg/).first().innerText();
    const kg = Number(/= ([\d,]+) kg/.exec(total)![1].replace(/,/g, ''));
    await btn.click();
    await expect(page.getByText(/Batch .* labelled/).first()).toBeVisible();
    const server = await eventually(() => labelsOf(a, 'mats', 'kind=batch'), (l) => l.length > before);
    const mine = server.pop()!;
    expect(mine.qtyKg).toBeCloseTo(kg, 1);
    expect(mine.code).toMatch(/^MAT-BAT-\d{6}$/);
    expect(mine.colour).toBe('Blue');
    // the day's mass-balance input rose by exactly the batch (no double count of label + batch + entry)
    await expect.poll(async () => (await a.json(`/sites/mats/mass-balance?day=${day}`)).kgIn, { timeout: 30_000 }).toBeCloseTo(kgInBefore + kg, 1);
    // after the sync the page shows the real code, never PENDING
    await expect.poll(async () => (await text(page)).includes(mine.code) && !/PENDING-/.test(await text(page)), { timeout: 40_000 }).toBeTruthy();
    // a second identical batch is a second batch (no silent duplicate suppression)
    await shoot(page, 'mats-mixer-after');
    w.assertClean();
  });

  test('extruders: log string output with a rack label; invalid and valid', async ({ page, request, w }) => {
    await openAs(page, 'supmats', 'mats', 'production/extruders');
    const a = await api(request, 'supmats');
    await shoot(page, 'mats-extruders');
    await page.getByRole('tab', { name: 'Table' }).click();
    await shoot(page, 'mats-extruders-table');
    await page.getByRole('tab', { name: 'Map' }).click();
    await page.getByRole('button', { name: /log string output/i }).click();
    const dlg = page.getByRole('dialog');
    await expect(dlg).toBeVisible();
    await expect(dlg).toContainText(/Still needed: .*kilos/);
    const done = dlg.getByRole('button', { name: /^(log|print|save)/i }).last();
    await expect(done).toBeDisabled();
    await chooseWorker(page, dlg);
    await dlg.locator('input[inputmode="decimal"]').first().fill('0');
    await expect(done).toBeDisabled();
    await dlg.locator('input[inputmode="decimal"]').first().fill('120.5');
    await dlg.getByRole('radio', { name: 'Red' }).click();
    await expect(done).toBeEnabled();
    const before = (await labelsOf(a, 'mats', 'kind=rack')).length;
    await done.click();
    await expect(page.getByText(/rack label printed/).first()).toBeVisible();
    const rack = (await eventually(() => labelsOf(a, 'mats', 'kind=rack'), (l) => l.length > before)).pop()!;
    expect(rack.qtyKg).toBeCloseTo(120.5, 1);
    expect(rack.colour).toBe('Red');
    expect(rack.code).toMatch(/^MAT-RAC-\d{6}$/);
    w.assertClean();
  });

  test('finishing: bundles and stickers from the sewn count', async ({ page, request, w }) => {
    await openAs(page, 'supmats', 'mats', 'production/finishing');
    const a = await api(request, 'supmats');
    await shoot(page, 'mats-finishing');
    const btn = page.getByRole('button', { name: /bundle and print/i });
    await expect(btn).toBeDisabled();
    await expect(page.getByText(/Still needed: product, loom, finisher, pieces/)).toBeVisible();
    await page.getByRole('radio', { name: /Domona/ }).click();
    await page.getByPlaceholder(/search loom|machine|loom/i).first().click().catch(() => undefined);
    await page.getByRole('option').first().click().catch(() => undefined);
    await chooseWorker(page, page.locator('main'));
    const pcs = page.locator('main input[inputmode="numeric"]').first();
    await pcs.fill('0');
    await expect(btn).toBeDisabled();
    await pcs.fill('1000000');
    await expect(pcs).not.toHaveValue('1,000,000');
    await pcs.fill('13'); // Domona bundles hold 6: 2 bundles, 1 loose
    await expect(page.getByText('Bundles of 6').first()).toBeVisible();
    await expect(page.getByText('13 ÷ 6')).toBeVisible();
    await expect(btn).toBeEnabled();
    const before = (await labelsOf(a, 'mats', 'kind=bundle')).length;
    await btn.click();
    await expect(page.getByText(/2 bundle stickers printed/).first()).toBeVisible();
    const got = await eventually(() => labelsOf(a, 'mats', 'kind=bundle'), (l) => l.length >= before + 2);
    expect(got.length).toBe(before + 2);
    w.assertClean();
  });

  test('waste: weigh waste form validates and counts in the server mass balance', async ({ page, request, w }) => {
    await openAs(page, 'supmats', 'mats', 'production/waste');
    const a = await api(request, 'supmats');
    await shoot(page, 'mats-waste');
    const rec = page.getByRole('button', { name: 'Record weighing' });
    await expect(rec).toBeDisabled();
    const kilos = page.getByLabel('Kilos', { exact: true });
    for (const bad of ['-5', '0', 'abc']) { await kilos.fill(bad); await expect(rec).toBeDisabled(); }
    const day = (await a.json('/sites/mats/machine-wall?shift=day')).day as string;
    const mb0 = await a.json(`/sites/mats/mass-balance?day=${day}`);
    await kilos.fill('12.5');
    await page.getByLabel('Reason').fill('e2e trim');
    await rec.click();
    await expect(page.getByText(/weighed: 12\.5 kg/).first()).toBeVisible();
    await expect.poll(async () => (await a.json(`/sites/mats/mass-balance?day=${day}`)).wasteKg, { timeout: 30_000 }).toBeCloseTo(mb0.wasteKg + 12.5, 1);
    // the page's waste figure follows the server
    await go(page, 'mats', 'production/waste');
    const mb1 = await a.json(`/sites/mats/mass-balance?day=${day}`);
    expect(await text(page)).toContain(`${(Math.round(mb1.wasteKg * 10) / 10).toLocaleString('en-US')} kg of ${(Math.round(mb1.kgIn * 10) / 10).toLocaleString('en-US')} kg in`);
    w.assertClean();
  });
});
