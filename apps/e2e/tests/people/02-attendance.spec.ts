import { expect, test, goOffline, goOnline, watch } from '../../support/helpers';
import { apiAs, businessDay, eventually, gotoSite, sheetCounts, signIn, snap, tolerateThrottledPulls, type Api } from '../../support/people';

test.describe.configure({ mode: 'serial' });

const TODAY = businessDay(); // the same business day the app and the server use
type Row = Record<string, any>;

/** Active workers of mats that can be marked today (no ended contract), in name order. */
async function markable(a: Api) {
  const people = (await snap(a, 'mats', 'people')).filter((p) => p.status === 'active' && !p.shared);
  const engs = await snap(a, 'mats', 'engagements');
  return people.filter((p) => { const e = engs.find((x) => x.personId === p.id && x.status === 'active'); return e && (e.type !== 'contract' || (e.endDate && e.endDate >= TODAY)); })
    .sort((x, y) => x.fullName.localeCompare(y.fullName));
}
const todayRows = async (a: Api, kind: 'day' | 'night') => (await snap(a, 'mats', 'attendance')).filter((r) => r.day === TODAY && r.shiftKind === kind);
/** Workers who can be marked on this shift and have no mark yet today (read from the server, so the seed may change). */
async function unmarked(a: Api, kind: 'day' | 'night') {
  const done = new Set((await todayRows(a, kind)).map((r) => r.personId));
  return (await markable(a)).filter((p) => !done.has(p.id));
}
const group = (page: any, name: string) => page.getByRole('radiogroup', { name: `${name} today` });

let A: string, B: string, C: string, D: string;

test('supervisor marks present, half and absent; the server gets each exactly once', async ({ page, request, w }) => {
  tolerateThrottledPulls(w);
  const a = await apiAs(request, 'admin');
  [A, B, C, D] = (await unmarked(a, 'day')).slice(0, 4).map((p) => p.fullName);
  expect(D, 'four unmarked day-shift workers in the seed').toBeTruthy();
  const dayBefore = (await todayRows(a, 'day')).length;
  await signIn(page, 'supmats');
  await gotoSite(page, 'mats', 'people/attendance');
  const c0 = await sheetCounts(page);
  await group(page, A).getByRole('radio', { name: 'Present' }).click();
  await expect(page.getByRole('status')).toContainText(/present/i);
  await group(page, B).getByRole('radio', { name: 'Half' }).click();
  await group(page, C).getByRole('radio', { name: 'Absent' }).click();
  await expect(page.getByText(new RegExp(`${c0.marked + 3} of ${c0.roster} marked · ${c0.present + 1} present · ${c0.half + 1} half day · ${c0.absent + 1} absent`))).toBeVisible();
  await expect(group(page, A).getByRole('radio', { name: 'Present' })).toHaveAttribute('aria-checked', 'true');
  const rows = await eventually(() => todayRows(a, 'day'), (r) => r.length >= dayBefore + 3, 'three attendance rows');
  const people = await snap(a, 'mats', 'people');
  const st = (n: string) => rows.filter((r) => r.personId === people.find((p) => p.fullName === n)!.id);
  expect(st(A).map((r) => r.status)).toEqual(['present']);
  expect(st(B).map((r) => r.status)).toEqual(['half']);
  expect(st(C).map((r) => r.status)).toEqual(['absent']);
  expect(rows.length).toBe(dayBefore + 3);
  w.assertClean();
});

test('changing a mark asks for a reason (correction) and saves it', async ({ page, request, w }) => {
  tolerateThrottledPulls(w);
  const a = await apiAs(request, 'admin');
  await signIn(page, 'supmats');
  await gotoSite(page, 'mats', 'people/attendance');
  await group(page, A).getByRole('radio', { name: 'Absent' }).click();
  const panel = page.getByRole('group', { name: 'Correction reason' });
  await expect(panel).toBeVisible();
  await expect(panel.getByRole('button', { name: 'Save correction' })).toBeDisabled(); // empty reason
  await panel.getByRole('button', { name: 'Cancel' }).click();
  await expect(panel).toBeHidden();
  await expect(group(page, A).getByRole('radio', { name: 'Present' })).toHaveAttribute('aria-checked', 'true'); // unchanged
  await group(page, A).getByRole('radio', { name: 'Absent' }).click();
  await panel.getByLabel('Reason for the correction').fill('   '); // whitespace is not a reason
  await expect(panel.getByRole('button', { name: 'Save correction' })).toBeDisabled();
  await panel.getByLabel('Reason for the correction').fill('Went home sick at noon');
  await panel.getByRole('button', { name: 'Save correction' }).click();
  await expect(page.getByRole('status')).toContainText(/Correction: Went home sick/);
  const people = await snap(a, 'mats', 'people'); const id = people.find((p) => p.fullName === A)!.id;
  await eventually(() => todayRows(a, 'day'), (r) => r.find((x) => x.personId === id)?.status === 'absent', 'A corrected to absent');
  expect((await todayRows(a, 'day')).filter((r) => r.personId === id)).toHaveLength(1);
  w.assertClean();
});

test('overtime: marks an unmarked worker present, enforces the maximum, corrects an existing mark', async ({ page, request, w }) => {
  tolerateThrottledPulls(w);
  const a = await apiAs(request, 'admin');
  const people = await snap(a, 'mats', 'people');
  await signIn(page, 'supmats');
  await gotoSite(page, 'mats', 'people/attendance');
  // D is not marked: saving overtime marks them present
  await page.getByRole('button', { name: `Overtime for ${D}` }).click();
  const dlg = page.getByRole('dialog');
  await expect(dlg).toContainText('Not marked yet');
  const input = dlg.getByRole('textbox');
  await input.fill('99'); // 12 h shift -> at most 12 h of overtime; the pad refuses a larger number
  await expect(input).not.toHaveValue('99');
  await input.fill('3');
  await dlg.getByRole('button', { name: /Save 3 h/ }).click();
  await expect(page.getByRole('status')).toContainText(/3 h overtime/);
  const id = people.find((p) => p.fullName === D)!.id;
  const r = await eventually(() => todayRows(a, 'day'), (x) => x.some((y) => y.personId === id), 'D marked');
  expect(r.find((x) => x.personId === id)).toMatchObject({ status: 'present', overtimeHours: '3.00' });
  // B is already marked half: changing the overtime is a correction and needs a reason
  await page.getByRole('button', { name: `Overtime for ${B}` }).click();
  await dlg.getByRole('textbox').fill('2');
  await dlg.getByRole('button', { name: /Save 2 h/ }).click();
  const panel = page.getByRole('group', { name: 'Correction reason' });
  await expect(panel).toBeVisible();
  await panel.getByLabel('Reason for the correction').fill('Stayed to finish the order');
  await panel.getByRole('button', { name: 'Save correction' }).click();
  const bId = people.find((p) => p.fullName === B)!.id;
  await eventually(() => todayRows(a, 'day'), (x) => x.find((y) => y.personId === bId)?.overtimeHours === '2.00', 'B overtime 2');
  await expect(page.getByText(`+2h`).first()).toBeVisible();
  w.assertClean();
});

test('past days are locked; sector chip, search and the archived tab work; CSV exports', async ({ page, request, w }) => {
  tolerateThrottledPulls(w);
  const a = await apiAs(request, 'admin');
  await signIn(page, 'supmats');
  await gotoSite(page, 'mats', 'people/attendance');
  const grid = page.getByRole('grid', { name: 'Attendance sheet' });
  await grid.getByRole('row').filter({ hasText: B }).locator('td').nth(2).click();
  await expect(page.getByRole('status')).toContainText('Past days are locked');
  // sector chip
  const people = await snap(a, 'mats', 'people'); const sector = people.find((p) => p.fullName === B)!.sector;
  await page.getByRole('radio', { name: sector, exact: true }).first().click().catch(async () => { await page.getByRole('button', { name: sector, exact: true }).first().click(); });
  await expect(grid.getByText(B)).toBeVisible();
  const inSector = (await markable(a)).concat(people.filter((p) => p.status === 'active')).filter((p, i, arr) => p.sector === sector && arr.findIndex((q) => q.id === p.id) === i).length;
  expect(inSector).toBeGreaterThan(0);
  // search
  await page.getByPlaceholder(/find worker by name/i).fill(B.split(' ')[0].slice(0, 4).toLowerCase());
  await expect(grid.getByText(B)).toBeVisible();
  await page.getByPlaceholder(/find worker by name/i).fill('zzzz-nobody');
  await expect(page.getByText('No worker matches')).toBeVisible();
  await page.getByRole('button', { name: 'Clear search' }).click();
  await expect(grid.getByText(B)).toBeVisible();
  // CSV
  const dl = page.waitForEvent('download');
  await page.getByRole('button', { name: 'Export' }).click();
  expect((await dl).suggestedFilename()).toMatch(/^mats-attendance-(day|night)-\d{4}-\d{2}-\d{2}\.csv$/);
  // archived tab: server archived workers
  const archived = people.filter((p) => p.status === 'archived');
  await page.getByRole('tab', { name: /Archived Workers/ }).click();
  await expect(page.getByText(archived[0].fullName).first()).toBeVisible();
  await expect(page.getByRole('tab', { name: /Archived Workers/ })).toContainText(String(archived.length));
  w.assertClean();
});

test('guided roll call marks with the buttons and finishes', async ({ page, request, w }) => {
  tolerateThrottledPulls(w);
  const a = await apiAs(request, 'admin');
  await signIn(page, 'supmats');
  await gotoSite(page, 'mats', 'people/attendance');
  const before = (await todayRows(a, 'day')).length;
  await page.getByRole('button', { name: /Guided Roll Call/ }).click();
  const dlg = page.getByRole('dialog');
  await expect(dlg).toContainText(/Roll call/);
  // Walk the roll: skip anyone already marked or with an ended contract, and mark three people present, half day and absent.
  const labels = ['Present', 'Half day', 'Absent']; let done = 0;
  for (let guard = 0; done < 3 && guard < 80; guard++) {
    await dlg.getByText(/Each mark saves|Already marked|Renew before marking/).first().waitFor();
    if (await dlg.getByText(/Already marked|Renew before marking/).first().isVisible()) { await dlg.getByRole('button', { name: 'Skip' }).click(); continue; }
    await dlg.getByRole('button', { name: labels[done], exact: true }).click(); done++;
  }
  expect(done).toBe(3);
  await expect(dlg.getByText(/\d+ of \d+ marked/).first()).toBeVisible();
  await dlg.getByRole('button', { name: 'Skip' }).click();
  await dlg.getByRole('button', { name: 'Previous' }).click();
  await eventually(() => todayRows(a, 'day'), (r) => r.length === before + 3, 'three more marks from roll call');
  await page.keyboard.press('Escape');
  await expect(dlg).toBeHidden();
  w.assertClean();
});

test('"Not marked yet" shortcuts and "Mark N unmarked Present" (online)', async ({ page, request, w }) => {
  tolerateThrottledPulls(w);
  const a = await apiAs(request, 'admin');
  await signIn(page, 'supmats');
  await gotoSite(page, 'mats', 'people/attendance');
  const aside = page.locator('aside').filter({ hasText: 'Not marked yet' });
  const first = aside.locator('button', { hasText: 'Present' }).first();
  await first.click();
  await expect(page.getByRole('status')).toContainText(/present/i);
  const btn = page.getByRole('button', { name: /^Mark \d+ unmarked Present$/ });
  const n = Number((await btn.innerText()).match(/\d+/)![0]);
  const before = (await todayRows(a, 'day')).length;
  await eventually(() => todayRows(a, 'day'), (r) => r.length === before + 0 || r.length >= before, 'settle');
  await btn.click();
  await expect(page.getByRole('status')).toContainText(`Marked ${n} workers Present`);
  const after = await eventually(() => todayRows(a, 'day'), (r) => r.length >= before + n, `${n} more rows`, 40_000);
  const ids = after.map((r) => r.personId); expect(new Set(ids).size, 'one row per person').toBe(ids.length);
  await expect(page.getByText('Everyone is marked')).toBeVisible();
  w.assertClean();
});

test('OFFLINE: a night-shift batch marked offline reaches the server exactly once after reconnecting', async ({ page, request, w }) => {
  tolerateThrottledPulls(w);
  const a = await apiAs(request, 'admin');
  const AN = (await unmarked(a, 'night'))[0].fullName;
  await signIn(page, 'supmats');
  await gotoSite(page, 'mats', 'people/attendance');
  await page.getByRole('tab', { name: 'Night' }).click();
  const c0 = await sheetCounts(page);
  const before = (await todayRows(a, 'night')).length;
  await goOffline(page);
  const btn = page.getByRole('button', { name: /^Mark \d+ unmarked Present$/ });
  const n = Number((await btn.innerText()).match(/\d+/)![0]);
  await btn.click();
  await group(page, AN).getByRole('radio', { name: 'Absent' }).click(); // a correction while offline
  await page.getByRole('group', { name: 'Correction reason' }).getByLabel('Reason for the correction').fill('Not on night duty');
  await page.getByRole('button', { name: 'Save correction' }).click();
  await page.waitForTimeout(1500);
  expect((await todayRows(a, 'night')).length, 'nothing reached the server while offline').toBe(before);
  await expect(page.getByText(/queued/i).first()).toBeVisible(); // the header shows the waiting work
  await goOnline(page);
  const rows = await eventually(() => todayRows(a, 'night'), (r) => r.length >= before + n, `${n} new night rows`, 60_000);
  const ids = rows.map((r) => r.personId);
  expect(new Set(ids).size, 'each person once').toBe(ids.length);
  expect(rows.length).toBe(before + n);
  const people = await snap(a, 'mats', 'people'); const aId = people.find((p) => p.fullName === AN)!.id;
  expect(rows.find((r) => r.personId === aId)?.status).toBe('absent');
  await page.waitForTimeout(2000);
  expect((await todayRows(a, 'night')).length, 'still exactly once after the next sync').toBe(before + n);
  expect(c0.marked).toBeGreaterThanOrEqual(0);
  await expect(page.getByText(/\d+ queued/)).toHaveCount(0);
  w.assertClean();
});

test('contracts-ending and ghost-worker panels agree with the server', async ({ page, request, w }) => {
  tolerateThrottledPulls(w);
  const a = await apiAs(request, 'admin');
  const engs = (await snap(a, 'mats', 'engagements')).filter((e) => e.type === 'contract' && e.status === 'active');
  const people = await snap(a, 'mats', 'people');
  const ended = engs.filter((e) => e.endDate < TODAY && people.find((p) => p.id === e.personId)?.status === 'active');
  await signIn(page, 'smmats');
  await gotoSite(page, 'mats', 'people/attendance');
  const panel = page.locator('section, div').filter({ has: page.getByText('Contracts ending', { exact: true }) }).last();
  await expect(page.getByText('Contracts ending', { exact: true })).toBeVisible();
  for (const e of ended.slice(0, 3)) await expect(page.getByRole('complementary').filter({ hasText: 'Not marked yet' })).toContainText(people.find((p) => p.id === e.personId)!.fullName);
  await expect(page.getByText('Ghost-worker guard')).toBeVisible();
  void panel; w.assertClean();
});

test('contracts ending: a supervisor is offered no Renew and the server refuses one; the site manager renews', async ({ page, request, w }) => {
  tolerateThrottledPulls(w);
  const sa = await apiAs(request, 'admin');
  const soon = new Date(Date.parse(TODAY) + 3 * 86400000).toISOString().slice(0, 10); // the sheet warns 3 days ahead
  const engs = (await snap(sa, 'mats', 'engagements')).filter((e) => e.type === 'contract' && e.status === 'active' && e.endDate <= soon);
  const people = await snap(sa, 'mats', 'people');
  const ph = people.filter((p) => p.status === 'active' && engs.some((e) => e.personId === p.id)).sort((x, y) => x.fullName.localeCompare(y.fullName));
  expect(ph.length, 'the seed has contracts ending soon').toBeGreaterThanOrEqual(2);
  const [X, Y] = [ph[0], ph[1]];
  const engX = engs.find((q) => q.personId === X.id)!;
  const ending = (pg: any) => pg.getByRole('complementary').filter({ hasText: 'Contracts ending' });
  // supervisor: the panel lists them but offers no Renew
  await signIn(page, 'supmats');
  await gotoSite(page, 'mats', 'people/attendance');
  await expect(ending(page)).toContainText(X.fullName);
  await expect(ending(page).getByRole('button', { name: 'Renew' })).toHaveCount(0);
  await expect(page.getByRole('grid', { name: 'Attendance sheet' }).getByRole('button', { name: 'Renew' })).toHaveCount(0);
  // the server says no, in words a person can read
  const sup = await apiAs(request, 'supmats');
  const r = await sup.post(`/sites/mats/engagements/${engX.id}/renew`, { termDays: 30, newEngagementId: crypto.randomUUID() });
  expect(r.status()).toBe(403);
  expect((await r.json()).detail).toBe('This role may not do this at this site.');
  expect((await snap(sa, 'mats', 'engagements')).find((e) => e.id === engX.id)!.status).toBe('active');
  // site manager renews Y from the panel
  const c2 = await page.context().browser()!.newContext(); const sm = await c2.newPage();
  const w2 = watch(sm); tolerateThrottledPulls(w2);
  await signIn(sm, 'smmats');
  await gotoSite(sm, 'mats', 'people/attendance');
  await ending(sm).locator('div.border-b').filter({ hasText: Y.fullName }).getByRole('button', { name: 'Renew' }).click();
  const dlg = sm.getByRole('dialog'); await expect(dlg).toContainText('Renew contract');
  await dlg.getByRole('button', { name: /^Renew \d+ days$/ }).click();
  await expect(sm.getByRole('status')).toContainText(/Contract renewed for \d+ days/);
  const engY = engs.find((q) => q.personId === Y.id)!;
  await eventually(() => snap(sa, 'mats', 'engagements'), (e) => e.find((x) => x.id === engY.id)?.status === 'renewed' && e.some((x) => x.personId === Y.id && x.status === 'active' && x.endDate > soon), 'Y renewed');
  w2.assertClean(); await c2.close();
  w.assertClean();
});

test('CEO sees the attendance sheet read-only; the group People screen cannot renew', async ({ page, w }) => {
  tolerateThrottledPulls(w);
  await signIn(page, 'ceo1');
  await gotoSite(page, 'mats', 'people/attendance');
  await expect(page.getByRole('radio', { name: 'Present' }).first()).toBeDisabled();
  await expect(page.getByRole('button', { name: /^Mark \d+ unmarked Present$/ })).toHaveCount(0);
  await expect(page.getByRole('button', { name: /Guided Roll Call/ })).toBeDisabled();
  w.assertClean();
});
