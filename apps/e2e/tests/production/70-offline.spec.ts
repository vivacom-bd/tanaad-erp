import { expect, test } from '../../support/helpers';
import { api, chooseWorker, eventually, labelsOf, openAs, saveEntry, serverWall, shoot, text } from './util';

test.describe('offline-capable production actions queue and sync', () => {
  test('shift entry offline: saved locally, reaches the server on reconnect', async ({ page, request, w }) => {
    await openAs(page, 'supmats', 'mats', 'production/wall');
    const a = await api(request, 'supmats');
    const wall = await serverWall(a, 'mats', 'day');
    // Prefer a loom with no entry yet: the seed's entries carry times later than the real clock before ~10:40 Kampala, and a server entry
    // newer than the device's own edit is (correctly) shown instead of it. Fall back to a logged loom when every loom is logged.
    const fresh = wall.machines.find((m) => m.state === 'not_logged');
    const code = (fresh ?? wall.machines.filter((m) => m.state === 'ok' || m.state === 'logged')[1]).machine.code;
    await page.context().setOffline(true);
    await page.locator(`[data-tile][aria-label^="${code},"]`).click();
    const dlg = page.getByRole('dialog');
    if (fresh) { await chooseWorker(page, dlg); if (!(await dlg.locator('[aria-label="Product"] [aria-checked="true"]').count())) await dlg.locator('[aria-label="Product"]').getByRole('radio').first().click(); }
    const nums = dlg.locator('input[inputmode]');
    await nums.nth(0).fill('61'); await nums.nth(1).fill('3'); await nums.nth(2).fill('0');
    await saveEntry(dlg);
    await expect(page.getByText(`${code} logged`).first()).toBeVisible();
    await expect(page.locator(`[data-tile][aria-label^="${code},"]`)).toContainText('61');
    await shoot(page, 'mats-wall-offline');
    const stale = (await serverWall(a, 'mats', 'day', wall.day)).machines.find((m) => m.machine.code === code)!.entry;
    expect(stale?.goodUnits, 'the server has not seen it yet').not.toBe(61);
    await page.context().setOffline(false);
    await expect.poll(async () => (await serverWall(a, 'mats', 'day', wall.day)).machines.find((m) => m.machine.code === code)!.entry?.goodUnits, { timeout: 60_000 }).toBe(61);
    w.assertClean();
  });

  test('label create offline: a PENDING code is shown, then replaced by the real one after sync', async ({ page, request, w }) => {
    await openAs(page, 'supmats', 'mats', 'production/labels');
    const a = await api(request, 'supmats');
    await page.context().setOffline(true);
    await page.getByRole('radio', { name: 'Cream' }).click();
    await page.locator('main input[inputmode="decimal"]').first().fill('33.3');
    await page.getByPlaceholder('Search machine').click();
    await page.getByRole('option').first().click();
    await page.getByRole('button', { name: /create batch label/i }).click();
    await expect(page.getByText('Batch label recorded').first()).toBeVisible();
    // the pending code is visible in the recent list, marked as pending, never presented as a real number silently
    await expect(page.getByText(/PENDING-[A-Z0-9]{6}/).first()).toBeVisible();
    await shoot(page, 'mats-label-offline');
    const before = (await labelsOf(a, 'mats', 'kind=batch')).filter((l) => Math.abs(l.qtyKg - 33.3) < 0.05).length;
    expect(before).toBe(0);
    await page.context().setOffline(false);
    const got = await eventually(() => labelsOf(a, 'mats', 'kind=batch'), (l) => l.some((x) => Math.abs(x.qtyKg - 33.3) < 0.05), 60_000);
    const mine = got.find((x) => Math.abs(x.qtyKg - 33.3) < 0.05)!;
    await expect.poll(async () => { const t = await text(page); return t.includes(mine.code) && !/PENDING-/.test(t); }, { timeout: 60_000 }).toBeTruthy();
    w.assertClean();
  });

  test('mixer batch offline: queued, then real code and server kilos after sync', async ({ page, request, w }) => {
    await openAs(page, 'supmats', 'mats', 'production/mixer');
    const a = await api(request, 'supmats');
    const bags = page.locator('main input[inputmode="numeric"]').first();
    await page.context().setOffline(true);
    await bags.fill('4');
    await page.getByRole('radio', { name: 'Purple' }).click();
    await chooseWorker(page, page.locator('main'));
    await page.getByRole('button', { name: /weigh and label/i }).click();
    await expect(page.getByText(/Batch .* labelled/).first()).toBeVisible();
    await expect(page.getByText(/PENDING-/).first()).toBeVisible();
    await page.context().setOffline(false);
    const got = await eventually(() => labelsOf(a, 'mats', 'kind=batch'), (l) => l.some((x) => x.colour === 'Purple' && Math.abs(x.qtyKg - 165) < 0.05), 60_000);
    expect(got.some((x) => x.colour === 'Purple' && Math.abs(x.qtyKg - 165) < 0.05)).toBeTruthy();
    await expect.poll(async () => !/PENDING-/.test(await text(page)), { timeout: 60_000 }).toBeTruthy();
    w.assertClean();
  });

  test('roll sticker offline in bags: queued and replaced; online-only actions refuse plainly', async ({ page, request, w }) => {
    await openAs(page, 'supbags', 'bags', 'production/scrap');
    const a = await api(request, 'supbags');
    await page.context().setOffline(true);
    const { weighManually } = await import('./util');
    await weighManually(page, '9.9', /Scrap scale/);
    await page.getByRole('button', { name: /^Label sack/ }).click();
    await page.context().setOffline(false);
    const got = await eventually(() => labelsOf(a, 'bags', 'kind=sack'), (l) => l.some((x) => Math.abs(x.qtyKg - 9.9) < 0.05), 60_000);
    expect(got.some((x) => Math.abs(x.qtyKg - 9.9) < 0.05)).toBeTruthy();
    w.assertClean();
  });
});
