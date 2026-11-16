import { expect, type Page } from '@playwright/test';
import { loginAs, openFirstPortal } from './helpers';

/** SPA navigation without a reload (a hard load returns to the portal picker). */
export async function spaGo(page: Page, path: string) {
  await page.evaluate((p) => { history.pushState({}, '', p); window.dispatchEvent(new PopStateEvent('popstate')); }, path);
  await page.waitForURL((u) => u.pathname === path.split('?')[0]);
  await page.waitForLoadState('networkidle').catch(() => undefined);
}

/** Sign in, open the first portal, and optionally land on a path. */
const logins: number[] = [];
const lastGroup = new Map<string, number>();
export async function enter(page: Page, username: string, path?: string) {
  // The sign-in form is limited to 10 a minute per client; stay under it across the whole run.
  const now = Date.now(); while (logins.length && now - logins[0] > 60_000) logins.shift();
  if (logins.length >= 7) await page.waitForTimeout(61_000 - (now - logins[0]));
  logins.push(Date.now());
  // A group user's app syncs ~100 requests a cycle against a 600/min per-user limit: keep two group sessions of one user apart.
  if (/^(ceo|admin)/.test(username)) {
    const wait = 45_000 - (Date.now() - (lastGroup.get(username) ?? 0));
    if (wait > 0) await page.waitForTimeout(wait);
    lastGroup.set(username, Date.now());
  }
  await loginAs(page, username);
  await openFirstPortal(page);
  if (path) { await spaGo(page, path); await expect(page.locator('main').first()).toBeVisible(); }
}
