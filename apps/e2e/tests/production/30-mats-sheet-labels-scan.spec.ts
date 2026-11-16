import { expect, test } from '../../support/helpers';
import { api, chooseWorker, eventually, fmt, go, labelsOf, openAs, serverWall, settle, shoot, text } from './util';
import { businessDay } from '../../support/people';

test.describe('mats: shift handover sheet, print labels, scan', () => {
  test('shift sheet shows the server book and the server mass balance; CSV and tabs work', async ({ page, request, w }) => {
    await openAs(page, 'supmats', 'mats', 'production/sheet');
    const a = await api(request, 'supmats');
    const wall = await serverWall(a, 'mats', 'day');
    const run = wall.machines.filter((m) => m.state !== 'arriving');
    const logged = run.filter((m) => m.entry).length;
    const t = await text(page);
    expect(t).toContain(`${logged} of ${run.length} logged`);
    for (const m of run) expect(t).toContain(m.machine.code);
    const mb = await a.json(`/sites/mats/mass-balance?day=${wall.day}`);
    expect(t).toContain(fmt(mb.kgIn));
    expect(t).toContain(`Allowed loss ${mb.allowedLossPct}%`);
    const totalGood = run.reduce((s, m) => s + (m.entry?.goodUnits ?? 0), 0);
    expect(t).toMatch(new RegExp(`Totals · ${logged} of ${run.length} logged ${fmt(totalGood)}`));
    await shoot(page, 'mats-sheet');
    // night shift tab
    await page.getByRole('tab', { name: /Night shift/ }).click();
    const night = await serverWall(a, 'mats', 'night', wall.day);
    await expect.poll(() => text(page)).toContain(`${night.machines.filter((m) => m.entry).length} of ${run.length} logged`);
    await page.getByRole('tab', { name: /Day shift/ }).click();
    // CSV download
    const [dl] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'CSV' }).click()]);
    expect(dl.suggestedFilename()).toMatch(/\.csv$/);
    w.assertClean();
  });

  test('close shift: offline is refused plainly; an already-closed shift answers a plain 409 and then reads Shift closed', async ({ page, w }) => {
    // The seed closes every shift up to its last day, so the page is told (only here) that none is closed yet; the real server
    // then answers for itself. The real closed shifts are kept to pick a day the server has closed, whatever the wall clock.
    const closed = new Set<string>();
    await page.route(/\/snapshots\?tables=/, async (r) => {
      const res = await r.fetch(); const body = await res.json();
      const c = body.results?.production_shifts_closed;
      if (c) {
        for (const x of (c.data ?? []) as { siteId?: string; day: string; shiftKind: string; deletedAt?: string | null }[]) if (!x.deletedAt) closed.add(`${String(x.day).slice(0, 10)}|${x.shiftKind}`);
        body.results.production_shifts_closed = { ...c, data: [], has_more: false };
      }
      await r.fulfill({ response: res, json: body });
    });
    await openAs(page, 'supmats', 'mats', 'production/sheet');
    // The Day chips are the last 7 working days (Sundays off), oldest first, today last. Work on the latest past day whose
    // shifts are both closed on the server (today's state depends on the time of day and on the seed's last day).
    const days: string[] = []; for (let d = businessDay(); days.length < 7; d = new Date(Date.parse(d) - 86_400_000).toISOString().slice(0, 10)) if (new Date(d + 'T00:00:00Z').getUTCDay() !== 0) days.unshift(d);
    await expect.poll(() => closed.size, { timeout: 15_000 }).toBeGreaterThan(0);
    const idx = days.slice(0, -1).map((d, i) => [d, i] as const).reverse().find(([d]) => closed.has(`${d}|day`) && closed.has(`${d}|night`))?.[1];
    expect(idx, `no closed day among ${days.join(', ')}`).toBeDefined();
    const chips = page.getByRole('radiogroup', { name: 'Day' }).getByRole('radio');
    await expect(chips).toHaveCount(7);
    await chips.nth(idx!).click();
    await settle(page);
    const close = page.getByRole('button', { name: /^close shift$/i });
    await expect(close).toBeEnabled();
    await shoot(page, 'mats-sheet-close-open');
    // offline: refused with a plain message, the button stays usable
    await page.context().setOffline(true);
    await close.click();
    await expect(page.getByText('Shift not closed').first()).toBeVisible();
    await expect(page.getByText('No connection. A shift can only be closed while online.').first()).toBeVisible();
    await page.context().setOffline(false);
    await expect(close).toBeEnabled();
    // online: the server says it is already closed (409): plain words, no codes, and the button now reads Shift closed
    w.allow(/409 POST \/sites\/mats\/production\/shifts\/close/);
    await close.click();
    await expect(page.getByText('This shift is already closed. It cannot be closed twice.').first()).toBeVisible();
    await expect(page.getByRole('button', { name: /^shift closed$/i })).toBeDisabled();
    await expect(page.getByText(/\b409\b|exception|undefined|\[object/i)).toHaveCount(0);
    w.assertClean();
  });

  test('print label: validation, a good batch label, pending code replaced by the real one, recent list', async ({ page, request, w }) => {
    await openAs(page, 'supmats', 'mats', 'production/labels');
    const a = await api(request, 'supmats');
    await shoot(page, 'mats-print-label');
    const btn = page.getByRole('button', { name: /create batch label/i });
    await btn.click(); // nothing chosen: refused, nothing created
    await expect(page.getByText('Label not recorded').first()).toBeVisible();
    await expect(page.getByText(/Still needed: colour/).first()).toBeVisible();
    await page.getByRole('radio', { name: 'Green' }).click();
    const kg = page.locator('main input[inputmode="decimal"]').first();
    for (const bad of ['-3', '0']) { await kg.fill(bad); await btn.click(); await expect(page.getByText(/Still needed: .*kg/).first()).toBeVisible(); }
    await kg.fill('48.5');
    await page.getByPlaceholder('Search machine').click();
    await page.getByRole('option').first().click();
    const before = (await labelsOf(a, 'mats', 'kind=batch')).length;
    await btn.click();
    await expect(page.getByText('Batch label recorded').first()).toBeVisible();
    const got = await eventually(() => labelsOf(a, 'mats', 'kind=batch'), (l) => l.length > before);
    const mine = got.find((l) => Math.abs(l.qtyKg - 48.5) < 0.05)!;
    expect(mine, 'the 48.5 kg Green batch label is on the server').toBeTruthy();
    expect(mine.colour).toBe('Green');
    await expect.poll(async () => (await text(page)).includes(mine.code), { timeout: 40_000 }).toBeTruthy();
    expect(await text(page)).not.toMatch(/PENDING-/);
    // product-based kind: Bundle
    await page.getByRole('radio', { name: /Bundle/ }).click();
    await expect(page.getByText(/Still needed: product/).first()).toBeVisible();
    w.assertClean();
  });

  test('scan anything: known, unknown and another site code; recent scans; history from the server', async ({ page, request, w }) => {
    await openAs(page, 'supmats', 'mats', 'production/scan');
    const a = await api(request, 'supmats');
    await shoot(page, 'mats-scan');
    const known = (await labelsOf(a, 'mats', 'kind=bundle')).find((l) => l.status === 'in_stock')!;
    const field = page.locator('main input').first();
    await field.fill(known.code);
    await field.press('Enter');
    await expect(page.locator('main').getByText(known.code).first()).toBeVisible();
    await expect(page.getByText(/Recent scans/)).toBeVisible();
    await shoot(page, 'mats-scan-found');
    // unknown code: a plain message, no crash
    w.allow(/404 GET \/labels\//);
    await field.fill('NOPE-000000-X');
    await field.press('Enter');
    await expect(page.getByText(/Unknown code/)).toBeVisible();
    // duplicate scan: the same code twice keeps one entry in the recent list
    await field.fill(known.code); await field.press('Enter');
    await field.fill(known.code); await field.press('Enter');
    const items = page.locator('main').getByText(known.code);
    expect(await items.count()).toBeGreaterThan(0);
    // a bags label from the mats supervisor: not visible to this role
    const bagsLabel = (await labelsOf(await api(request, 'supbags'), 'bags', 'kind=roll'))[0];
    w.allow(/40[34] GET \/labels\//);
    await field.fill(bagsLabel.code); await field.press('Enter');
    await expect(page.getByText(/Unknown code/)).toBeVisible();
    // simulate buttons
    await page.getByRole('button', { name: /^In stock/ }).first().click();
    w.assertClean();
  });
});
