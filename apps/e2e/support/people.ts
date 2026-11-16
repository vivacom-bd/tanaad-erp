import { expect, test, type APIRequestContext, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { api, loginAs, openFirstPortal } from './helpers';
import { API_URL } from './env';

export const SCREENS = resolve(fileURLToPath(new URL('..', import.meta.url)), 'screens/people');
export type Api = Awaited<ReturnType<typeof api>>;

const tokens = new Map<string, string>();
/** Like helpers.api, but logs in once per user for the whole run (pin-login is limited to 5 a minute per user). */
export async function apiAs(request: APIRequestContext, username: string): Promise<Api> {
  const known = tokens.get(username);
  if (!known) { const a = await api(request, username); tokens.set(username, a.token); return a; }
  const headers = { Authorization: `Bearer ${known}`, Accept: 'application/json' }; const base = API_URL;
  return {
    token: known, login: undefined,
    get: (path: string) => request.get(`${base}${path}`, { headers }),
    post: (path: string, data?: unknown) => request.post(`${base}${path}`, { headers, data }),
    put: (path: string, data?: unknown) => request.put(`${base}${path}`, { headers, data }),
    json: async (path: string) => { const x = await request.get(`${base}${path}`, { headers }); expect(x.ok(), `GET ${path}: ${x.status()}`).toBeTruthy(); return x.json(); },
  } as Api;
}

/** Sign in through the UI, land on the role's first portal, then jump straight to a site route. */
export async function signIn(page: Page, username: string) {
  try { await loginAs(page, username); }
  catch { test.info().setTimeout(test.info().timeout + 40_000); await page.waitForTimeout(31_000); await loginAs(page, username); } // sign-in is limited to 10 a minute per user; a burst of specs can hit it
  await openFirstPortal(page);
}
export async function enterSite(page: Page, username: string, site: 'mats' | 'bags' | 'recycling', path: string) {
  await signIn(page, username);
  await gotoSite(page, site, path);
}
export async function gotoSite(page: Page, site: string, path: string) {
  await page.goto(`/s/${site}/${path}`);
  await page.waitForLoadState('networkidle');
  await expect(page.locator('main, [role="main"]').first()).toBeVisible();
}
export async function gotoGroup(page: Page, path: string) {
  await page.goto(`/g/${path}`);
  await page.waitForLoadState('networkidle');
  await expect(page.locator('main, [role="main"]').first()).toBeVisible();
}

/** Rows of a server snapshot (people, engagements, attendance, exceptions ...). */
export async function snap<T = Record<string, any>>(a: Api, site: string, projection: string): Promise<T[]> {
  const out: T[] = []; let since = 0;
  for (let i = 0; i < 50; i++) { // a snapshot answers in pages; follow them by sequence number
    const r = await a.json(`/sites/${site}/snapshots/${projection}?since=${since}&limit=1000`);
    const rows = r.data as (T & { seq?: number })[]; out.push(...rows);
    if (!r.has_more || !rows.length) break;
    since = Math.max(...rows.map((x) => Number(x.seq ?? 0)));
  }
  return out;
}
export const runs = async (a: Api, site: string) => (await a.json(`/sites/${site}/settlement-runs`)).data as { run: Record<string, any>; lines: Record<string, any>[]; payouts: Record<string, any>[] }[];
export const advances = async (a: Api, site: string) => (await a.json(`/sites/${site}/advances`)).data as Record<string, any>[];

/** Poll the API until the predicate holds (the outbox flushes asynchronously). */
export async function eventually<T>(fn: () => Promise<T>, ok: (v: T) => boolean, what: string, ms = 20_000): Promise<T> {
  const end = Date.now() + ms; let last!: T;
  while (Date.now() < end) { last = await fn(); if (ok(last)) return last; await new Promise((r) => setTimeout(r, 400)); }
  throw new Error(`timed out waiting for ${what}; last: ${JSON.stringify(last).slice(0, 400)}`);
}

/** Screenshot at desktop and phone width into screens/people/ and report horizontal overflow of the page. */
export async function captureScreen(page: Page, name: string): Promise<string[]> {
  mkdirSync(SCREENS, { recursive: true });
  const overflow: string[] = [];
  const orig = page.viewportSize() ?? { width: 1440, height: 900 };
  for (const [w, h] of [[1280, 800], [390, 844]] as const) {
    await page.setViewportSize({ width: w, height: h });
    await page.waitForTimeout(500);
    const o = await page.evaluate(() => {
      const de = document.documentElement; const wide: string[] = [];
      document.querySelectorAll('body *').forEach((el) => { const r = el.getBoundingClientRect(); if (r.width > 0 && r.right > innerWidth + 1 && !el.closest('[class*="overflow-"],table,[role="grid"]') && getComputedStyle(el).position !== 'fixed') wide.push(`${el.tagName.toLowerCase()}.${String(el.className).slice(0, 40)}`); });
      return { sw: de.scrollWidth, iw: innerWidth, wide: wide.slice(0, 3) };
    });
    if (o.sw > o.iw + 1) overflow.push(`${name} @${w}: page scrollWidth ${o.sw} > ${o.iw}; e.g. ${o.wide.join(', ')}`);
    await page.screenshot({ path: resolve(SCREENS, `${name}-${w}.png`), fullPage: false });
  }
  await page.setViewportSize(orig);
  return overflow;
}

export async function toastText(page: Page) {
  return (await page.locator('[role="status"], [role="alert"], [aria-live]').allInnerTexts()).join(' | ');
}

/** The business day the web app and the server agree on: Kampala (UTC+3), and before 07:00 (end of the night shift) it is still yesterday. */
export function businessDay(now = Date.now()): string {
  const local = new Date(now + 3 * 3600_000);
  const minute = local.getUTCHours() * 60 + local.getUTCMinutes();
  return new Date(local.getTime() - (minute < 7 * 60 ? 86_400_000 : 0)).toISOString().slice(0, 10);
}

/** The app pulls ~47 snapshot/summary endpoints after every write and the server allows 600 calls a minute per user, so a burst of writes can
 *  draw a 429 on a *read*. Known finding (see report); the specs tolerate that single failure, never a 4xx/5xx on a write or any other read. */
export function tolerateThrottledPulls(w: { allow: (re: RegExp) => void }) {
  w.allow(/^429 POST \/sites\/[a-z]+\/events/); // a throttled write stays in the outbox and is retried: the specs assert it still arrives exactly once
  w.allow(/^429 GET \/sites\/[a-z]+\/(snapshots|settlement-runs|advances|machine-wall|dispatches|stock|purchases|spares|exceptions)/);
}

/** Header counts of the attendance sheet: "N of M marked · P present · H half day · A absent". */
export async function sheetCounts(page: Page) {
  const t = await page.getByText(/\d+ of \d+ marked/).first().innerText();
  const n = (re: RegExp) => Number(re.exec(t)?.[1] ?? NaN);
  return { marked: n(/(\d+) of \d+ marked/), roster: n(/\d+ of (\d+) marked/), present: n(/(\d+) present/), half: n(/(\d+) half day/), absent: n(/(\d+) absent/) };
}
