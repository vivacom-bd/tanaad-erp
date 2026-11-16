import { expect, test } from '../../support/helpers';
import { apiAs, businessDay, eventually, gotoGroup, signIn, snap, tolerateThrottledPulls } from '../../support/people';

test.describe.configure({ mode: 'serial' });
type R = Record<string, any>;

test('CEO sees the ghost-worker and contract-expiring exceptions the server raised', async ({ page, request, w }) => {
  tolerateThrottledPulls(w);
  const ceo = await apiAs(request, 'ceo2');
  const all = (await ceo.json('/exceptions')).data as R[];
  const open = (k: string) => all.filter((e) => e.kind === k && e.status === 'open');
  expect(open('ghost_worker').length).toBeGreaterThan(0); expect(open('contract_expiring').length).toBeGreaterThan(0);
  await signIn(page, 'ceo1'); await gotoGroup(page, 'approvals');
  for (const [label, kind] of [['Ghost worker', 'ghost_worker'], ['Contract expiring', 'contract_expiring']] as const) {
    await page.getByRole('tab', { name: new RegExp(`^${label}`) }).click();
    await expect(page.getByRole('tab', { name: new RegExp(`^${label}`) })).toContainText(String(open(kind).length));
    const first = open(kind)[0];
    await expect(page.getByRole('button', { name: new RegExp(first.title.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')) }).first()).toBeVisible();
  }
  // detail of a ghost-worker exception names the worker and the engagement
  await page.getByRole('tab', { name: /^Ghost worker/ }).click();
  await page.getByRole('button', { name: /marked at two sites/ }).first().click();
  await expect(page.locator('main')).toContainText(/Worker/);
  await expect(page.locator('main')).toContainText(/Engagement/);
  w.assertClean();
});

test('CEO holds a ghost-worker exception with a reason; the server records it', async ({ page, request, w }) => {
  tolerateThrottledPulls(w);
  const ceo = await apiAs(request, 'ceo2');
  const before = ((await ceo.json('/exceptions')).data as R[]).filter((e) => e.kind === 'contract_expiring' && e.status === 'open');
  const target = before[0];
  await signIn(page, 'ceo1'); await gotoGroup(page, 'approvals');
  await page.getByRole('tab', { name: /^Contract expiring/ }).click();
  await page.getByRole('button', { name: new RegExp(target.title.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')) }).first().click();
  await page.getByRole('button', { name: 'Hold', exact: true }).last().click(); // the detail panel's, after the two order cards
  const confirm = page.getByRole('button', { name: 'Confirm hold' });
  await page.getByPlaceholder(/note required/i).fill('Renewal paperwork with the site manager');
  await confirm.click();
  await eventually(async () => ((await ceo.json('/exceptions')).data as R[]).find((e) => e.id === target.id), (e) => !!e && e.status !== 'open' || !!e?.onHold || /held|rejected/.test(String(e?.status)), 'hold recorded', 20_000);
  w.assertClean();
});

test('group People screen: contracts ending match the server; the CEO cannot renew, the group admin can', async ({ browser, request, w }) => {
  tolerateThrottledPulls(w);
  const adm = await apiAs(request, 'admin');
  const people = await snap(adm, 'mats', 'people'); const engs = await snap(adm, 'mats', 'engagements');
  const soon = new Date(Date.parse(businessDay()) + 3 * 86400000).toISOString().slice(0, 10);
  const expired = engs.filter((e) => e.type === 'contract' && e.status === 'active' && e.endDate <= soon && people.find((p) => p.id === e.personId)?.status === 'active')
    .map((e) => ({ e, p: people.find((p) => p.id === e.personId)! })).sort((a, b) => a.p.fullName.localeCompare(b.p.fullName));
  const c1 = await browser.newContext(); const ceo = await c1.newPage();
  await signIn(ceo, 'ceo1'); await gotoGroup(ceo, 'people');
  await expect(ceo.getByText('Contracts ending').first()).toBeVisible();
  await expect(ceo.getByText(expired[0].p.fullName).first()).toBeVisible();
  await expect(ceo.getByRole('button', { name: 'Renew', exact: true })).toHaveCount(0);
  await expect(ceo.getByRole('button', { name: /Renew the most urgent|Renew next/ })).toBeDisabled();
  await c1.close();
  const c2 = await browser.newContext(); const admin = await c2.newPage();
  await signIn(admin, 'admin'); await gotoGroup(admin, 'people');
  const target = expired[0];
  await admin.getByRole('row').filter({ hasText: target.p.fullName }).getByRole('button', { name: 'Renew', exact: true }).click();
  await admin.getByRole('dialog').getByRole('button', { name: /^Renew/ }).last().click();
  await expect(admin.getByRole('status')).toContainText(/contract renewed/i);
  await eventually(() => snap(adm, 'mats', 'engagements'), (r) => r.find((x) => x.id === target.e.id)?.status === 'renewed', 'renewed by the group admin');
  await c2.close();
  w.assertClean();
});
