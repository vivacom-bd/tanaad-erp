import { expect, loginAs, openFirstPortal, test } from '../../support/helpers';
import { API_URL } from '../../support/env';

// Request budget: how many API calls a sign-in costs and how fast an idle tab polls. The sync batches every site's tables into one
// GET /sites/{site}/snapshots call and skips the per-site views while the ledger has not moved, so these numbers stay small.
// Keep the budgets honest: raise one only with a reason.
const IDLE_MS = 70_000;
const ROLES = [
  { username: 'ceo1', label: 'CEO (3 sites)', signIn: 40, idlePerMin: 12 },
  { username: 'supmats', label: 'Supervisor, Mats', signIn: 40, idlePerMin: 12 },
  { username: 'salesmats', label: 'Sales, Mats', signIn: 30, idlePerMin: 12 },
];

for (const r of ROLES) {
  test(`request budget: ${r.label} (${r.username})`, async ({ page }) => {
    test.setTimeout(IDLE_MS + 90_000);
    const calls: { at: number; line: string }[] = [];
    page.on('request', (q) => { if (q.url().startsWith(API_URL)) calls.push({ at: Date.now(), line: `${q.method()} ${q.url().replace(API_URL, '').split('?')[0]}` }); });

    await loginAs(page, r.username);
    await openFirstPortal(page);
    await page.waitForLoadState('networkidle').catch(() => undefined);
    const signInCalls = calls.length;
    const batched = calls.filter((c) => /\/snapshots$/.test(c.line)).length;
    const singles = calls.filter((c) => /\/snapshots\/[^/]+$/.test(c.line)).length;

    const from = calls.length; const t0 = Date.now();
    await page.waitForTimeout(IDLE_MS);
    const idle = calls.slice(from); const perMin = (idle.length / ((Date.now() - t0) / 60_000));

    const tally = (xs: typeof calls) => { const m = new Map<string, number>(); for (const c of xs) m.set(c.line.replace(/[0-9a-f]{8}-[0-9a-f-]{27}/g, ':id'), (m.get(c.line.replace(/[0-9a-f]{8}-[0-9a-f-]{27}/g, ':id')) ?? 0) + 1); return [...m].sort((a, b) => b[1] - a[1]).map(([k, v]) => `${v}x ${k}`).join('\n  '); };
    console.log(`[budget] ${r.username}: sign-in ${signInCalls} calls (${batched} batched snapshot, ${singles} single); idle ${idle.length} in ${IDLE_MS / 1000}s = ${perMin.toFixed(1)}/min\n  ${tally(calls.slice(0, signInCalls))}\n idle:\n  ${tally(idle)}`);

    expect.soft(singles, 'single-table snapshot calls (the batch should cover them)').toBe(0);
    expect(signInCalls, `sign-in requests for ${r.username}`).toBeLessThanOrEqual(r.signIn);
    expect(perMin, `idle requests per minute for ${r.username}`).toBeLessThanOrEqual(r.idlePerMin);
  });
}
