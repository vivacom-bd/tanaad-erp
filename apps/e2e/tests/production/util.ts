import { expect, type APIRequestContext, type Locator, type Page } from '@playwright/test';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { API_URL } from '../../support/env';
import { api as rawApi } from '../../support/helpers';

export const SCREENS = resolve(fileURLToPath(new URL('../../screens/production', import.meta.url)));
export type SiteKey = 'mats' | 'bags' | 'recycling';
export const SITE_UUID: Record<SiteKey, string> = { mats: '', bags: '', recycling: '' };

/** Sign in through the form, open the site workspace and go to a production route (e.g. "production/wall"). */
export async function signIn(page: Page, username: string) {
  // The dev server reloads the page when someone edits the app mid-run; sign in again if that swallowed the form.
  for (let attempt = 1; ; attempt++) {
    try {
      await page.goto('/');
      await page.getByPlaceholder(/username/i).fill(username);
      await page.getByPlaceholder('Password').fill('password');
      await page.getByRole('button', { name: /^sign in/i }).click();
      await page.waitForURL(/\/portals/, { timeout: 15_000 });
      return;
    } catch (e) { if (attempt >= 3) throw e; }
  }
}
const CARD: Record<SiteKey, string> = { mats: 'Mats Factory', bags: 'Bags Factory', recycling: 'Recycling Plant' };
/** From /portals open one workspace card, optionally "Open as" a given role chip (users holding two roles at a site). */
export async function openPortal(page: Page, site: SiteKey, role?: string) {
  if (!/\/portals/.test(page.url())) await page.goto('/portals');
  const card = page.locator('article').filter({ hasText: CARD[site] }).first();
  await expect(card).toBeVisible({ timeout: 20_000 });
  if (role) await card.getByRole('button', { name: role, exact: true }).click();
  await card.getByRole('button', { name: /open workspace/i }).click();
  await expect(page.locator('nav[aria-label="Main"], nav[aria-label="Primary"]').first()).toBeVisible({ timeout: 20_000 });
}
export async function openAs(page: Page, username: string, site: SiteKey, route: string, role?: string) {
  await signIn(page, username);
  // A reload while the dev server is busy can drop the session back to /portals or the sign-in form; start over then.
  for (let attempt = 1; ; attempt++) {
    try {
      await openPortal(page, site, role);
      await page.goto(`/s/${site}/${route}`);
      await expect(page.locator('main').first()).toBeVisible({ timeout: 15_000 });
      break;
    } catch (e) { if (attempt >= 3) throw e; if (page.url().endsWith('/') || /sign|login/.test(page.url())) await signIn(page, username); }
  }
  await settle(page);
}
export async function go(page: Page, site: SiteKey, route: string) {
  await page.goto(`/s/${site}/${route}`);
  await expect(page.locator('main').first()).toBeVisible({ timeout: 20_000 });
  await settle(page);
}
export async function settle(page: Page) { await page.waitForLoadState('networkidle').catch(() => undefined); await page.waitForTimeout(1500); }

/** Screenshot at 390 and 1280 px; fails softly when the page scrolls sideways. */
export async function shoot(page: Page, name: string) {
  const prev = page.viewportSize();
  for (const [w, h] of [[390, 844], [1280, 900]] as const) {
    await page.setViewportSize({ width: w, height: h });
    await page.waitForTimeout(350);
    const over = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth, main: (document.querySelector('main') as HTMLElement | null)?.scrollWidth ?? 0 }));
    expect.soft(over.sw, `${name} @${w}px scrolls sideways (scrollWidth ${over.sw} > ${over.cw})`).toBeLessThanOrEqual(over.cw + 1);
    await page.screenshot({ path: `${SCREENS}/${name}-${w}.png`, fullPage: true });
  }
  if (prev) await page.setViewportSize(prev); else await page.setViewportSize({ width: 1440, height: 900 });
}

export const main = (page: Page) => page.locator('main').first();
export const text = async (page: Page) => (await main(page).innerText()).replace(/\s+/g, ' ');

/** Pick an option in a Combobox by its placeholder or current value, searching by text. */
export async function pick(scope: Page | Locator, combo: Locator, query: string) {
  await combo.click();
  await combo.fill(query);
  const opt = (scope as Page).getByRole('option').filter({ hasText: new RegExp(query, 'i') }).first();
  await opt.click();
}

export async function chip(scope: Page | Locator, group: string, name: string | RegExp) {
  await scope.getByRole('group', { name: group }).or(scope.locator(`[aria-label="${group}"]`)).getByRole('radio', { name }).first().click();
}

export type Api = Awaited<ReturnType<typeof rawApi>>;
const tokens = new Map<string, string>();
let lastLogin = 0;
/** pin-login is throttled (5/min): one login per user for the whole run, spaced out, retried on 429. */
async function pinToken(request: APIRequestContext, username: string): Promise<string> {
  for (let i = 0; i < 8; i++) {
    const wait = lastLogin + 13_000 - Date.now(); if (wait > 0) await new Promise((r) => setTimeout(r, wait));
    lastLogin = Date.now();
    const r = await request.post(`${API_URL}/auth/pin-login`, { data: { username, pin: '1234' }, headers: { Accept: 'application/json' } });
    if (r.status() === 429) { await new Promise((x) => setTimeout(x, 15_000)); continue; }
    expect(r.ok(), `pin-login ${username}: ${r.status()}`).toBeTruthy();
    const b = await r.json(); return b.token ?? b.data?.token;
  }
  throw new Error(`pin-login ${username} stayed rate limited`);
}
export async function api(request: APIRequestContext, username: string): Promise<Api> {
  if (!tokens.has(username)) tokens.set(username, await pinToken(request, username));
  const token = tokens.get(username)!;
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };
  return {
    token, login: undefined as never,
    get: (path: string) => request.get(`${API_URL}${path}`, { headers }),
    post: (path: string, data?: unknown) => request.post(`${API_URL}${path}`, { headers, data }),
    put: (path: string, data?: unknown) => request.put(`${API_URL}${path}`, { headers, data }),
    json: async (path: string) => { const x = await request.get(`${API_URL}${path}`, { headers }); expect(x.ok(), `GET ${path}: ${x.status()} ${(await x.text()).slice(0, 200)}`).toBeTruthy(); return x.json(); },
  };
}
export async function siteId(a: Api, site: SiteKey): Promise<string> {
  if (SITE_UUID[site]) return SITE_UUID[site];
  const me = await a.json('/auth/me');
  const sites: { id: string; code: string }[] = me.sites ?? me.data?.sites ?? [];
  const f = sites.find((s) => s.code === site);
  if (f) { SITE_UUID[site] = f.id; return f.id; }
  throw new Error(`site ${site} not found in /auth/me: ${JSON.stringify(me).slice(0, 300)}`);
}
export { API_URL };
export type { APIRequestContext };

export const STATE_LABEL: Record<string, string> = { ok: 'On target', logged: 'Logged', below: 'Below target', not_logged: 'Not logged', down: 'Down', arriving: 'Arriving' };
export interface WallMachine { machine: { code: string; status: string }; entry?: { goodUnits: number; rejectUnits: number; inputKg: number; outputKg: number; downtimeMin: number } | null; state: string; pct?: number | null }
export async function serverWall(a: Api, site: SiteKey, shift: 'day' | 'night', day?: string): Promise<{ day: string; machines: WallMachine[] }> {
  return a.json(`/sites/${site}/machine-wall?shift=${shift}${day ? `&day=${day}` : ''}`);
}
/** Every running machine on the page shows the state the server computed. */
export async function expectTilesMatchServer(page: Page, wall: { machines: WallMachine[] }) {
  for (const m of wall.machines.filter((x) => x.state !== 'arriving')) {
    await expect(page.locator(`[data-tile][aria-label^="${m.machine.code}, ${STATE_LABEL[m.state]}"]`), `${m.machine.code} should read ${STATE_LABEL[m.state]}`).toHaveCount(1);
  }
}
export const fmt = (n: number) => Math.round(n).toLocaleString('en-US');

/** Open a PersonPicker (placeholder "Search worker by name") inside `scope` and take the first/matching person. */
export async function chooseWorker(page: Page, scope: Page | Locator, name?: string) {
  const box = scope.getByPlaceholder(/^Search .*(worker|operator)/i).first();
  if ((await box.count()) === 0) return; // someone is already chosen (an existing entry)
  await box.click();
  if (name) await box.fill(name);
  await page.getByRole('option').filter(name ? { hasText: name } : undefined).first().click();
}
export const toast = (page: Page, re: RegExp | string) => page.getByText(re).first();
type LabelRow = { code: string; kind: string; qtyKg: number; status: string; day: string; colour?: string | null; productId?: string | null; qtyUnits?: number };
/** All labels of a site (the API pages at 50), optionally filtered: `labelsOf(a, 'mats', 'kind=bundle')`. */
export async function labelsOf(a: Api, site: SiteKey, query = ''): Promise<LabelRow[]> {
  const out: LabelRow[] = [];
  for (let page = 1; page < 60; page++) {
    const r = await a.json(`/sites/${site}/labels?page=${page}&perPage=50${query ? `&${query}` : ''}`);
    out.push(...(r.data ?? []));
    if (out.length >= (r.total ?? 0) || (r.data ?? []).length === 0) break;
  }
  return out;
}
export async function eventually<T>(fn: () => Promise<T>, ok: (v: T) => boolean, ms = 30_000): Promise<T> {
  const end = Date.now() + ms; let last!: T;
  while (Date.now() < end) { last = await fn(); if (ok(last)) return last; await new Promise((r) => setTimeout(r, 1000)); }
  return last;
}

/** Enter a weight through a ScaleWidget's manual path (type the display, take the mandatory photo, use it). */
export async function weighManually(page: Page, kg: string | number, scale?: string | RegExp) {
  const widget = page.locator('.surface').filter({ hasText: scale ?? 'Scale display' }).filter({ has: page.getByRole('tab', { name: /Manual/ }) }).last();
  await widget.getByRole('tab', { name: /Manual/ }).click();
  const display = widget.locator('input[inputmode="decimal"]');
  await display.fill(String(kg));
  await widget.getByRole('button', { name: /Photo of the scale display/ }).click();
  const use = widget.getByRole('button', { name: /^Use / });
  await expect(use).toBeEnabled();
  await use.click();
}
export async function chipIn(scope: Page | Locator, group: string, name: string | RegExp) {
  await scope.locator(`[aria-label="${group}"]`).getByRole('radio', { name }).first().click();
}

/** Save a shift-entry sheet; picks a downtime reason first when the numbers need one. */
export async function saveEntry(dlg: Locator) {
  if (await dlg.getByText('The entry cannot be saved without a reason').count()) await dlg.locator('[aria-label="Downtime reason"]').getByRole('radio').first().click();
  await expect(dlg).toContainText('Ready to save');
  await dlg.getByRole('button', { name: /(save|update) entry/i }).click();
  await expect(dlg).toBeHidden();
}
