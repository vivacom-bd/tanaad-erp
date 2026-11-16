import { expect, test } from '../../support/helpers';
import { enter } from '../../support/nav';
import { apiAs, shot } from '../../support/cashgroup';
import { readFileSync } from 'node:fs';

test.use({ actionTimeout: 20_000 });
test.describe.configure({ mode: 'serial', timeout: 180_000 });
type Line = { id: string; type: string; siteCode: string | null; summary: string; reason: string | null; flag: string; actorName: string };

test('audit feed: totals, cash corrections and reopenings are on record with their reasons', async ({ page, request, w }) => {
  const a = await apiAs(request, 'admin');
  const first = await a.json('/group/audit?per_page=100');
  const total: number = first.meta.total;
  const lines: Line[] = first.data;
  await enter(page, 'ceo1', '/g/audit');
  await expect(page.getByRole('heading', { name: 'Audit feed' })).toBeVisible();
  await expect(page.getByText('Append-only').first()).toBeVisible();
  await expect(page.getByText(new RegExp(`of ${total.toLocaleString('en-UG')} on record`))).toBeVisible();
  await shot(page, 'audit-feed');

  // the Mats reopen from the cash specs: its reason is the line's reason
  await page.getByPlaceholder('Search the feed').fill('Fuel receipt found in the drawer');
  await expect(page.getByText('Fuel receipt found in the drawer').first()).toBeVisible();
  await expect(page.getByText(/Reopened the cash day/).first()).toBeVisible();
  await page.getByText(/Reopened the cash day/).first().click();
  const sheet = page.getByRole('dialog');
  await expect(sheet.getByText('This line cannot be edited or deleted')).toBeVisible();
  await shot(page, 'audit-line-sheet');
  await page.keyboard.press('Escape');

  // corrections filter: shows edit/reopen lines only, including the entry correction
  await page.getByPlaceholder('Search the feed').fill('');
  await page.getByRole('tab', { name: /^Edits and reopenings/ }).click();
  await expect(page.getByText('Typed the wrong amount').first()).toBeVisible();
  
  const edits = lines.filter((l) => l.flag === 'edit' || l.flag === 'reopen').length;
  expect(edits).toBeGreaterThan(1);
  await page.getByRole('tab', { name: /^All$/ }).click();

  // site tabs only show that site
  await page.getByRole('tab', { name: /Bags/ }).click();
  await page.waitForTimeout(1500);
  await expect(page.getByText(/cash day/i).first()).toBeVisible();
  await expect(page.getByPlaceholder('Search the feed')).toBeVisible();
  w.assertClean();
});

test('audit feed: nothing matches message, load older lines, CSV export', async ({ page, request, w }) => {
  await enter(page, 'ceo1', '/g/audit');
  await expect(page.getByRole('heading', { name: 'Audit feed' })).toBeVisible();
  await page.getByPlaceholder('Search the feed').fill('zzzz-nothing-like-this');
  await expect(page.getByText('Nothing matches')).toBeVisible();
  await page.getByPlaceholder('Search the feed').fill('');
  const loadMore = page.getByRole('button', { name: 'Load older lines' });
  await expect(loadMore).toBeVisible();
  const before = await page.getByText(/^Loaded [\d,]+ of/).innerText();
  await loadMore.click();
  await expect(page.getByText(/^Loaded [\d,]+ of/)).not.toHaveText(before);
  const [dl] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: /^Export/ }).click()]);
  const csv = readFileSync(await dl.path(), 'utf8');
  expect(csv.split('\n')[0]).toContain('When');
  expect(csv.split('\n').length).toBeGreaterThan(40);
  w.assertClean();
});

test('audit feed is not shown to a cashier or a supervisor', async ({ browser, w }) => {
  w.allow(/403/);
  for (const u of ['cashmats', 'supmats']) {
    const ctx = await browser.newContext(); const page = await ctx.newPage();
    await enter(page, u, '/g/audit');
    await page.waitForTimeout(2000);
    await expect(page.getByText("You don't have access to this page")).toBeVisible();
    await ctx.close();
  }
});
