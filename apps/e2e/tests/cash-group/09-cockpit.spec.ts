import { expect, test } from '../../support/helpers';
import { enter, spaGo } from '../../support/nav';
import { apiAs, shot } from '../../support/cashgroup';

test.use({ actionTimeout: 20_000 });
test.describe.configure({ mode: 'serial', timeout: 180_000 });

const compact = (n: number) => (Math.abs(n) >= 1_000_000 ? `${(n / 1_000_000).toFixed(2)}M` : Math.abs(n) >= 1_000 ? `${Math.round(n / 1_000)}k` : `${Math.round(n)}`);
type Site = { siteCode: string; name: string; cash: { inSafe: number; asOf: string | null }; exceptions: { total: number; critical: number }; machinesDown: { count: number }; headcount: { total: number }; dispatches: { held: number } };

test('cockpit tiles are the server figures, per site and in total', async ({ page, request, w }) => {
  const cockpit = (await (await apiAs(request, 'admin')).json('/group/cockpit')).data as { day: string; sites: Site[] };
  await enter(page, 'ceo1', '/g/cockpit');
  await expect(page.getByRole('heading', { name: 'Group overview' })).toBeVisible();
  const total = cockpit.sites.reduce((n, s) => n + s.exceptions.total, 0);
  const critical = cockpit.sites.reduce((n, s) => n + s.exceptions.critical, 0);
  await expect(page.getByRole('button', { name: `To decide ${total} ${critical} critical` })).toBeVisible();
  for (const s of cockpit.sites) {
    const panel = page.locator('section, div').filter({ has: page.getByRole('button', { name: 'Open portal' }) }).filter({ hasText: s.name }).last();
    await expect(panel.getByText(s.name).first()).toBeVisible();
    await expect(panel.getByText(s.cash.asOf ? compact(s.cash.inSafe) : '—').first()).toBeVisible();
    if (s.cash.asOf && s.cash.asOf !== cockpit.day) await expect(panel.getByText(`From the cash page of ${s.cash.asOf}; none opened today`)).toBeVisible();
    await expect(panel.getByText('To decide').first()).toBeVisible();
  }
  await shot(page, 'cockpit');
  expect(total).toBeGreaterThan(0);
  w.assertClean();
});

test('cockpit: refresh, jump to approvals and credit, open a site portal', async ({ page, w }) => {
  await enter(page, 'ceo1', '/g/cockpit');
  await page.getByRole('button', { name: 'Refresh' }).click();
  await expect(page.getByRole('button', { name: 'Refresh' })).toBeEnabled();
  await page.getByRole('button', { name: /^Approvals/ }).first().click();
  await expect(page).toHaveURL(/\/g\/approvals$/);
  await spaGo(page, '/g/cockpit');
  await page.getByRole('button', { name: /^Receivables/ }).first().click();
  await expect(page).toHaveURL(/\/g\/credit$/);
  await spaGo(page, '/g/cockpit');
  await page.getByRole('button', { name: 'Open portal' }).first().click();
  await expect(page).toHaveURL(/\/s\/(bags|mats|recycling)\/dashboard$/);
  w.assertClean();
});

test('group admin reads the cockpit too; a site manager gets the no-access screen', async ({ browser, w }) => {
  w.allow(/403/);
  const ctx = await browser.newContext(); const page = await ctx.newPage();
  await enter(page, 'admin', '/g/cockpit');
  await expect(page.getByRole('heading', { name: 'Group overview' })).toBeVisible();
  await expect(page.getByText('Mats Factory').first()).toBeVisible();
  await ctx.close();
  const c2 = await browser.newContext(); const p2 = await c2.newPage();
  await enter(p2, 'smmats', '/g/cockpit');
  await p2.waitForTimeout(2500);
  await shot(p2, 'cockpit-site-manager');
  await expect(p2.getByText("You don't have access to this page")).toBeVisible();
  await c2.close();
});
