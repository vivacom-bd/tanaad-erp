import { expect, test } from '../../support/helpers';
import { enter } from '../../support/nav';
import { TODAY, apiAs, shot } from '../../support/cashgroup';

test.use({ actionTimeout: 20_000 });
test.describe.configure({ mode: 'serial', timeout: 180_000 });
const compact = (n: number) => (Math.abs(n) >= 1_000_000 ? `${(n / 1_000_000).toFixed(2)}M` : Math.abs(n) >= 1_000 ? `${Math.round(n / 1_000)}k` : `${Math.round(n)}`);

test('morning brief: preview carries the server figures; copy and "mark sent" are logged once each', async ({ browser, request, w }) => {
  const ctx = await browser.newContext({ permissions: ['clipboard-read', 'clipboard-write'] });
  const page = await ctx.newPage();
  const admin = await apiAs(request, 'admin');
  const cockpit = (await admin.json('/group/cockpit')).data as { day: string; sites: { name: string; cash: { inSafe: number } }[] };
  const before = (await admin.json('/briefs')).data.length;
  await enter(page, 'ceo1', '/g/brief');
  await expect(page.getByRole('heading', { name: 'Morning brief' })).toBeVisible();
  await expect(page.getByText('Hassan Warsame').first()).toBeVisible();
  for (const s of cockpit.sites) {
    await expect(page.getByText(`*${s.name}*`).first()).toBeVisible();
    // Before 07:00 Kampala the app's business day is still yesterday while the server's overview is already on the new calendar day: compare figures only when they agree.
    if (cockpit.day === TODAY) await expect(page.getByText(`• Cash UGX ${compact(s.cash.inSafe)}`).first()).toBeVisible();
    else await expect(page.getByText(/• Cash UGX /).first()).toBeVisible();
  }
  await expect(page.getByText('Where the numbers come from')).toBeVisible();
  await expect(page.getByText('Cash variance over threshold')).toBeVisible();
  await shot(page, 'brief');

  await page.getByRole('button', { name: 'Copy message' }).click();
  await expect(page.getByText('Brief copied')).toBeVisible();
  const clip = await page.evaluate(() => navigator.clipboard.readText());
  expect(clip).toContain('*Mats Factory*');
  await page.getByRole('button', { name: 'Mark sent on WhatsApp' }).click();
  await expect(page.getByText('Logged as sent on WhatsApp')).toBeVisible();
  await expect.poll(async () => (await admin.json('/briefs')).data.length, { timeout: 20_000 }).toBe(before + 2);
  const rows = (await admin.json('/briefs')).data as { channel: string; lines: string[] }[];
  expect(rows.map((r) => r.channel).sort().slice(-2)).toEqual(['copy', 'whatsapp'].sort());
  expect(rows[0].lines.join('\n')).toContain('Cash UGX');
  w.assertClean();
  await ctx.close();
});

test('brief: a site manager is shown the no-access screen', async ({ browser, w }) => {
  w.allow(/403/);
  const ctx = await browser.newContext(); const page = await ctx.newPage();
  await enter(page, 'smmats', '/g/brief');
  await expect(page.getByText("You don't have access to this page")).toBeVisible();
  await expect(page.getByRole('button', { name: 'Mark sent on WhatsApp' })).toHaveCount(0);
  await shot(page, 'brief-site-manager-no-access');
  await ctx.close();
});
