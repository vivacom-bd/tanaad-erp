import { expect, test as base, type APIRequestContext, type Page } from '@playwright/test';
import { API_URL, PASSWORD, PIN } from './env';

/** Sign in through the real sign-in screen (username + password form). Floor roles may use the PIN pad: `{ pin: true }`. */
export async function loginAs(page: Page, username: string, secret = PASSWORD, opts: { pin?: boolean; site?: 'mats' | 'bags' | 'recycling' } = {}) {
  await page.goto('/');
  if (opts.pin) {
    await page.getByRole('tab', { name: /pin|tablet/i }).click();
    if (opts.site) await page.getByRole('button', { name: new RegExp(opts.site === 'recycling' ? 'recycl' : opts.site, 'i') }).first().click();
    throw new Error('PIN sign-in on the tablet picker chooses people by display name; use loginAs(page, username) or api(...) for the PIN endpoint.');
  }
  await page.getByPlaceholder(/username/i).fill(username);
  await page.getByPlaceholder('Password').fill(secret);
  await page.getByRole('button', { name: /^sign in/i }).click();
  await expect(page.getByRole('heading', { name: /./ }).first()).toBeVisible();
  await page.waitForURL(/\/portals/, { timeout: 45_000 });
}

/** On /portals, open the first workspace card (the group card for the CEO) and wait for the app shell. */
export async function openFirstPortal(page: Page) {
  await page.locator('article').first().getByRole('button', { name: /open|enter|continue|go/i }).first().click();
  await expect(page.locator('nav[aria-label="Main"], nav[aria-label="Primary"]').first()).toBeVisible({ timeout: 20_000 });
}

export const goOffline = (page: Page) => page.context().setOffline(true);
export const goOnline = (page: Page) => page.context().setOffline(false);

/** Real-API helper for assertions: logs in with the PIN endpoint and returns small typed verbs. */
export async function api(request: APIRequestContext, username: string, pin = PIN) {
  const r = await request.post(`${API_URL}/auth/pin-login`, { data: { username, pin }, headers: { Accept: 'application/json' } });
  expect(r.ok(), `pin-login ${username}: ${r.status()} ${await r.text()}`).toBeTruthy();
  const body = await r.json();
  const token: string = body.token ?? body.data?.token ?? body.access_token;
  expect(token, 'pin-login returned no token').toBeTruthy();
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };
  return {
    token, login: body,
    get: (path: string) => request.get(`${API_URL}${path}`, { headers }),
    post: (path: string, data?: unknown) => request.post(`${API_URL}${path}`, { headers, data }),
    put: (path: string, data?: unknown) => request.put(`${API_URL}${path}`, { headers, data }),
    json: async (path: string) => { const x = await request.get(`${API_URL}${path}`, { headers }); expect(x.ok(), `GET ${path}: ${x.status()}`).toBeTruthy(); return x.json(); },
  };
}

export interface Watch { consoleErrors: string[]; failedCalls: string[]; allow: (re: RegExp) => void; }

/** Collects console errors and API 4xx/5xx for a page; `assertClean()` fails the test on anything not allow-listed. */
export function watch(page: Page): Watch & { assertClean: () => void } {
  const consoleErrors: string[] = [], failedCalls: string[] = []; const allowed: RegExp[] = [];
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(`${m.text()} @ ${m.location().url}`); });
  page.on('pageerror', (e) => consoleErrors.push(`pageerror: ${e.message}`));
  page.on('response', (r) => { if (r.url().startsWith(API_URL) && r.status() >= 400) failedCalls.push(`${r.status()} ${r.request().method()} ${r.url().replace(API_URL, '')}`); });
  const keep = (xs: string[]) => xs.filter((x) => !allowed.some((re) => re.test(x)));
  return { consoleErrors, failedCalls, allow: (re) => allowed.push(re), assertClean: () => {
    // A failed network resource logs a console error that duplicates the failed call; report each once.
    expect.soft(keep(failedCalls), 'API calls that failed (4xx/5xx)').toEqual([]);
    expect.soft(keep(consoleErrors).filter((e) => !/Failed to load resource/.test(e)), 'console errors').toEqual([]);
  } };
}

/** `test` with an automatic watcher (`w`) and a full-page screenshot attached on failure. */
export const test = base.extend<{ w: ReturnType<typeof watch> }>({
  w: async ({ page }, use, info) => {
    const w = watch(page);
    await use(w);
    if (info.status !== info.expectedStatus) {
      await info.attach('failure', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' }).catch(() => undefined);
    }
  },
});
export { expect };

/** Visit the role's primary navigation: expand every hub, then follow each link once. Returns the visited paths. */
export async function visitPrimaryNav(page: Page, max = 14): Promise<string[]> {
  const nav = page.locator('nav[aria-label="Main"]').first();
  await expect(nav).toBeVisible();
  for (const b of await nav.locator('button[aria-expanded="false"]').all()) await b.click().catch(() => undefined);
  const hrefs = [...new Set(await nav.locator('a[href^="/"]').evaluateAll((as) => as.map((a) => (a as HTMLAnchorElement).getAttribute('href')!)))].slice(0, max);
  const visited: string[] = [];
  for (const href of hrefs) {
    const link = nav.locator(`a[href="${href}"]`).first();
    if (!(await link.isVisible())) continue;
    await link.click();
    await page.waitForURL((u) => u.pathname === href.split('?')[0], { timeout: 15_000 });
    await page.waitForLoadState('networkidle').catch(() => undefined);
    await expect(page.locator('main, [role="main"], h1').first()).toBeVisible();
    visited.push(href);
  }
  return visited;
}
