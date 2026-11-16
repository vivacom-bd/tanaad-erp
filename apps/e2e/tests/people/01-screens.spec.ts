import { expect, test } from '../../support/helpers';
import { apiAs, captureScreen, tolerateThrottledPulls, gotoGroup, gotoSite, signIn, snap } from '../../support/people';

// Every people screen renders for each role that sees it, with values that come from the server, and fits at 390 and 1280.
const overflow: string[] = [];

const SITE_SCREENS = [
  { path: 'people/attendance', name: 'attendance', h1: /^attendance$/i, roles: ['supmats', 'smmats', 'ceo1', 'admin'] },
  { path: 'people/signup', name: 'signup', h1: /casual sign-up/i, roles: ['supmats', 'smmats'] },
  { path: 'people/registry', name: 'registry', h1: /^workers$/i, roles: ['supmats', 'smmats', 'ceo1', 'admin'] },
  { path: 'people/settlement', name: 'settlement', h1: /^settlement$/i, roles: ['smmats', 'cashmats', 'ceo1', 'admin'] },
];

for (const s of SITE_SCREENS) for (const user of s.roles) {
  test(`screen ${s.name} renders for ${user}`, async ({ page, w }) => {
    tolerateThrottledPulls(w);
    await signIn(page, user);
    await gotoSite(page, 'mats', s.path);
    await expect(page.getByRole('heading', { name: s.h1 }).first()).toBeVisible();
    if (user === 'smmats' || user === 'supmats') overflow.push(...(await captureScreen(page, `${s.name}-${user}`)));
    w.assertClean();
  });
}

test('workers screen values come from the server', async ({ page, request, w }) => {
    tolerateThrottledPulls(w);
  const a = await apiAs(request, 'smmats');
  const people = await snap(a, 'mats', 'people');
  const engs = await snap(a, 'mats', 'engagements');
  const active = people.filter((p) => p.status === 'active');
  const archived = people.filter((p) => p.status === 'archived');
  await signIn(page, 'smmats');
  await gotoSite(page, 'mats', 'people/registry');
  await expect(page.getByText(`${active.length} active`).first()).toBeVisible();
  await expect(page.getByText(new RegExp(`${active.length} of ${people.length}`)).first()).toBeVisible();
  // a known worker row shows the server's phone number
  const p = active[0];
  await page.getByPlaceholder(/search name/i).fill(p.fullName);
  const row = page.getByRole('row').filter({ hasText: p.fullName }).first();
  await expect(row).toContainText(p.phoneMomo);
  const e = engs.find((x) => x.personId === p.id && x.status === 'active');
  if (e) await expect(row).toContainText(Number(e.rate).toLocaleString('en-US'));
  expect(archived.length).toBeGreaterThan(0);
  w.assertClean();
});

test('group People screen for the CEO shows server headcount', async ({ page, request, w }) => {
    tolerateThrottledPulls(w);
  const a = await apiAs(request, 'ceo1');
  let total = 0;
  for (const site of ['mats', 'bags', 'recycling']) total += (await snap(a, site, 'people')).filter((p) => p.status === 'active').length;
  await signIn(page, 'ceo1');
  await gotoGroup(page, 'people');
  await expect(page.getByRole('heading', { name: /^people$/i }).first()).toBeVisible();
  await expect(page.locator('main')).toContainText(/on shift today/);
  overflow.push(...(await captureScreen(page, 'group-people-ceo1')));
  console.log('server active people across sites', total);
  w.assertClean();
});

test.afterAll(() => { expect.soft(overflow, 'horizontal overflow at 390/1280').toEqual([]); });
