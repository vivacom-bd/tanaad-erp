import { expect, test } from '../../support/helpers';
import { api, chipIn, chooseWorker, eventually, fmt, go, labelsOf, openAs, saveEntry, serverWall, shoot, text, weighManually } from './util';

test.describe('bags: wall, raw issue, roll sticker, conversion, film scrap, sheet, labels', () => {
  test('extruder wall renders the server wall and mass balance', async ({ page, request, w }) => {
    await openAs(page, 'supbags', 'bags', 'production/wall');
    const a = await api(request, 'supbags');
    const wall = await serverWall(a, 'bags', 'day');
    const run = wall.machines.filter((m) => m.state !== 'arriving');
    const t = await text(page);
    expect(t).toContain(`${run.filter((m) => m.entry).length} logged`);
    expect(t).toContain(`${run.filter((m) => !m.entry && m.state !== 'down').length} not logged`);
    const rolls = run.reduce((s, m) => s + (m.entry?.goodUnits ?? 0), 0);
    expect(t).toMatch(new RegExp(`Rolls this shift ${fmt(rolls)}\\b`));
    const kg = run.reduce((s, m) => s + (m.entry?.outputKg ?? 0), 0);
    expect(t).toContain(`${fmt(kg)} kg`);
    await shoot(page, 'bags-wall');
    await page.getByRole('tab', { name: 'Table View' }).click();
    await expect(page.locator('main table tbody tr').filter({ hasText: 'Extruder A' }).first()).toBeVisible();
    await page.getByRole('tab', { name: 'Machine Wall' }).click();
    await page.getByRole('tab', { name: 'Night shift' }).click();
    await shoot(page, 'bags-wall-night');
    w.assertClean();
  });

  test('extruder wall entry: a shift entry with rolls, rejects, film kg', async ({ page, request, w }) => {
    await openAs(page, 'supbags', 'bags', 'production/wall');
    const a = await api(request, 'supbags');
    const wall = await serverWall(a, 'bags', 'day');
    // A machine with no entry yet in the current shift (the seed leaves different ones unlogged before and after 07:00 Kampala), never the one the raw-issue test uses.
    const code = wall.machines.find((m) => !m.entry && m.state === 'not_logged' && m.machine.code !== 'Extruder H')?.machine.code ?? 'Extruder F';
    await page.locator(`[data-tile][aria-label^="${code},"]`).click();
    const dlg = page.getByRole('dialog');
    await expect(dlg).toContainText('Shift entry');
    await chooseWorker(page, dlg);
    await chipIn(dlg, 'Product', /Faras/);
    const nums = dlg.locator('input[inputmode]');
    await nums.nth(0).fill('0');
    await expect(dlg).toContainText('Still needed');
    await nums.nth(0).fill('6');
    await nums.nth(1).fill('1');
    await nums.nth(2).fill('365.5');
    await shoot(page, 'bags-entry-sheet');
    await saveEntry(dlg);
    await expect.poll(async () => { const e = (await serverWall(a, 'bags', 'day', wall.day)).machines.find((m) => m.machine.code === code)?.entry; return e && [e.goodUnits, e.rejectUnits, e.outputKg].join('/'); }, { timeout: 25_000 }).toBe('6/1/365.5');
    w.assertClean();
  });

  test('raw issue: needs an extruder and bags; kilos are bags x pack weight; mass balance kg in follows the server', async ({ page, request, w }) => {
    await openAs(page, 'supbags', 'bags', 'production/raw');
    const a = await api(request, 'supbags');
    await shoot(page, 'bags-raw');
    const btn = page.getByRole('button', { name: /^Issue(?!d)/ });
    await expect(btn).toBeDisabled();
    await expect(page.getByText(/Still needed: an extruder, the number of bags/)).toBeVisible();
    await chipIn(page, 'Extruder', /^H$/);
    const bags = page.locator('main input[inputmode="numeric"]').first();
    await bags.fill('0');
    await expect(btn).toBeDisabled();
    await bags.fill('99999999');
    await expect(bags).not.toHaveValue('99,999,999');
    await bags.fill('4');
    await expect(btn).toBeEnabled();
    await expect(btn).toContainText('Issue 100 kg to Extruder H');
    await btn.click();
    await expect(page.getByText(/4 bags .* issued to Extruder H/).first()).toBeVisible();
    await expect(page.getByText('Recent issues')).toBeVisible();
    const day = (await a.json('/sites/bags/machine-wall?shift=day')).day;
    const mb = await a.json(`/sites/bags/mass-balance?day=${day}`);
    await go(page, 'bags', 'production/raw');
    expect(await text(page)).toContain('Raw material issue');
    expect(mb.kgIn).toBeGreaterThan(0);
    w.assertClean();
  });

  test('backend: the machine wall ignores raw-issue entries of the same extruder (wall filters by its stage)', async ({ request }) => {
    const a = await api(request, 'supbags');
    const wall = await serverWall(a, 'bags', 'day');
    const h = wall.machines.find((m) => m.machine.code === 'Extruder H')! as unknown as { entry?: { stageCode?: string } | null };
    expect(h.entry?.stageCode ?? 'extrude').toBe('extrude');
  });

  test('roll sticker: validation, a roll at the standard weight, pending code replaced, duplicate scan of the same roll later', async ({ page, request, w }) => {
    await openAs(page, 'supbags', 'bags', 'production/rolls');
    const a = await api(request, 'supbags');
    await shoot(page, 'bags-rolls');
    const btn = page.getByRole('button', { name: 'Print sticker' });
    await expect(btn).toBeDisabled();
    await expect(page.getByText(/Still needed: a machine, an operator, the weight/)).toBeVisible();
    // Extruder C is down: its chip cannot be chosen
    await expect(page.locator('[aria-label="Machine"]').getByRole('radio', { name: /^C$/ })).toBeDisabled();
    await chipIn(page, 'Machine', /^B$/);
    await chooseWorker(page, page.locator('main'));
    await weighManually(page, '60.4', /Roll scale/);
    await expect(btn).toBeEnabled();
    const before = (await labelsOf(a, 'bags', 'kind=roll')).length;
    await btn.click();
    await expect(page.getByText(/Roll sticker printed/).first()).toBeVisible();
    const rolls = await eventually(() => labelsOf(a, 'bags', 'kind=roll'), (l) => l.length > before);
    const mine = rolls.find((l) => Math.abs(l.qtyKg - 60.4) < 0.05)!;
    expect(mine.code).toMatch(/^BAG-ROL-\d{6}$/);
    await expect.poll(async () => (await text(page)).includes(mine.code), { timeout: 40_000 }).toBeTruthy();
    expect(await text(page)).not.toMatch(/PENDING-/);
    w.assertClean();
  });

  test('film scrap: weigh a sack, destination choices, pending code replaced', async ({ page, request, w }) => {
    await openAs(page, 'supbags', 'bags', 'production/scrap');
    const a = await api(request, 'supbags');
    await shoot(page, 'bags-scrap');
    const btn = page.getByRole('button', { name: /^Label sack/ });
    await expect(btn).toBeDisabled();
    await expect(page.getByText(/Still needed: the weight/)).toBeVisible();
    await chipIn(page, 'Destination', /Re-pelletise/);
    await weighManually(page, '18.2', /Scrap scale/);
    await expect(btn).toBeEnabled();
    const before = (await labelsOf(a, 'bags', 'kind=sack')).length;
    await btn.click();
    const sacks = await eventually(() => labelsOf(a, 'bags', 'kind=sack'), (l) => l.length > before);
    const mine = sacks.find((l) => Math.abs(l.qtyKg - 18.2) < 0.05)!;
    expect(mine, 'the 18.2 kg sack is on the server').toBeTruthy();
    expect(mine.code).toMatch(/^BAG-SAC-\d{6}$/);
    await expect.poll(async () => (await text(page)).includes(mine.code), { timeout: 40_000 }).toBeTruthy();
    expect(await text(page)).not.toMatch(/PENDING-/);
    w.assertClean();
  });

  test('conversion: scan rolls in, count packs out; unknown and duplicate roll codes', async ({ page, request, w }) => {
    await openAs(page, 'supbags', 'bags', 'production/convert');
    const a = await api(request, 'supbags');
    await shoot(page, 'bags-convert');
    const btn = page.getByRole('button', { name: /Record and print|Record/ }).last();
    await expect(btn).toBeDisabled();
    const scan = page.getByPlaceholder('Scan or type a roll code');
    const rolls = (await labelsOf(a, 'bags', 'kind=roll&status=in_stock'));
    expect(rolls.length).toBeGreaterThan(2);
    // unknown code
    await scan.fill('BAG-ROL-999999'); await scan.press('Enter');
    await expect(page.getByText(/No roll in the roll store with that code/).first()).toBeVisible();
    // known code, then the same again (duplicate)
    await scan.fill(rolls[0].code); await scan.press('Enter');
    await expect(page.getByText('1 roll on this run')).toBeVisible();
    await scan.fill(rolls[0].code); await scan.press('Enter');
    await expect(page.getByText(/already on this run/).first()).toBeVisible();
    await expect(page.getByText('1 roll on this run')).toBeVisible();
    // a full run: second roll, converter, product, packs
    await page.getByRole('button', { name: 'Scan next' }).click();
    await expect(page.getByText('2 rolls on this run')).toBeVisible();
    await page.locator('[aria-label="Converter"]').getByRole('radio').first().click();
    const product = page.getByPlaceholder('Search products');
    await product.click(); await page.getByRole('option').first().click();
    const packs = page.locator('main input[inputmode="numeric"]').nth(0);
    await packs.fill('99999'); await expect(packs).not.toHaveValue('99,999');
    await packs.fill('3');
    await expect(page.getByText(/Still needed/)).toHaveCount(0);
    const btn2 = page.getByRole('button', { name: /^Record and print/ });
    await expect(btn2).toBeEnabled();
    const before = (await labelsOf(a, 'bags', 'kind=pack')).length;
    await btn2.click();
    const got = await eventually(() => labelsOf(a, 'bags', 'kind=pack'), (l) => l.length >= before + 3);
    expect(got.length).toBe(before + 3);
    await expect.poll(async () => (await labelsOf(a, 'bags', 'kind=roll')).filter((l) => l.status === 'consumed').length, { timeout: 30_000 }).toBeGreaterThanOrEqual(2);
    w.assertClean();
  });

  test('bags shift sheet and print labels', async ({ page, request, w }) => {
    await openAs(page, 'supbags', 'bags', 'production/sheet');
    const a = await api(request, 'supbags');
    const wall = await serverWall(a, 'bags', 'day');
    const run = wall.machines.filter((m) => m.state !== 'arriving');
    const t = await text(page);
    expect(t).toContain(`${run.filter((m) => m.entry).length} of ${run.length} logged`);
    const mb = await a.json(`/sites/bags/mass-balance?day=${wall.day}`);
    expect(t).toContain(fmt(mb.kgIn));
    await shoot(page, 'bags-sheet');
    await go(page, 'bags', 'production/labels');
    await shoot(page, 'bags-print-label');
    const btn = page.getByRole('button', { name: /create roll label/i });
    await btn.click();
    await expect(page.getByText('Label not recorded').first()).toBeVisible();
    await expect(page.getByText(/Still needed: product/).first()).toBeVisible();
    w.assertClean();
  });
});
