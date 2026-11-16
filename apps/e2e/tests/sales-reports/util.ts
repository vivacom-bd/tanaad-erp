import { appendFileSync, mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, type Page } from '@playwright/test';

export const SHOTS = resolve(import.meta.dirname, '../../screens/sales-reports');
mkdirSync(SHOTS, { recursive: true });
export const WORDING = resolve(SHOTS, 'wording-hits.txt');
export const SCREENS = resolve(SHOTS, 'screens-covered.txt');

/** Words the product should not use in real mode. */
const BAD = /\b(demo|sample|prototype|simulated|simulation|mock|fake|lorem|example|placeholder)\b/i;

/** Visible text of the whole page, record wording hits (appended to wording-hits.txt). */
export async function wording(page: Page, name: string) {
  const ph = await page.evaluate(() => [...document.querySelectorAll('[placeholder],[title],[aria-label]')].flatMap((e) => [e.getAttribute('placeholder'), e.getAttribute('title')]).filter(Boolean).join('\n'));
  const text = (await page.locator('body').innerText()) + '\n' + ph;
  const hits = text.split('\n').filter((l) => BAD.test(l)).map((l) => l.trim().slice(0, 160));
  if (hits.length) appendFileSync(WORDING, `${name}: ${[...new Set(hits)].join(' || ')}\n`);
  return hits;
}

/** Screenshot at 390px and 1280px, assert no horizontal overflow, record the screen and its wording hits. */
export async function shoot(page: Page, name: string) {
  const safe = name.replace(/[^a-z0-9]+/gi, '-').toLowerCase();
  appendFileSync(SCREENS, `${name}\n`);
  await page.waitForLoadState('networkidle').catch(() => undefined);
  await wording(page, name);
  const orig = page.viewportSize() ?? { width: 1440, height: 900 };
  for (const w of [1280, 390]) {
    await page.setViewportSize({ width: w, height: w === 390 ? 844 : 900 });
    await page.waitForTimeout(250);
    const over = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
    expect.soft(over.sw, `horizontal overflow on "${name}" at ${w}px (scrollWidth ${over.sw})`).toBeLessThanOrEqual(over.iw + 1);
    await page.screenshot({ path: resolve(SHOTS, `${safe}-${w}.png`), fullPage: false });
  }
  await page.setViewportSize(orig);
}

export const site = (s: string) => `/s/${s}`;

/** Log in and pick the portal whose card text matches (default first). */
export async function enter(page: Page, portal?: RegExp) {
  const card = portal ? page.locator('article').filter({ hasText: portal }).first() : page.locator('article').first();
  await card.getByRole('button', { name: /open|enter|continue|go/i }).first().click();
  await expect(page.locator('nav[aria-label="Main"], nav[aria-label="Primary"]').first()).toBeVisible({ timeout: 20_000 });
}

/** Client-side navigation without a reload (keeps the signed-in session and outbox). */
export async function goto(page: Page, path: string) {
  await page.evaluate((x) => { history.pushState({}, '', x); dispatchEvent(new PopStateEvent('popstate')); }, path);
  await page.waitForURL((u) => u.pathname === path.split('?')[0]);
  await page.waitForLoadState('networkidle').catch(() => undefined);
}

import { API_URL, PIN, WEB_URL } from '../../support/env';

/** Cached server-side API client (one PIN login per user per file; the login endpoint is rate limited). Uses fetch, not the test request fixture. */
const cache = new Map<string, { token: string }>();
export async function A(username: string) {
  let c = cache.get(username);
  const login = async () => {
    for (let i = 0; i < 8; i++) {
      const r = await fetch(`${API_URL}/auth/pin-login`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ username, pin: PIN }) });
      if (r.status === 429) { await new Promise((x) => setTimeout(x, 15_000)); continue; }
      const b: any = await r.json(); if (!r.ok) throw new Error(`pin-login ${username}: ${r.status}`);
      c = { token: b.token }; cache.set(username, c); return c;
    }
    throw new Error(`pin-login ${username}: rate limited`);
  };
  if (!c) await login();
  const call = async (method: string, path: string, data?: unknown) => {
    const go = () => fetch(`${API_URL}${path}`, { method, headers: { Authorization: `Bearer ${c!.token}`, Accept: 'application/json', ...(data ? { 'Content-Type': 'application/json' } : {}) }, body: data ? JSON.stringify(data) : undefined });
    let r = await go(); if (r.status === 401) { await login(); r = await go(); }
    for (let i = 0; i < 3 && r.status >= 500 && method === 'GET'; i++) { await new Promise((x) => setTimeout(x, 1000)); r = await go(); } // the DB cache throttle can deadlock (backend, reported)
    const text = await r.text(); let json: any = null; try { json = JSON.parse(text); } catch { /* not json */ }
    return { status: r.status, ok: r.ok, json, text };
  };
  return {
    get: (p: string) => call('GET', p), post: (p: string, d?: unknown) => call('POST', p, d ?? {}), put: (p: string, d?: unknown) => call('PUT', p, d ?? {}),
    json: async (p: string) => { const r = await call('GET', p); if (!r.ok) throw new Error(`GET ${p}: ${r.status} ${r.text.slice(0, 200)}`); return r.json; },
  };
}

import type { Browser } from '@playwright/test';
/** A brand-new browser context + page (own storage), for role loops. */
export async function fresh(browser: Browser) { const ctx = await browser.newContext({ baseURL: WEB_URL, serviceWorkers: 'block', viewport: { width: 1440, height: 900 } }); return { ctx, page: await ctx.newPage() }; }

/** Money as the screens print it. */
export const money = (n: number, cur: string) => (cur === 'USD' ? `$${n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` : `UGX ${n.toLocaleString('en-US')}`);
/** Current Mats data from the server, so specs derive expectations from the seed at run time. */
export async function matsData() {
  const a = await A('ceo2');
  const [o, c, i] = await Promise.all([a.json('/sites/mats/orders'), a.json('/sites/mats/clients'), a.json('/sites/mats/invoices')]);
  return { orders: o.orders as any[], clients: c.clients as any[], invoices: i.invoices as any[] };
}
