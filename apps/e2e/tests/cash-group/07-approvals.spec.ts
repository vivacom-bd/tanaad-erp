import { expect, test } from '../../support/helpers';
import { enter, spaGo } from '../../support/nav';
import { apiAs, shot } from '../../support/cashgroup';

test.use({ actionTimeout: 20_000 });
test.describe.configure({ mode: 'serial', timeout: 180_000 });
const R = Date.now().toString(36).slice(-4);
const T = (s: string) => `E2E ${s} ${R}`;

type Ex = { id: string; siteCode: string; kind: string; status: string; title: string; ownerRole: string; decisionReason?: string | null; raisedBy?: string | null };
const list = async (request: import('@playwright/test').APIRequestContext): Promise<Ex[]> => (await (await apiAs(request, 'admin')).json('/exceptions')).data;
const byTitle = async (request: import('@playwright/test').APIRequestContext, title: string) => (await list(request)).find((e) => e.title === title);

async function raiseAs(request: import('@playwright/test').APIRequestContext, user: string, site: string, title: string, severity = 'warning') {
  const r = await (await apiAs(request, user)).post(`/sites/${site}/exceptions`, { kind: 'low_stock', severity, title, detail: 'Raised by the e2e suite' });
  expect(r.status(), await r.text()).toBe(201);
}

const bar = (page: import('@playwright/test').Page) => page.getByLabel('Reason').locator('xpath=..');

async function openApprovals(page: import('@playwright/test').Page, search?: string) {
  await spaGo(page, '/g/approvals');
  await expect(page.getByRole('heading', { name: 'Approvals' })).toBeVisible();
  if (search) await page.getByPlaceholder('Search exceptions').fill(search);
}

test('approvals inbox matches the server: totals, site and kind filters', async ({ page, request, w }) => {
  const all = await list(request);
  const open = all.filter((e) => e.status === 'open' || e.status === 'held');
  await enter(page, 'ceo1', '/g/approvals');
  await expect(page.getByRole('heading', { name: 'Approvals' })).toBeVisible();
  await expect(page.getByText(`${open.length} open`).first()).toBeVisible();
  for (const site of ['mats', 'bags', 'recycling'] as const) {
    const n = open.filter((e) => e.siteCode === site).length;
    const label = { mats: 'Mats', bags: 'Bags', recycling: 'Recycling' }[site];
    await expect(page.getByRole('tab', { name: new RegExp(`^${label}\\s*${n}$`) })).toBeVisible();
  }
  // kind filter narrows the list; search narrows further; clearing restores
  await page.getByRole('tab', { name: /^Cash variance/ }).click();
  const cv = open.filter((e) => e.kind === 'cash_variance').length;
  await expect(page.getByText(`${cv} of ${open.length} shown`)).toBeVisible();
  await page.getByRole('tab', { name: /^All kinds/ }).click();
  await page.getByPlaceholder('Search exceptions').fill('zzzz-no-such');
  await expect(page.getByText('Nothing matches these filters')).toBeVisible();
  await shot(page, 'approvals-no-match');
  await page.getByPlaceholder('Search exceptions').fill('');
  await shot(page, 'approvals-inbox');
  w.assertClean();
});

test('approve, hold (note required) and resolve an exception raised by a site manager', async ({ page, request, w }) => {
  await raiseAs(request, 'smmats', 'mats', T('approve'));
  await raiseAs(request, 'smmats', 'mats', T('hold'));
  await raiseAs(request, 'smmats', 'mats', T('resolve'));
  await enter(page, 'ceo1');
  await spaGo(page, '/g/approvals');
  const pick = async (t: string) => { await page.getByPlaceholder('Search exceptions').fill(t); await page.getByRole('button', { name: new RegExp(t) }).first().click(); };

  // Hold without a note: blocked with a plain message
  await pick(T('hold'));
  await bar(page).getByRole('button', { name: 'Hold', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Confirm hold' })).toBeDisabled(); // a hold cannot be sent without a note
  expect((await byTitle(request, T('hold')))!.status).toBe('open');
  await shot(page, 'approvals-hold-needs-note');
  await page.getByLabel('Reason').fill('Waiting for the stock count');
  await page.getByRole('button', { name: 'Confirm hold' }).click();
  await expect(page.getByText(`On hold: ${T('hold')}`)).toBeVisible();
  await expect.poll(async () => (await byTitle(request, T('hold')))?.status, { timeout: 20_000 }).toBe('held');
  expect((await byTitle(request, T('hold')))!.decisionReason).toBe('Waiting for the stock count');

  // Approve with no reason
  await pick(T('approve'));
  await bar(page).getByRole('button', { name: 'Approve', exact: true }).click();
  await page.getByRole('button', { name: 'Confirm approve' }).click();
  await expect(page.getByText(`Approved: ${T('approve')}`)).toBeVisible();
  await expect.poll(async () => (await byTitle(request, T('approve')))?.status, { timeout: 20_000 }).toBe('approved');

  // Resolve (acknowledge) with a reason
  await pick(T('resolve'));
  await bar(page).getByRole('button', { name: 'Resolve', exact: true }).click();
  await page.getByLabel('Reason').fill('Counted and fine');
  await page.getByRole('button', { name: 'Confirm resolve' }).click();
  await expect(page.getByText(`Resolved: ${T('resolve')}`)).toBeVisible();
  await expect.poll(async () => (await byTitle(request, T('resolve')))?.status, { timeout: 20_000 }).toBe('acknowledged');

  // Decided today tab lists the decided ones
  await page.getByPlaceholder('Search exceptions').fill(`E2E`);
  await page.getByRole('tab', { name: /^Decided today/ }).click();
  await expect(page.getByText(T('approve')).first()).toBeVisible();
  await expect(page.getByText(T('resolve')).first()).toBeVisible();
  await shot(page, 'approvals-decided-today');
  w.assertClean();
});

test('a decided exception cannot be decided twice (409 shown as a plain message)', async ({ page, request, w }) => {
  w.allow(/409|state_conflict/);
  await raiseAs(request, 'smmats', 'mats', T('twice'));
  await enter(page, 'ceo1', '/g/approvals');
  await page.getByPlaceholder('Search exceptions').fill(T('twice'));
  await page.getByRole('button', { name: new RegExp(T('twice')) }).first().click();
  // another decision-maker gets there first
  const ex = (await byTitle(request, T('twice')))!;
  const r = await (await apiAs(request, 'admin')).post(`/sites/mats/exceptions/${ex.id}/decision`, { decision: 'acknowledge', note: 'Done by admin' });
  expect(r.ok(), await r.text()).toBeTruthy();
  await bar(page).getByRole('button', { name: 'Approve', exact: true }).click();
  await page.getByRole('button', { name: 'Confirm approve' }).click();
  await page.getByRole('button', { name: 'Sync problems' }).waitFor({ timeout: 30_000 });
  await page.getByRole('button', { name: 'Sync problems' }).click();
  await expect(page.getByText(/already acknowledged/)).toBeVisible();
  await shot(page, 'approvals-already-decided');
  w.assertClean();
});

test('the CEO cannot decide an exception they raised themselves; the app says so', async ({ page, request, w }) => {
  w.allow(/403|forbidden/i);
  await enter(page, 'ceo1', '/g/approvals');
  await page.getByRole('button', { name: 'Raise exception' }).click();
  const sheet = page.getByRole('dialog');
  const send = sheet.getByRole('button', { name: /Send|Raise|Save/ }).last();
  await expect(send).toBeDisabled();
  await sheet.getByLabel('Title').fill(T('own'));
  await sheet.getByLabel('Detail').fill('CEO raised this');
  await shot(page, 'approvals-raise-sheet');
  await expect(send).toBeEnabled();
  await send.click();
  await expect(page.getByText('Exception sent')).toBeVisible();
  await expect.poll(async () => (await byTitle(request, T('own')))?.raisedBy, { timeout: 20_000 }).toBeTruthy();
  await page.getByPlaceholder('Search exceptions').fill(T('own'));
  await page.getByRole('button', { name: new RegExp(T('own')) }).first().click();
  await bar(page).getByRole('button', { name: 'Approve', exact: true }).click();
  await page.getByRole('button', { name: 'Confirm approve' }).click();
  await page.getByRole('button', { name: 'Sync problems' }).waitFor({ timeout: 30_000 });
  await page.getByRole('button', { name: 'Sync problems' }).click();
  await expect(page.getByText('You raised this exception; someone else must decide it.')).toBeVisible();
  expect((await byTitle(request, T('own')))!.status).toBe('open');
  w.assertClean();
});

test('site manager: the group pages are closed to him; he decides his own site cash variance through the API only', async ({ page, request, w }) => {
  w.allow(/403|forbidden/i);
  const cv = (await list(request)).find((e) => e.kind === 'cash_variance' && e.siteCode === 'mats' && e.status === 'open');
  expect(cv, 'a Mats close-day exception from the cash specs').toBeTruthy();
  await enter(page, 'smmats', '/g/approvals');
  await expect(page.getByText("You don't have access to this page")).toBeVisible();
  await shot(page, 'approvals-site-manager-no-access');
  const sm = await apiAs(request, 'smmats');
  const r = await sm.post(`/sites/mats/exceptions/${cv!.id}/decision`, { decision: 'acknowledge', note: 'Recounted with the cashier' });
  expect(r.ok(), await r.text()).toBeTruthy();
  expect((await byTitle(request, cv!.title))!.status).toBe('acknowledged');
  // a CEO-owned exception is refused to him
  const ceoOwned = (await list(request)).find((e) => e.ownerRole === 'ceo' && e.status === 'open');
  if (ceoOwned) expect((await sm.post(`/sites/${ceoOwned.siteCode}/exceptions/${ceoOwned.id}/decision`, { decision: 'acknowledge', note: 'try' })).status()).toBe(403);
});
