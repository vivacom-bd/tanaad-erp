import { expect, type APIRequestContext, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { API_URL, PIN } from './env';

export const SHOTS = resolve(import.meta.dirname, '..', 'screens', 'cash-group');
/** The app's business day: Kampala (UTC+3) date, still yesterday until 07:00 (end of the night shift). Mirrors web/src/data/emptyData.ts realToday. */
export const businessDay = (now = Date.now()) => { const local = new Date(now + 3 * 3600_000); const minute = local.getUTCHours() * 60 + local.getUTCMinutes(); return new Date(local.getTime() - (minute < 420 ? 86_400_000 : 0)).toISOString().slice(0, 10); };
export const TODAY = businessDay();
const WD = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']; const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
/** "Mon 9 Nov", as the cash book prints a day. */
export const dayLabel = (d: string) => { const x = new Date(d + 'T00:00:00Z'); return `${WD[x.getUTCDay()]} ${x.getUTCDate()} ${MON[x.getUTCMonth()]}`; };
export const shortDay = (d: string) => dayLabel(d).slice(4);

/** Screenshots at 390px and 1280px with a programmatic horizontal-overflow check; restores the desktop viewport. */
export async function shot(page: Page, name: string, opts: { settle?: () => Promise<void> } = {}) {
  mkdirSync(SHOTS, { recursive: true });
  const overflow: string[] = [];
  for (const [w, h] of [[390, 844], [1280, 900]] as const) {
    await page.setViewportSize({ width: w, height: h });
    await page.waitForTimeout(350);
    if (opts.settle) await opts.settle();
    const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth, bw: document.body.scrollWidth }));
    if (Math.max(o.sw, o.bw) > o.cw + 1) overflow.push(`${w}px: scrollWidth ${Math.max(o.sw, o.bw)} > ${o.cw}`);
    await page.screenshot({ path: resolve(SHOTS, `${name}-${w}.png`), fullPage: false });
  }
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.waitForTimeout(250);
  expect.soft(overflow, `horizontal overflow on ${name}`).toEqual([]);
}

const tokens = new Map<string, string>();
const pinLogins: number[] = [];

/** Real-API helper with a per-user cached token (pin-login is limited to 5 a minute, shared by every test of a run). */
export async function apiAs(request: APIRequestContext, user: string) {
  let token = tokens.get(user);
  if (!token) {
    const now = Date.now(); while (pinLogins.length && now - pinLogins[0] > 60_000) pinLogins.shift();
    if (pinLogins.length >= 4) await new Promise((r) => setTimeout(r, 61_000 - (now - pinLogins[0])));
    pinLogins.push(Date.now());
    for (let i = 0; i < 4 && !token; i++) {
      const r = await request.post(`${API_URL}/auth/pin-login`, { data: { username: user, pin: PIN }, headers: { Accept: 'application/json' } });
      if (r.status() === 429) { await new Promise((x) => setTimeout(x, 20_000)); continue; }
      expect(r.ok(), `pin-login ${user}: ${r.status()}`).toBeTruthy();
      token = (await r.json()).token as string;
    }
    expect(token, `no token for ${user}`).toBeTruthy(); tokens.set(user, token!);
  }
  const headers = { Authorization: `Bearer ${tokens.get(user)}`, Accept: 'application/json' };
  return {
    get: (path: string) => request.get(`${API_URL}${path}`, { headers }),
    post: (path: string, data?: unknown) => request.post(`${API_URL}${path}`, { headers, data }),
    json: async (path: string) => { const x = await request.get(`${API_URL}${path}`, { headers }); expect(x.ok(), `GET ${path}: ${x.status()}`).toBeTruthy(); return x.json(); },
  };
}

/** Server-side view of a site's cash day (summary query). */
export async function cashDay(request: APIRequestContext, user: string, site: string, day = TODAY) {
  return (await apiAs(request, user)).json(`/sites/${site}/cash/days/${day}`);
}

/** Type an amount into the desktop NumberPad (the labelled input). */
export const amountBox = (scope: Page | import('@playwright/test').Locator) => scope.getByPlaceholder('0').first();

export const num = (n: number) => n.toLocaleString('en-UG');

/** Choose a day in the cash book's day combobox by its label fragment, e.g. "4 Nov". */
export async function pickDay(page: Page, label: string) {
  const box = page.getByRole('combobox').first();
  await box.click(); await box.fill(label);
  await page.getByRole('option', { name: new RegExp(label) }).first().click();
}

const SITE_USERS: Record<string, { cashier: string; manager: string }> = { mats: { cashier: 'cashmats', manager: 'smmats' }, bags: { cashier: 'cashbags', manager: 'smbags' }, recycling: { cashier: 'cashrec', manager: 'smrec' } };
export const siteUsers = (site: string) => SITE_USERS[site];

/** Make today's cash page of a site writable: reopen it (as the site manager) when a previous spec or the seed closed it. Returns the day as the server sees it. */
export async function ensureOpen(request: APIRequestContext, site: string, day = TODAY) {
  const u = SITE_USERS[site];
  let d = await cashDay(request, u.manager, site, day);
  if (d.day?.status === 'closed') {
    const r = await (await apiAs(request, u.manager)).post(`/sites/${site}/cash/days/${day}/reopen`, { reason: 'Test setup: reopen' });
    expect(r.ok(), await r.text()).toBeTruthy();
    d = await cashDay(request, u.manager, site, day);
  }
  return d;
}

/** Amount for a receipt that leaves the drawer total at a whole multiple of 500 (the smallest note), so it can be counted exactly. */
export const roundingReceipt = (expected: number, atLeast = 30_000) => atLeast + ((500 - ((expected + atLeast) % 500)) % 500);

/** Type the counted drawer into the close-day sheet using the configured notes, largest first. Returns what is left uncounted. */
export async function countNotes(sheet: import('@playwright/test').Locator, amount: number) {
  let rest = amount;
  for (const n of [50000, 20000, 10000, 5000, 2000, 1000, 500]) { const q = Math.floor(rest / n); if (q) await sheet.getByLabel(`How many ${num(n)}`).fill(String(q)); rest -= q * n; }
  return rest;
}

/** Close today's page (as the site manager, counting the drawer in 500s) when it is open, so a spec can start from a closed day. */
export async function ensureClosed(request: APIRequestContext, site: string, day = TODAY) {
  const u = SITE_USERS[site]; const d = await cashDay(request, u.manager, site, day);
  if (d.day?.status === 'closed') return d;
  const exp = d.totals.expectedClosing as number; const q = Math.floor(exp / 500);
  const r = await (await apiAs(request, u.manager)).post(`/sites/${site}/cash/days/${day}/close`, { counted: q * 500, denominations: { '500': q } });
  expect(r.ok(), await r.text()).toBeTruthy();
  return cashDay(request, u.manager, site, day);
}
