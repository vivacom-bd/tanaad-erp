import { expect, type Page } from '@playwright/test';
import { loginAs } from './helpers';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const SHOTS = resolve(fileURLToPath(new URL('..', import.meta.url)), 'screens', 'stock-dispatch');

/** Sign in and open a workspace. `site` picks the card by name ('mats' | 'bags' | 'recycling'), `role` the "Open as" button. */
export async function openAs(page: Page, username: string, opts: { site?: 'mats' | 'bags' | 'recycling'; role?: RegExp | string } = {}) {
  await loginAs(page, username);
  const cards = page.locator('article');
  const card = opts.site ? cards.filter({ hasText: new RegExp(opts.site === 'bags' ? 'package|bags' : opts.site === 'recycling' ? 'recycl' : 'mats', 'i') }).first() : cards.first();
  if (opts.role) await card.getByRole('button', { name: opts.role, exact: typeof opts.role === 'string' }).click();
  await card.getByRole('button', { name: /open workspace/i }).click();
  await expect(page.locator('nav[aria-label="Main"]').first()).toBeVisible({ timeout: 20_000 });
}

/** Client-side jump to a site route via the app router (keeps the in-memory store). */
export async function go(page: Page, path: string) {
  const site = /\/s\/([a-z]+)\//.exec(page.url())?.[1] ?? 'mats';
  await page.evaluate((p) => { window.history.pushState({}, '', p); window.dispatchEvent(new PopStateEvent('popstate')); }, `/s/${site}/${path}`);
  await page.waitForURL((u) => u.pathname === `/s/${site}/${path}`);
  await page.waitForLoadState('networkidle').catch(() => undefined);
  await expect(page.locator('main h1, main [role="heading"]').first()).toBeVisible();
}

/** Screenshot at 390px and 1280px and fail on horizontal overflow of the page. */
export async function shoot(page: Page, name: string) {
  const prior = page.viewportSize();
  const overflow: string[] = [];
  for (const [w, h] of [[390, 844], [1280, 900]] as const) {
    await page.setViewportSize({ width: w, height: h });
    await page.waitForTimeout(350);
    const o = await page.evaluate(() => {
      const de = document.documentElement; const bad = de.scrollWidth - de.clientWidth;
      const main = document.querySelector('main'); const mbad = main ? main.scrollWidth - main.clientWidth : 0;
      return Math.max(bad, mbad);
    });
    if (o > 1) overflow.push(`${name} @${w}px overflows by ${o}px`);
    await page.screenshot({ path: resolve(SHOTS, `${name}-${w}.png`), fullPage: false });
  }
  if (prior) await page.setViewportSize(prior);
  expect(overflow, 'horizontal overflow').toEqual([]);
}

/* ---------- API helpers for arranging data (the screens are what is under test) ---------- */
import type { api as ApiFn } from './helpers';
export type A = Awaited<ReturnType<typeof ApiFn>>;

export function uuid7(): string {
  const ms = BigInt(Date.now()); const r = (n: number) => Array.from({ length: n }, () => Math.floor(Math.random() * 16).toString(16)).join('');
  const t = ms.toString(16).padStart(12, '0');
  return `${t.slice(0, 8)}-${t.slice(8, 12)}-7${r(3)}-${(8 + Math.floor(Math.random() * 4)).toString(16)}${r(3)}-${r(12)}`;
}
export const today = () => new Date().toISOString().slice(0, 10);

export async function siteConfig(a: A, site: string) {
  const c = await a.json(`/sites/${site}/config`);
  return { zones: c.zones as { id: string; name: string }[], products: c.products as { id: string; code: string; internalName: string; bundlePieces: number; stdBundleWeightKg: number }[] };
}
/** Create `n` in-stock bundle labels of a product in a zone straight through the API; returns their codes. */
export async function tagViaApi(a: A, site: string, productName: string, zoneName: string, n: number): Promise<string[]> {
  const c = await siteConfig(a, site);
  const p = c.products.find((x) => x.internalName === productName)!; const z = c.zones.find((x) => x.name === zoneName)!;
  const codes: string[] = [];
  for (let i = 0; i < n; i++) {
    const r = await a.post(`/sites/${site}/labels`, { label_id: uuid7(), kind: 'bundle', product_id: p.id, stage_code: 'store', day: today(), qty_units: p.bundlePieces, qty_kg: p.stdBundleWeightKg, status: 'in_stock', zone_id: z.id });
    if (!r.ok()) throw new Error(`tagViaApi ${r.status()} ${await r.text()}`);
    const b = await r.json(); codes.push(b.label?.code ?? b.events?.[0]?.payload?.code ?? b.code);
  }
  return codes;
}

/** Create an approved order straight through the API (site manager creates, CEO approves) so a screen test has its own order. */
export async function approvedOrder(sm: A, ceo: A, lines: [string, number][], site = 'mats'): Promise<{ id: string; number: string; clientName: string }> {
  const cfg = await siteConfig(sm, site);
  const clients = (await sm.json(`/sites/${site}/clients`)).clients as { id: string; channel: string }[];
  const client = clients.find((c) => c.channel === 'export')!;
  const id = uuid7();
  const r = await sm.post(`/sites/${site}/orders`, { order_id: id, client_id: client.id, lines: lines.map(([n, b]) => ({ product_id: cfg.products.find((p) => p.internalName === n)!.id, bundles: b })) });
  if (!r.ok()) throw new Error(`order ${r.status()} ${await r.text()}`);
  const a = await ceo.post(`/sites/${site}/orders/${id}/approve`, {});
  if (!a.ok()) throw new Error(`approve ${a.status()} ${await a.text()}`);
  const o = (await a.json()).order;
  return { id, number: o.number, clientName: o.clientName };
}
/** In-stock bundle codes of a product at a site, from the API. */
export async function stockCodes(a: A, site: string, productName: string): Promise<string[]> {
  const c = await siteConfig(a, site); const p = c.products.find((x) => x.internalName === productName)!;
  const rows = await allLabels(a, site, 'in_stock');
  // bundles already scanned onto an open lorry stay "in_stock" until the gate passes: skip them
  const loaded = new Set(((await a.json(`/sites/${site}/dispatches`)).dispatches as { scannedCodes: string[] }[]).flatMap((d) => d.scannedCodes));
  return rows.filter((l) => l.productId === p.id && !loaded.has(l.code)).map((l) => l.code);
}

export interface DispatchRow { id: string; orderRef: string; scannedCodes: string[]; gateStatus: string; deliveryNoteNo: string; gateKg: number | null; expectedKg: number; overrideReason?: string | null; gateReasons?: string[]; pod?: unknown }
/** Start a dispatch for an order, scan `n` codes and (optionally) weigh, all through the API. */
export async function loadViaApi(sk: A, order: { id: string }, codes: string[], opts: { weigh?: number | 'expected' } = {}): Promise<DispatchRow> {
  const snap = await sk.json(`/sites/mats/orders/${order.id}/dispatch-snapshot`);
  const id = uuid7();
  const st = await sk.post('/sites/mats/dispatches', { dispatchId: id, driver: 'Api Driver', vehicle: 'UBK 442X', order: { orderRef: snap.order_ref ?? snap.orderRef, clientName: snap.client_name ?? snap.clientName, site: snap.site, lines: (snap.lines as { product_id?: string; productId?: string; bundles: number }[]).map((l) => ({ productId: l.product_id ?? l.productId, bundles: l.bundles })) } });
  if (!st.ok()) throw new Error(`start ${st.status()} ${await st.text()}`);
  for (const c of codes) { const r = await sk.post(`/sites/mats/dispatches/${id}/scan`, { code: c }); if (!r.ok()) throw new Error(`scan ${c} ${r.status()} ${await r.text()}`); }
  let d = (await (await sk.get(`/sites/mats/dispatches/${id}`)).json()).dispatch ?? (await (await sk.get(`/sites/mats/dispatches/${id}`)).json());
  if (opts.weigh !== undefined) {
    const kg = opts.weigh === 'expected' ? d.expectedKg : opts.weigh;
    const w = await sk.post(`/sites/mats/dispatches/${id}/gate-weight`, { kg });
    if (!w.ok()) throw new Error(`weigh ${w.status()} ${await w.text()}`);
    d = (await w.json()).dispatch;
  }
  return d as DispatchRow;
}
export async function goGroup(page: Page, path: string) {
  await page.evaluate((p) => { window.history.pushState({}, '', p); window.dispatchEvent(new PopStateEvent('popstate')); }, `/g/${path}`);
  await page.waitForURL((u) => u.pathname === `/g/${path}`);
  await page.waitForLoadState('networkidle').catch(() => undefined);
}

export const pickOrder = async (page: Page, number: string) => { await page.getByRole('option', { name: new RegExp(number) }).click(); await expect(page.getByRole('option', { name: new RegExp(number) })).toHaveAttribute('aria-selected', 'true'); };
export const startLoading = async (page: Page, driver = 'Test Driver', vehicle = 'UBK 442X') => {
  await page.getByPlaceholder('Select below or type driver name').fill(driver);
  await page.getByRole('radiogroup', { name: 'Vehicle' }).getByRole('radio', { name: vehicle }).click();
  await page.getByRole('button', { name: /^Start loading/ }).last().click();
  await expect(page.getByText('Loading started').first()).toBeVisible();
};
export const scan = async (page: Page, code: string) => { const i = page.getByPlaceholder('Scan a sticker, or type its code'); await i.fill(code); await i.press('Enter'); };



/* ---------- pin-login is rate limited: log in once per user and reuse the token across tests ---------- */
import { API_URL, PIN } from './env';
import type { APIRequestContext } from '@playwright/test';
const tokens = new Map<string, string>();
export async function api(request: APIRequestContext, username: string): Promise<A> {
  let token = tokens.get(username);
  if (!token) {
    const r = await request.post(`${API_URL}/auth/pin-login`, { data: { username, pin: PIN }, headers: { Accept: 'application/json' } });
    expect(r.ok(), `pin-login ${username}: ${r.status()} ${await r.text()}`).toBeTruthy();
    const b = await r.json(); token = b.token ?? b.data?.token; tokens.set(username, token!);
  }
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };
  return {
    token: token!, login: undefined,
    get: (path: string) => request.get(`${API_URL}${path}`, { headers }),
    post: (path: string, data?: unknown) => request.post(`${API_URL}${path}`, { headers, data }),
    put: (path: string, data?: unknown) => request.put(`${API_URL}${path}`, { headers, data }),
    json: async (path: string) => { const x = await request.get(`${API_URL}${path}`, { headers }); expect(x.ok(), `GET ${path}: ${x.status()}`).toBeTruthy(); return x.json(); },
  } as A;
}

/** Every label with a status, across pages (the API pages 50 at a time and ignores `limit`). */
export async function allLabels(a: A, site: string, status = 'in_stock') {
  type L = { id: string; code: string; productId: string | null; kind: string; stageCode: string; machineId: string | null; status: string; zoneId: string | null };
  const out: L[] = [];
  for (let page = 1; page < 40; page++) {
    const r = await a.json(`/sites/${site}/labels?status=${status}&page=${page}`);
    out.push(...(r.data as L[]));
    if (out.length >= r.total || (r.data as L[]).length === 0) break;
  }
  return out;
}
