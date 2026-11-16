import { expect, test } from '../../support/helpers';
import { api, go, openAs, openPortal, serverWall, shoot, signIn, text, type SiteKey } from './util';

const P = (...r: string[]) => r.map((x) => `production/${x}`);
const MATS_ALL = P('wall', 'mixer', 'extruders', 'finishing', 'waste', 'sheet', 'labels', 'scan');
const BAGS_ALL = P('wall', 'raw', 'rolls', 'convert', 'scrap', 'sheet', 'labels', 'scan');
const REC_ALL = P('wall', 'intake', 'collectors', 'sacks', 'sheet', 'labels', 'scan');

// What each role is offered in the navigation (apps/web/src/app/nav.ts is the source of truth).
const NAV: { user: string; site: SiteKey; label: string; routes: string[]; role?: string }[] = [
  { user: 'supmats', site: 'mats', label: 'supervisor', routes: MATS_ALL },
  { user: 'smmats', site: 'mats', label: 'site manager', routes: MATS_ALL },
  { user: 'cashmats', site: 'mats', label: 'cashier', routes: P('scan') },
  { user: 'storemats', site: 'mats', label: 'storekeeper', role: 'Storekeeper', routes: P('labels', 'scan') },
  { user: 'storemats', site: 'mats', label: 'gate ', role: 'Gate', routes: P('scan') },
  { user: 'mech', site: 'mats', label: 'mechanic', routes: P('wall', 'scan') },
  { user: 'supbags', site: 'bags', label: 'supervisor', routes: BAGS_ALL },
  { user: 'smbags', site: 'bags', label: 'site manager', routes: BAGS_ALL },
  { user: 'cashbags', site: 'bags', label: 'cashier', routes: P('scan') },
  { user: 'storebags', site: 'bags', label: 'storekeeper', role: 'Storekeeper', routes: P('labels', 'scan') },
  { user: 'storebags', site: 'bags', label: 'gate ', role: 'Gate', routes: P('scan') },
  { user: 'suprec', site: 'recycling', label: 'supervisor', routes: REC_ALL },
  { user: 'smrec', site: 'recycling', label: 'site manager', routes: REC_ALL },
  { user: 'cashrec', site: 'recycling', label: 'cashier', routes: P('intake', 'collectors', 'scan') },
  { user: 'storerec', site: 'recycling', label: 'storekeeper', role: 'Storekeeper', routes: P('intake', 'labels', 'scan') },
  { user: 'storerec', site: 'recycling', label: 'gate ', role: 'Gate', routes: P('intake', 'scan') },
];

test.describe('roles: what each role sees of production, labels and recycling', () => {
  for (const r of NAV) {
    test(`navigation: ${r.label} at ${r.site} (${r.user}) is offered exactly ${r.routes.length} production screens`, async ({ page, w }) => {
      await openAs(page, r.user, r.site, 'today', r.role);
      const nav = page.locator('nav[aria-label="Main"]').first();
      const hub = nav.getByRole('button', { name: /^Production/ });
      if (await hub.count()) { await hub.first().click(); await page.waitForTimeout(600); }
      const hrefs = await nav.locator('a[href*="/production/"]').evaluateAll((as) => as.map((a) => (a as HTMLAnchorElement).getAttribute('href')!.split('/s/')[1].split('/').slice(1).join('/')));
      expect([...new Set(hrefs)].sort()).toEqual([...r.routes].sort());
      // every offered screen opens without a failed call or console error
      for (const route of r.routes) {
        await go(page, r.site, route);
        await expect(page.locator('main')).not.toContainText(/something went wrong|cannot|error/i);
      }
      w.assertClean();
    });
  }

  test('CEO sees every site\'s wall, sheet and collector ledger read-only: no entry sheet, no Close shift', async ({ page, request, w }) => {
    await signIn(page, 'ceo1');
    const nav = page.locator('nav[aria-label="Main"]').first();
    const via = async (label: RegExp) => {
      if (!(await nav.getByRole('link', { name: label }).count())) await nav.getByRole('button', { name: /^Production/ }).click();
      await nav.getByRole('link', { name: label }).first().click();
      await page.waitForLoadState('networkidle').catch(() => undefined);
      await page.waitForTimeout(1200);
    };
    for (const [site, wallTitle] of [['mats', 'Loom Wall'], ['bags', 'Extruder Wall'], ['recycling', 'Line Wall']] as const) {
      await openPortal(page, site);
      await via(/Machine wall/);
      await expect(page.getByText(wallTitle).first()).toBeVisible();
      const t = await text(page);
      expect(t).not.toContain('Log next unlogged');
      await expect(page.locator('button[data-tile]')).toHaveCount(0);
      const a = await api(request, 'ceo1');
      const wall = await serverWall(a, site, 'day');
      expect(t).toContain(`${wall.machines.filter((m) => m.state !== 'arriving' && m.entry).length} logged`);
      await shoot(page, `ceo-${site}-wall`);
      await via(/Shift handover/);
      await expect(page.getByText('Shift sheet').first()).toBeVisible();
      await expect(page.getByRole('button', { name: /close shift/i })).toHaveCount(0);
      if (site === 'recycling') {
        await via(/Collector ledger/);
        await expect(page.getByText('Collector ledger').first()).toBeVisible();
        await expect(page.getByRole('button', { name: 'Pay', exact: true })).toHaveCount(0);
        await shoot(page, 'ceo-collectors');
      }
    }
    w.assertClean();
  });

  test('cross-site isolation: the server refuses another site\'s production, labels and cash', async ({ request }) => {
    const sup = await api(request, 'supmats');
    for (const path of ['/sites/bags/machine-wall', '/sites/bags/mass-balance', '/sites/recycling/collector-ledger', '/sites/recycling/label-stock', '/sites/bags/labels']) {
      const x = await sup.get(path);
      expect([403, 404], `${path} for a mats supervisor`).toContain(x.status());
    }
    const bad = await sup.post('/sites/bags/production/shifts/close', { day: '2026-11-09', kind: 'day' });
    expect([403, 404, 409]).toContain(bad.status());
    // a cashier may not record production at their own site either
    const cash = await api(request, 'cashmats');
    const w2 = await cash.get('/sites/mats/machine-wall');
    expect(w2.status()).toBe(403);
    const close = await cash.post('/sites/mats/production/shifts/close', { day: '2026-11-09', kind: 'day' });
    expect(close.status()).toBe(403);
    // the recycling ledger is not open to the storekeeper
    const store = await api(request, 'storerec');
    expect((await store.get('/sites/recycling/collector-ledger')).status()).toBe(403);
  });

  test('another site\'s screens are refused by the route guard (no leak, no failed calls)', async ({ page, w }) => {
    await openAs(page, 'supmats', 'mats', 'today');
    await go(page, 'bags', 'production/wall');
    const t = await text(page);
    expect(t).toMatch(/don't have access|not.*access/i);
    expect(t).not.toMatch(/Extruder A/);
    w.assertClean();
  });

  test('deep link to a screen the role is not offered: the route guard shows No access, no form, no write', async ({ page, request, w }) => {
    await openAs(page, 'cashmats', 'mats', 'production/mixer');
    const a = await api(request, 'supmats');
    const before = (await a.json('/sites/mats/labels?kind=batch&perPage=1')).total as number;
    const t = await text(page);
    expect(t).toMatch(/don't have access to this page/i);
    await expect(page.getByRole('button', { name: /weigh and label/i })).toHaveCount(0);
    await shoot(page, 'cashier-deeplink-mixer');
    expect((await a.json('/sites/mats/labels?kind=batch&perPage=1')).total as number).toBe(before);
    w.assertClean();
  });
});
