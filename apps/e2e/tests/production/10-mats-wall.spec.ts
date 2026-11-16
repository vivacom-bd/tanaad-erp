import { expect, test } from '../../support/helpers';
import { api, expectTilesMatchServer, fmt, go, openAs, serverWall, shoot, text } from './util';

test.describe('mats: machine wall and shift entry (supervisor)', () => {
  test('wall renders the server numbers; filters, tabs, table view work', async ({ page, request, w }) => {
    await openAs(page, 'supmats', 'mats', 'production/wall');
    const a = await api(request, 'supmats');
    const wall = await serverWall(a, 'mats', 'day');
    const running = wall.machines.filter((m) => m.state !== 'arriving');
    const logged = running.filter((m) => m.entry).length;
    const good = running.reduce((t, m) => t + (m.entry?.goodUnits ?? 0), 0);
    const rejects = running.reduce((t, m) => t + (m.entry?.rejectUnits ?? 0), 0);
    const down = running.reduce((t, m) => t + (m.entry?.downtimeMin ?? 0), 0);
    const t = await text(page);
    expect(t).toContain(`${logged} logged`);
    expect(t).toContain(`${running.length - logged} not logged`);
    expect(t).toContain(`${running.filter((m) => m.state === 'below').length} below target`);
    expect(t).toMatch(new RegExp(`Mats this shift ${fmt(good)}\\b`));
    expect(t).toMatch(new RegExp(`Rejects ${rejects}\\b`));
    expect(t).toMatch(new RegExp(`Downtime ${fmt(down)}\\s*min`));
    await expectTilesMatchServer(page, wall);
    await shoot(page, 'mats-wall');

    // filters dim the tiles that do not match
    const filter = page.locator('[aria-label="Filter machines"]');
    const dimmed = () => page.locator('[data-tile].opacity-30').count();
    const pick = async (name: string, shown: number) => { await filter.getByRole('radio', { name, exact: true }).click(); await expect.poll(dimmed).toBe(running.length - shown); };
    await pick('Not logged', running.length - logged);
    await pick('Below target', running.filter((m) => m.state === 'below').length);
    await pick('Down', running.filter((m) => m.state === 'down').length);
    await pick('Logged', running.filter((m) => ['ok', 'logged', 'below'].includes(m.state)).length);
    await pick('All', running.length);

    // table view, sorted by a click on a header
    await page.getByRole('tab', { name: 'Table View' }).click();
    await expect(page.locator('main table tbody tr').filter({ hasText: 'Loom 1' }).first()).toBeVisible();
    await shoot(page, 'mats-wall-table');
    await page.getByRole('tab', { name: 'Machine Wall' }).click();

    // night shift against the server
    const night = await serverWall(a, 'mats', 'night', wall.day);
    await page.getByRole('tab', { name: 'Night shift' }).click();
    await expect.poll(() => text(page)).toContain(`${night.machines.filter((m) => m.entry).length} logged`);
    await expectTilesMatchServer(page, night);
    await shoot(page, 'mats-wall-night');
    await page.getByRole('tab', { name: 'Day shift' }).click();
    w.assertClean();
  });

  test('shift entry: missing fields are refused with a plain message, nothing is sent', async ({ page, w }) => {
    await openAs(page, 'supmats', 'mats', 'production/wall');
    const posts: string[] = [];
    page.on('request', (r) => { if (r.method() === 'POST' && r.url().includes('/events')) posts.push(r.url()); });
    // a fresh (night) shift tile has no entry; fall back to the day shift if the night is logged already
    await page.getByRole('tab', { name: 'Night shift' }).click();
    const tile = page.locator('[data-tile][aria-label*="Not logged"]').first();
    const hasOpen = await tile.count();
    const target = hasOpen ? tile : page.locator('[data-tile]').first();
    await target.click();
    const dlg = page.getByRole('dialog');
    await expect(dlg).toContainText('Shift entry');
    if (hasOpen) {
      await expect(dlg).toContainText('Still needed: operator, product, good mats');
      await dlg.getByRole('button', { name: /save entry/i }).click();
      await expect(page.getByText('Not saved yet').first()).toBeVisible();
      await expect(dlg).toBeVisible();
    }
    await shoot(page, 'mats-entry-sheet');
    expect(posts).toHaveLength(0);
    await dlg.getByRole('button', { name: 'Close' }).first().click();
    await expect(dlg).toBeHidden();
    w.assertClean();
  });

  test('shift entry: below benchmark needs a reason; with one it saves and no exception is raised; the server agrees', async ({ page, request, w }) => {
    await openAs(page, 'supmats', 'mats', 'production/wall');
    const a = await api(request, 'supmats');
    const wall = await serverWall(a, 'mats', 'day');
    const open = wall.machines.find((m) => !m.entry && m.state !== 'arriving');
    const code = (open ?? wall.machines.find((m) => m.state === 'ok'))!.machine.code;
    await page.locator(`[data-tile][aria-label^="${code},"]`).click();
    const dlg = page.getByRole('dialog');
    if (open) {
      await dlg.getByPlaceholder('Search worker by name').click();
      await page.getByRole('option').first().click();
      await dlg.getByRole('radio', { name: /Domona/ }).click();
    }
    const nums = dlg.locator('input[inputmode]');
    await nums.nth(0).fill('9999999'); // the pad refuses anything above 99,999
    await expect(nums.nth(0)).not.toHaveValue('9,999,999');
    await nums.nth(0).fill('1');
    await expect(dlg).toContainText('The entry cannot be saved without a reason');
    await dlg.getByRole('button', { name: /(save|update) entry/i }).click();
    await expect(page.getByText('Not saved yet').first()).toBeVisible();
    await expect(dlg).toBeVisible();
    await dlg.getByRole('radio', { name: 'Warp Bobbin Creel Change' }).click().catch(async () => { await dlg.getByRole('radio').last().click(); });
    await expect(dlg).toContainText('Ready to save');
    await dlg.getByRole('button', { name: /(save|update) entry/i }).click();
    await expect(dlg).toBeHidden();
    await expect(page.getByText(`${code} logged`).first()).toBeVisible();
    await expect.poll(async () => (await serverWall(a, 'mats', 'day', wall.day)).machines.find((x) => x.machine.code === code)?.entry?.goodUnits, { timeout: 25_000 }).toBe(1);
    const ex = await (await api(request, 'ceo1')).json('/exceptions');
    const list = (ex.data ?? ex) as { kind: string; title: string; status: string }[];
    expect(list.filter((e) => e.kind === 'below_benchmark' && e.title.includes(' 1 of '))).toHaveLength(0);
    await go(page, 'mats', 'production/wall');
    await expectTilesMatchServer(page, await serverWall(a, 'mats', 'day', wall.day));
    w.assertClean();
  });

  test('shift entry: zero, negative and decimals are refused; a good entry saves', async ({ page, request, w }) => {
    await openAs(page, 'supmats', 'mats', 'production/wall');
    const a = await api(request, 'supmats');
    const wall = await serverWall(a, 'mats', 'day');
    const code = wall.machines.find((m) => m.state === 'ok')!.machine.code;
    await page.locator(`[data-tile][aria-label^="${code},"]`).click();
    const dlg = page.getByRole('dialog');
    const nums = dlg.locator('input[inputmode]');
    await nums.nth(0).fill('-5');
    await expect(nums.nth(0)).not.toHaveValue(/-/);
    await nums.nth(0).fill('0');
    await expect(dlg).toContainText('Still needed: good mats');
    await nums.nth(0).fill('55');
    await nums.nth(1).fill('2');
    await nums.nth(2).fill('30');
    await expect(dlg).toContainText('Ready to save');
    await dlg.getByRole('button', { name: /(save|update) entry/i }).click();
    await expect(dlg).toBeHidden();
    await expect.poll(async () => { const e = (await serverWall(a, 'mats', 'day', wall.day)).machines.find((x) => x.machine.code === code)?.entry; return e && [e.goodUnits, e.rejectUnits, e.downtimeMin].join('/'); }, { timeout: 25_000 }).toBe('55/2/30');
    w.assertClean();
  });
});
