import { expect, test } from '../../support/helpers';
import { apiAs, businessDay, eventually, gotoSite, signIn, snap, tolerateThrottledPulls, type Api } from '../../support/people';

test.describe.configure({ mode: 'serial' });
const TODAY = businessDay();
type R = Record<string, any>;
const search = (page: any) => page.getByPlaceholder(/search name/i);
const rowOf = (page: any, name: string) => page.getByRole('row').filter({ hasText: name }).first();

async function facts(a: Api) {
  const people = await snap(a, 'mats', 'people'); const engs = await snap(a, 'mats', 'engagements');
  const eng = (p: R) => engs.find((e) => e.personId === p.id && e.status === 'active');
  return { people, engs, eng, active: people.filter((p) => p.status === 'active'), archived: people.filter((p) => p.status === 'archived') };
}

test('KPI strip, type tabs and counts match the server', async ({ page, request, w }) => {
  tolerateThrottledPulls(w);
  const a = await apiAs(request, 'admin'); const f = await facts(a);
  await signIn(page, 'smmats'); await gotoSite(page, 'mats', 'people/registry');
  const byType = (t: string) => f.active.filter((p) => f.eng(p)?.type === t).length;
  await expect(page.getByText(`${f.active.length} active`).first()).toBeVisible();
  for (const [t, label] of [['permanent', 'Permanent'], ['contract', 'Contract'], ['casual', 'Casual']] as const) {
    await expect(page.getByRole('tab', { name: new RegExp(`^${label}`) })).toContainText(String(byType(t)));
  }
  await expect(page.getByText('Archived', { exact: true }).first()).toBeVisible();
  const ending = f.active.filter((p) => { const e = f.eng(p); return e?.type === 'contract' && e.endDate && e.endDate <= new Date(Date.parse(TODAY) + 3 * 86400000).toISOString().slice(0, 10); });
  await expect(page.getByText(new RegExp(`${ending.length} contracts? ending`)).first()).toBeVisible();
  await page.getByRole('tab', { name: /^Contract/ }).click();
  await expect(page.getByText(new RegExp(`${byType('contract')} of ${f.people.length}`))).toBeVisible();
  w.assertClean();
});

test('search by name, phone, ID and worker number; sector and status filters; clear; empty state', async ({ page, request, w }) => {
  tolerateThrottledPulls(w);
  const a = await apiAs(request, 'admin'); const f = await facts(a);
  const p = f.active.find((x) => f.eng(x)?.type === 'casual')!;
  await signIn(page, 'smmats'); await gotoSite(page, 'mats', 'people/registry');
  for (const needle of [p.fullName.toLowerCase(), p.phoneMomo, p.idNumber, p.number]) {
    await search(page).fill(needle);
    await expect(rowOf(page, p.fullName)).toBeVisible();
  }
  await search(page).fill('qqqq-nobody');
  await expect(page.getByText('No workers match')).toBeVisible();
  await page.getByRole('button', { name: 'Clear filters' }).first().click();
  await expect(search(page)).toHaveValue('');
  // sector
  await page.getByLabel('Sector').selectOption(p.sector);
  const inSector = f.active.filter((x) => x.sector === p.sector).length;
  await expect(page.getByText(new RegExp(`${inSector} of ${f.people.length}`))).toBeVisible();
  // status = archived shows the server's archived workers with an Archived badge
  await page.getByLabel('Sector').selectOption('all');
  await page.getByLabel('Status').selectOption('archived');
  await expect(page.getByText(new RegExp(`${f.archived.length} of ${f.people.length}`))).toBeVisible();
  await expect(rowOf(page, f.archived[0].fullName)).toContainText('Archived');
  await page.getByLabel('Status').selectOption('all');
  await expect(page.getByText(new RegExp(`${f.people.length} of ${f.people.length}`))).toBeVisible();
  // export
  const dl = page.waitForEvent('download'); await page.getByRole('button', { name: 'Export' }).click();
  expect((await dl).suggestedFilename()).toMatch(/^mats-workers-\d{4}-\d{2}-\d{2}\.csv$/);
  w.assertClean();
});

test('worker profile shows the server record, attendance strip and engagement history', async ({ page, request, w }) => {
  tolerateThrottledPulls(w);
  const a = await apiAs(request, 'admin'); const f = await facts(a);
  const p = f.active.find((x) => f.eng(x)?.type === 'permanent') ?? f.active[0]; const e = f.eng(p)!;
  await signIn(page, 'smmats'); await gotoSite(page, 'mats', 'people/registry');
  await search(page).fill(p.fullName);
  await rowOf(page, p.fullName).click();
  const sheet = page.getByRole('dialog').last();
  await expect(sheet).toContainText(p.fullName);
  await expect(sheet).toContainText(p.phoneMomo); await expect(sheet).toContainText(p.idNumber); await expect(sheet).toContainText(p.number);
  await expect(sheet).toContainText(Number(e.rate).toLocaleString('en-US'));
  await expect(sheet).toContainText('Attendance, last 14 working days');
  await expect(sheet).toContainText('Engagement history');
  await expect(sheet).toContainText('Paid from the cash book');
  await page.keyboard.press('Escape');
  await expect(sheet).toBeHidden();
  w.assertClean();
});

test('renew a contract from the "ending" list: new engagement on the server, old one renewed', async ({ page, request, w }) => {
  tolerateThrottledPulls(w);
  const a = await apiAs(request, 'admin'); const f = await facts(a);
  const soon = new Date(Date.parse(TODAY) + 3 * 86400000).toISOString().slice(0, 10);
  const target = f.active.filter((p) => { const e = f.eng(p); return e?.type === 'contract' && e.endDate && e.endDate <= soon; }).sort((x, y) => x.fullName.localeCompare(y.fullName)).at(-1)!; // the last by name: the attendance spec renews the first
  const old = f.eng(target)!;
  await signIn(page, 'smmats'); await gotoSite(page, 'mats', 'people/registry');
  await page.getByLabel('Status').selectOption('ending');
  await expect(rowOf(page, target.fullName)).toBeVisible();
  await rowOf(page, target.fullName).getByRole('button', { name: 'Renew' }).click();
  const dlg = page.getByRole('dialog'); await expect(dlg).toContainText('Renew contract');
  const terms = dlg.getByRole('radiogroup', { name: 'Term' }).getByRole('radio');
  expect(await terms.count(), 'contract terms come from master data').toBeGreaterThan(0);
  const last = terms.last(); const label = (await last.innerText()).trim(); await last.click();
  const days = Number(label.match(/\d+/)?.[0] ?? 0);
  await dlg.getByRole('button', { name: /^Renew \d+ days$/ }).click();
  await expect(page.getByRole('status')).toContainText(/contract renewed/i);
  const engs = await eventually(() => snap(a, 'mats', 'engagements'), (r) => r.some((e) => e.personId === target.id && e.renewsEngagementId === old.id) && r.find((e) => e.id === old.id)?.status === 'renewed', 'renewed engagement');
  const mine = engs.filter((e) => e.personId === target.id);
  expect(mine.find((e) => e.id === old.id)!.status).toBe('renewed');
  const fresh = mine.find((e) => e.status === 'active')!;
  expect(fresh).toMatchObject({ type: 'contract', startDate: TODAY, renewsEngagementId: old.id });
  if (days) expect(fresh.termDays).toBe(days);
  w.assertClean();
});

test('end an engagement needs a reason; archive and reactivate round-trip to the server', async ({ page, request, w }) => {
  tolerateThrottledPulls(w);
  const a = await apiAs(request, 'admin'); const f = await facts(a);
  const [p1, p2] = f.active.filter((p) => f.eng(p)?.type === 'casual').slice(-2);
  await signIn(page, 'smmats'); await gotoSite(page, 'mats', 'people/registry');
  // end engagement of p1
  await search(page).fill(p1.fullName); await rowOf(page, p1.fullName).click();
  const sheet = page.getByRole('dialog').last();
  await sheet.getByRole('button', { name: 'End engagement' }).click();
  const confirm = sheet.getByRole('button', { name: 'Confirm end' });
  await expect(confirm).toBeDisabled();
  await sheet.getByLabel('Reason for ending the engagement').fill('   ');
  await expect(confirm).toBeDisabled();
  await sheet.getByRole('button', { name: 'Back' }).click();
  await expect(sheet.getByRole('button', { name: 'End engagement' })).toBeVisible();
  await sheet.getByRole('button', { name: 'End engagement' }).click();
  await sheet.getByLabel('Reason for ending the engagement').fill('Left for another job');
  await confirm.click();
  await expect(page.getByRole('status')).toContainText('engagement ended');
  const e1 = await eventually(() => snap(a, 'mats', 'engagements'), (r) => r.some((e) => e.personId === p1.id && e.status === 'ended'), 'engagement ended');
  expect(e1.find((e) => e.personId === p1.id && e.status === 'ended')!.endReason).toBe('Left for another job');
  // archive p2
  await page.keyboard.press('Escape');
  await search(page).fill(p2.fullName); await rowOf(page, p2.fullName).click();
  await page.getByRole('dialog').last().getByRole('button', { name: 'Archive worker' }).click();
  await expect(page.getByRole('status')).toContainText(`${p2.fullName} archived`);
  await eventually(() => snap(a, 'mats', 'people'), (r) => r.find((x) => x.id === p2.id)?.status === 'archived', 'archived on server');
  await expect(rowOf(page, p2.fullName)).toHaveCount(0); // gone from the active list
  await page.getByLabel('Status').selectOption('archived');
  await rowOf(page, p2.fullName).click();
  await page.getByRole('dialog').last().getByRole('button', { name: 'Reactivate' }).click();
  await expect(page.getByRole('status')).toContainText(`${p2.fullName} reactivated`);
  await eventually(() => snap(a, 'mats', 'people'), (r) => r.find((x) => x.id === p2.id)?.status === 'active', 'reactivated on server');
  w.assertClean();
});

test('reactivate from the Attendance "Archived Workers" tab', async ({ page, request, w }) => {
  tolerateThrottledPulls(w);
  const a = await apiAs(request, 'admin'); const f = await facts(a);
  const p = f.archived[0];
  await signIn(page, 'smmats'); await gotoSite(page, 'mats', 'people/attendance');
  await page.getByRole('tab', { name: /Archived Workers/ }).click();
  await page.getByRole('row').filter({ hasText: p.fullName }).getByRole('button', { name: 'Reactivate' }).click();
  await expect(page.getByRole('status')).toContainText(`${p.fullName} is back on the roster`);
  await eventually(() => snap(a, 'mats', 'people'), (r) => r.find((x) => x.id === p.id)?.status === 'active', 'reactivated on server');
  w.assertClean();
});

test('OFFLINE archive: queued while offline, one archive event on the server after reconnecting', async ({ page, request, w }) => {
  tolerateThrottledPulls(w);
  const a = await apiAs(request, 'admin'); const f = await facts(a);
  const p = f.active.filter((x) => f.eng(x)?.type === 'casual')[3];
  await signIn(page, 'smmats'); await gotoSite(page, 'mats', 'people/registry');
  await search(page).fill(p.fullName); await rowOf(page, p.fullName).click();
  const { goOffline, goOnline } = await import('../../support/helpers');
  await goOffline(page);
  await page.getByRole('dialog').last().getByRole('button', { name: 'Archive worker' }).click();
  await expect(page.getByRole('status')).toContainText('archived');
  await page.waitForTimeout(1200);
  expect((await snap(a, 'mats', 'people')).find((x) => x.id === p.id)!.status).toBe('active');
  await goOnline(page);
  await eventually(() => snap(a, 'mats', 'people'), (r) => r.find((x) => x.id === p.id)?.status === 'archived', 'archived after reconnect', 45_000);
  await expect(page.getByText(/\d+ queued/)).toHaveCount(0);
  w.assertClean();
});

test('a supervisor may open the registry but is not offered Archive/End/Renew; the server refuses archive in plain words', async ({ page, request, w }) => {
  tolerateThrottledPulls(w);
  const a = await apiAs(request, 'admin'); const f = await facts(a);
  const p = f.active.filter((x) => f.eng(x)?.type === 'casual')[5];
  await signIn(page, 'supmats'); await gotoSite(page, 'mats', 'people/registry');
  await search(page).fill(p.fullName); await rowOf(page, p.fullName).click();
  const sheet = page.getByRole('dialog').last();
  await expect(sheet).toContainText(p.fullName);
  for (const b of ['Archive worker', 'End engagement', 'Reactivate']) await expect(sheet.getByRole('button', { name: b })).toHaveCount(0);
  const r = await (await apiAs(request, 'supmats')).post(`/sites/mats/people/${p.id}/archive`, { reason: 'x' });
  expect(r.status()).toBe(403);
  expect((await r.json()).detail).toBe('This role may not do this at this site.');
  expect((await snap(a, 'mats', 'people')).find((x) => x.id === p.id)!.status, 'server refused').toBe('active');
  // the CEO may archive and reactivate (but not end an engagement or renew)
  const c2 = await page.context().browser()!.newContext(); const ceo = await c2.newPage();
  await signIn(ceo, 'ceo1'); await gotoSite(ceo, 'mats', 'people/registry');
  await search(ceo).fill(p.fullName); await rowOf(ceo, p.fullName).click();
  const cs = ceo.getByRole('dialog').last();
  await expect(cs.getByRole('button', { name: 'End engagement' })).toHaveCount(0);
  await expect(cs.getByRole('button', { name: 'Archive worker' })).toBeVisible();
  await cs.getByRole('button', { name: 'Archive worker' }).click();
  await eventually(() => snap(a, 'mats', 'people'), (r2) => r2.find((x) => x.id === p.id)?.status === 'archived', 'CEO archived');
  await c2.close();
  w.assertClean();
});
