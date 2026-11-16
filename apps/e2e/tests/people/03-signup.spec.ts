import { expect, test, goOffline, goOnline } from '../../support/helpers';
import { apiAs, businessDay, eventually, gotoSite, signIn, snap, tolerateThrottledPulls, type Api } from '../../support/people';
import type { Page } from '@playwright/test';

test.describe.configure({ mode: 'serial' });

const stamp = String(Date.now()).slice(-6);
const phone = (n: number) => `07${stamp}${String(n).padStart(2, '0')}`; // 10 digits, unique per run
const people = async (a: Api) => snap(a, 'mats', 'people');
const joinedToday = async (a: Api) => (await people(a)).filter((p) => p.joinedOn === businessDay()).length;

async function capture(page: Page) {
  await page.getByRole('button', { name: /simulate id card photo capture/i }).click();
  await expect(page.getByText('Captured').first()).toBeVisible();
  await page.getByRole('button', { name: /simulate face photo capture/i }).click();
  await expect(page.getByText('Captured')).toHaveCount(2);
}
async function fill(page: Page, f: { name: string; id: string; phone: string; sector: string; rate: string }) {
  await page.getByLabel('Full name').fill(f.name);
  if (f.id) await page.getByLabel('ID number').fill(f.id);
  await page.getByLabel('Mobile money number').fill(f.phone);
  await page.getByRole('radio', { name: f.sector, exact: true }).click();
  await page.locator('input[placeholder="0"]').fill(f.rate);
}
const register = (page: Page) => page.getByRole('button', { name: 'Register worker' });

test('validation: every missing piece is named and nothing is sent until the form is complete', async ({ page, request, w }) => {
  tolerateThrottledPulls(w);
  const a = await apiAs(request, 'admin');
  const existing = (await people(a)).find((p) => p.status === 'active' && /^\d{10}$/.test(p.phoneMomo))!;
  await signIn(page, 'supmats');
  await gotoSite(page, 'mats', 'people/signup');
  await expect(register(page)).toBeDisabled();
  await expect(page.getByText('Still needed: ID photo, face photo, name, phone, sector, rate.')).toBeVisible();
  await expect(page.getByText('0/6')).toBeVisible();
  await capture(page);
  await expect(page.getByText('2/6')).toBeVisible();
  // name needs 3+ characters
  await page.getByLabel('Full name').fill('Al');
  await expect(page.getByText(/Still needed: name, phone, sector, rate\./)).toBeVisible();
  await page.getByLabel('Full name').fill('Alu');
  await expect(page.getByText(/Still needed: phone, sector, rate\./)).toBeVisible();
  // phone: digits only, 10 of them
  await page.getByLabel('Mobile money number').fill('07a1b2-34');
  await expect(page.getByLabel('Mobile money number')).toHaveValue('071234');
  await expect(page.getByText('6/10')).toBeVisible();
  await page.getByLabel('Mobile money number').fill('070000000012345');
  await expect(page.getByLabel('Mobile money number')).toHaveValue('0700000000'); // capped at 10
  // a number already used by an active worker is refused with a plain message
  await page.getByLabel('Mobile money number').fill(existing.phoneMomo);
  await expect(page.getByText(`Already registered to ${existing.fullName}`)).toBeVisible();
  await expect(page.getByText(/One number, one worker/)).toBeVisible();
  await page.getByRole('radio', { name: 'Weaving', exact: true }).click();
  await page.locator('input[placeholder="0"]').fill('15000');
  await expect(register(page)).toBeDisabled();
  await expect(page.getByText(/Still needed: a phone not already in use/)).toBeVisible();
  // rate: no minus sign, zero is not a rate
  await page.locator('input[placeholder="0"]').fill('-500');
  await expect(page.locator('input[placeholder="0"]')).not.toHaveValue(/-/);
  await page.locator('input[placeholder="0"]').fill('0');
  await expect(page.getByText(/rate\./)).toBeVisible();
  // usual-rate chips fill the field
  const chip = page.getByRole('group', { name: 'Usual rates' }).getByRole('radio').first();
  if (await chip.count()) { await chip.click(); await expect(page.locator('input[placeholder="0"]')).not.toHaveValue(''); }
  // Clear resets everything
  await page.getByRole('button', { name: 'Clear' }).click();
  await expect(page.getByText('0/6')).toBeVisible();
  const before = (await people(a)).length;
  expect((await people(a)).length, 'nothing was registered').toBe(before);
  w.assertClean();
});

test('valid sign-up online: registered, server number assigned, mark present, badge, registered-today list', async ({ page, request, w }) => {
  tolerateThrottledPulls(w);
  const a = await apiAs(request, 'admin');
  const name = `Test Online ${stamp}`; const ph = phone(1);
  await signIn(page, 'supmats');
  await gotoSite(page, 'mats', 'people/signup');
  const j0 = await joinedToday(a);
  await expect(page.getByText(`${j0} signed up today`)).toBeVisible();
  await capture(page);
  await fill(page, { name, id: `cm${stamp}01`, phone: ph, sector: 'Weaving', rate: '14000' });
  await expect(page.getByText('Ready to register')).toBeVisible();
  await expect(page.getByText('6/6')).toBeVisible();
  await register(page).click();
  await expect(page.getByText('Registered', { exact: true }).first()).toBeVisible();
  await expect(page.getByRole('status')).toContainText(`${name} signed up`);
  await expect(page.getByText(`${j0 + 1} signed up today`)).toBeVisible();
  await expect(page.locator('main')).toContainText(`CM${stamp}01`); // ID number upper-cased
  const row = await eventually(async () => (await people(a)).filter((p) => p.phoneMomo === ph), (r) => r.length === 1, 'person on server');
  expect(row[0]).toMatchObject({ fullName: name, idNumber: `CM${stamp}01`, sector: 'Weaving', status: 'active' });
  expect(row[0].number).toMatch(/^MAT-\d{4}$/);
  const eng = (await snap(a, 'mats', 'engagements')).filter((e) => e.personId === row[0].id);
  expect(eng).toHaveLength(1);
  expect(eng[0]).toMatchObject({ type: 'casual', rateBasis: 'day', settlementCycle: 'weekly', status: 'active' });
  expect(Number(eng[0].rate)).toBe(14000);
  // the server-assigned number is shown to the user
  await expect(page.locator('main')).toContainText(row[0].number);
  // badge
  await page.getByRole('button', { name: /print qr badge/i }).click();
  await expect(page.getByRole('status')).toContainText('Badge printed');
  // mark present now
  await page.getByRole('button', { name: /mark present now/i }).click();
  await expect(page.getByRole('status')).toContainText('Marked present');
  await expect(page.getByText('On the attendance sheet for today.')).toBeVisible();
  await eventually(() => snap(a, 'mats', 'attendance'), (r) => r.filter((x) => x.personId === row[0].id).length === 1, 'attendance row');
  // the "Registered today" list shows them and opens the registry
  await expect(page.getByRole('complementary').filter({ hasText: 'Registered today' })).toContainText(name);
  // sign up another
  await page.getByRole('button', { name: /sign up another/i }).click();
  await expect(page.getByText('0/6')).toBeVisible();
  await expect(page.getByText(`${j0 + 1} signed up today`)).toBeVisible();
  w.assertClean();
});

test('OFFLINE sign-up: registers locally with no number, then exactly one server person with a real number after reconnecting', async ({ page, request, w }) => {
  tolerateThrottledPulls(w);
  const a = await apiAs(request, 'admin');
  const name = `Test Offline ${stamp}`; const ph = phone(2);
  await signIn(page, 'supmats');
  await gotoSite(page, 'mats', 'people/signup');
  await goOffline(page);
  await capture(page);
  await fill(page, { name, id: `cm${stamp}02`, phone: ph, sector: 'Finishing', rate: '13000' });
  await register(page).click();
  await expect(page.getByText('Registered', { exact: true }).first()).toBeVisible();
  await page.getByRole('button', { name: /mark present now/i }).click();
  await expect(page.getByText('On the attendance sheet for today.')).toBeVisible();
  await page.waitForTimeout(1500);
  expect((await people(a)).filter((p) => p.phoneMomo === ph), 'not on the server while offline').toHaveLength(0);
  await expect(page.locator('main')).not.toContainText(/MAT-\d{4}/); // no invented number while offline
  await goOnline(page);
  const rows = await eventually(async () => (await people(a)).filter((p) => p.phoneMomo === ph), (r) => r.length >= 1, 'person synced', 60_000);
  expect(rows).toHaveLength(1);
  expect(rows[0].number).toMatch(/^MAT-\d{4}$/);
  const att = await eventually(() => snap(a, 'mats', 'attendance'), (r) => r.some((x) => x.personId === rows[0].id), 'attendance synced');
  expect(att.filter((x) => x.personId === rows[0].id)).toHaveLength(1);
  await expect(page.locator('main')).toContainText(rows[0].number, { timeout: 20_000 }); // provisional -> real number shown
  await page.waitForTimeout(2000);
  expect((await people(a)).filter((p) => p.phoneMomo === ph), 'exactly once').toHaveLength(1);
  await expect(page.getByText(/\d+ queued/)).toHaveCount(0);
  w.assertClean();
});

test('sign-up without an ID number stores none (no invented placeholder) and says so', async ({ page, request, w }) => {
  tolerateThrottledPulls(w);
  const a = await apiAs(request, 'admin');
  const name = `Test NoId ${stamp}`; const ph = phone(3);
  await signIn(page, 'supmats');
  await gotoSite(page, 'mats', 'people/signup');
  await capture(page);
  await fill(page, { name, id: '', phone: ph, sector: 'Weaving', rate: '12000' });
  await register(page).click();
  const row = await eventually(async () => (await people(a)).filter((p) => p.phoneMomo === ph), (r) => r.length === 1, 'person on server');
  expect(row[0].idNumber ?? '', 'the form must not invent an ID number').toBe('');
  await expect(page.locator('main')).toContainText('Not recorded');
  w.assertClean();
});

test('server rules (API): a supervisor may register casuals only; a second person on the same phone is not refused', async ({ request }) => {
  const sup = await apiAs(request, 'supmats');
  const base = { fullName: `Test Api ${stamp}`, idNumber: `API${stamp}`, phoneMomo: phone(4), sector: 'Weaving', engagement: { type: 'casual', rateBasis: 'day', rate: 12000 } };
  const contract = await sup.post('/sites/mats/people', { ...base, phoneMomo: phone(5), engagement: { type: 'contract', rateBasis: 'day', rate: 12000, termDays: 30 } });
  expect(contract.status(), 'supervisor registering a contract worker').toBe(403);
  expect((await contract.json()).detail ?? (await contract.json()).message).toBeTruthy();
  const empty = await sup.post('/sites/mats/people', { ...base, fullName: '', phoneMomo: phone(6) });
  expect(empty.status(), 'empty name').toBe(422);
  const ok = await sup.post('/sites/mats/people', base);
  expect(ok.status()).toBeLessThan(300);
});

test('a second registration with the same ID number, or the same phone and name, is refused with a plain message', async ({ request }) => {
  const sup = await apiAs(request, 'supmats');
  const base = { fullName: `Test Dup ${stamp}`, idNumber: `DUP${stamp}`, phoneMomo: phone(7), sector: 'Weaving', engagement: { type: 'casual', rateBasis: 'day', rate: 12000 } };
  expect((await sup.post('/sites/mats/people', base)).status()).toBeLessThan(300);
  const sameId = await sup.post('/sites/mats/people', { ...base, fullName: `Test Other ${stamp}`, phoneMomo: phone(8) });
  expect(sameId.status()).toBe(409);
  expect((await sameId.json()).detail).toMatch(/already/i);
  const samePhoneName = await sup.post('/sites/mats/people', { ...base, idNumber: `DUQ${stamp}` });
  expect(samePhoneName.status()).toBe(409);
});

test('who sees the people screens: navigation per role', async ({ browser }) => {
  const expected: Record<string, { open: string; links: string[] }> = {
    supmats: { open: 'people/attendance', links: ['Attendance', 'Casual sign-up', 'Workers'] },
    smmats: { open: 'people/attendance', links: ['Attendance', 'Casual sign-up', 'Workers', 'Wages'] },
    cashmats: { open: 'people/settlement', links: ['People'] }, // one hub link (to Wages); no Attendance, Sign-up or Workers
  };
  for (const [user, { open, links }] of Object.entries(expected)) {
    const ctx = await browser.newContext(); const page = await ctx.newPage();
    await signIn(page, user);
    await gotoSite(page, 'mats', open);
    const nav = page.locator('nav[aria-label="Main"]').first();
    const labels = await nav.locator('a[href*="/people/"]').evaluateAll((as) => as.map((a) => (a.textContent ?? '').trim()));
    expect.soft([...labels].sort(), `${user} people links`).toEqual([...links].sort());
    await ctx.close();
  }
  // roles with no people screens do not get the People hub at all
  for (const user of ['storemats', 'salesmats']) {
    const ctx = await browser.newContext(); const page = await ctx.newPage();
    await signIn(page, user);
    await expect.soft(page.locator('nav[aria-label="Main"]').first().getByRole('link', { name: 'People' })).toHaveCount(0);
    await ctx.close();
  }
});
