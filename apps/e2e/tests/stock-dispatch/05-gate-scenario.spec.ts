import { expect, test } from '../../support/helpers';
import { approvedOrder, go, goGroup, openAs, pickOrder, shoot, startLoading, api } from '../../support/stock';

test.use({ actionTimeout: 15_000 }); // a missing control fails in 15s, not at the test timeout

// THE GATE SCENARIO, end to end in the browser, five people on five browser contexts:
//   storekeeper loads a lorry ten bundles short and weighs -> the server HOLDS it at the gate
//   a weigher cannot override (plain 403) -> CEO sees the critical gate_variance -> site manager overrides with a reason
//   -> the delivery note number is assigned at the override -> the audit feed shows the hold and the override.
test('gate scenario: ten bundles short -> held -> CEO sees critical gate_variance -> weigher blocked -> site manager overrides -> audit shows both', async ({ browser, request }) => {
  test.setTimeout(420_000);
  const sk = await api(request, 'storemats'); const sm = await api(request, 'smmats'); const ceo = await api(request, 'ceo1');
  const order = await approvedOrder(sm, ceo, [['Sajaada light', 12]]);
  const find = async () => ((await sk.json('/sites/mats/dispatches')).dispatches as { id: string; orderRef: string; scannedCodes: string[]; gateStatus: string; deliveryNoteNo: string; gateReasons: string[]; overrideReason: string | null }[]).find((d) => d.orderRef === order.number)!;

  const mk = async () => { const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } }); const page = await ctx.newPage(); return { ctx, page, fails: [] as string[], errs: [] as string[] }; };
  const store = await mk(); const ceoUi = await mk(); const mgr = await mk();
  for (const u of [store, ceoUi, mgr]) { u.page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) u.errs.push(m.text()); }); u.page.on('response', (r) => { if (r.url().includes('/api/v1') && r.status() >= 400) u.fails.push(`${r.status()} ${r.request().method()} ${r.url().split('/api/v1')[1]}`); }); }

  // ---- 1. storekeeper loads 2 of 12 and weighs
  const p = store.page;
  await openAs(p, 'storemats', { role: 'Storekeeper' });
  await go(p, 'stock/dispatch');
  await pickOrder(p, order.number);
  await startLoading(p, 'Hold Driver');
  for (let i = 0; i < 2; i++) { await p.getByRole('button', { name: /Scan next label/ }).click(); await expect.poll(async () => (await find()).scannedCodes.length).toBe(i + 1); }
  await expect(p.getByText('2 of 12 bundles scanned').first()).toBeVisible();
  await expect(p.getByText(/Short load: 2 of 12 bundles scanned/)).toBeVisible();
  await p.getByRole('button', { name: /Weigh at the gate/ }).click();
  await p.getByRole('button', { name: /^Use [\d,.]+ kg$/ }).click({ timeout: 20_000 });
  await expect(p.getByRole('heading', { name: 'Gate check held' })).toBeVisible();
  const held = await find();
  expect(held.gateStatus).toBe('hold');
  expect(held.gateReasons.join(' ')).toMatch(/10|short|bundle|scanned|ordered/i);
  expect(held.deliveryNoteNo ?? '', 'no delivery note number while held').toMatch(/^$|pending/i);
  await expect(p.getByText(/CEOs alerted/)).toBeVisible();
  await expect(p.getByText('Why the gate is locked')).toBeVisible();
  await shoot(p, 'storemats-gate-held');

  // ---- 2. the weigher (storekeeper) cannot override: plain 403 sentence, nothing changes
  await p.getByRole('button', { name: 'Site Manager override' }).click();
  const dlg = p.getByRole('dialog');
  await expect(dlg.getByRole('button', { name: 'Release the lorry' })).toBeDisabled(); // reason is required first
  await dlg.getByPlaceholder('What was checked, and why the lorry may leave').fill('I say go');
  await dlg.getByRole('button', { name: 'Release the lorry' }).click();
  await expect(dlg.getByText('Only the Site Manager of this site may override the gate.', { exact: false }).or(dlg.locator('[class*="bad"]').filter({ hasText: /Site Manager/ })).first()).toBeVisible();
  expect(store.fails.some((f) => /^403 POST .*override/.test(f))).toBe(true);
  expect((await find()).gateStatus).toBe('hold');
  await shoot(p, 'storemats-gate-override-refused');
  await p.keyboard.press('Escape');

  // ---- 3. CEO: critical gate_variance in Approvals and in the alert bell
  const c = ceoUi.page;
  await openAs(c, 'ceo1');
  await goGroup(c, 'approvals');
  const card = c.getByText(new RegExp(`Gate held: order ${order.number}`)).first();
  await expect(card).toBeVisible({ timeout: 30_000 });
  await card.click();
  await expect(c.getByText('Gate variance').first()).toBeVisible();
  await expect(c.getByText(/critical/i).first()).toBeVisible();
  await shoot(c, 'ceo1-approvals-gate-held');
  await c.getByRole('button', { name: 'Alerts' }).click();
  // DEFECT (reported): the policy writes an alert fact ("Gate locked at ...") for the CEO but the bell stays empty ("No alerts").
  await expect(c.getByText(/Gate locked|Gate held/).first()).toBeVisible({ timeout: 20_000 });
  await c.keyboard.press('Escape');

  // ---- 4. site manager overrides with a reason; the delivery note is assigned
  const m = mgr.page;
  await openAs(m, 'smmats');
  await go(m, 'stock/dispatch');
  await pickOrder(m, order.number);
  await expect(m.getByText('Held at the gate').first()).toBeVisible();
  await m.getByRole('button', { name: 'Site Manager override' }).click();
  await m.getByRole('dialog').getByPlaceholder('What was checked, and why the lorry may leave').fill('Client collected the other ten on a second lorry; checked the weight slip');
  await m.getByRole('dialog').getByRole('button', { name: 'Release the lorry' }).click();
  await expect(m.getByText(/Override recorded for DN-\d{5}/).first()).toBeVisible();
  await expect(m.getByRole('heading', { name: 'Override recorded' })).toBeVisible();
  const after = await find();
  expect(after.gateStatus).toBe('overridden');
  expect(after.deliveryNoteNo).toMatch(/^DN-\d{5}$/);
  expect(after.overrideReason).toContain('second lorry');
  await shoot(m, 'smmats-gate-overridden');

  // ---- 5. the CEO's feed: the exception is resolved and the audit log shows both entries
  await goGroup(c, 'approvals');
  await expect(c.getByText(new RegExp(`Gate held: order ${order.number}`))).toHaveCount(0, { timeout: 30_000 });
  await goGroup(c, 'audit');
  await expect(c.getByRole('heading', { name: 'Audit feed' })).toBeVisible();
  // the feed shows the newest 100 of thousands of lines and seeded lines carry a later clock time, so use the type filters
  await c.getByRole('tab', { name: /^Override/ }).click();
  await expect(c.getByText(new RegExp(`Overrode the gate hold on dispatch ${order.number}`)).first()).toBeVisible({ timeout: 20_000 });
  await expect(c.getByText(/Client collected the other ten on a second lorry/).first()).toBeVisible();
  await expect(c.getByText('Moses Tumusiime').first()).toBeVisible();
  await c.getByRole('tab', { name: 'All', exact: true }).click();
  await c.getByPlaceholder('Search the feed').fill(order.number);
  const holdLine = c.getByText(new RegExp(`${order.number}`)).filter({ hasText: /hold|held|weigh/i });
  await expect(holdLine.first()).toBeVisible({ timeout: 20_000 });
  await shoot(c, 'ceo1-audit-gate');
  const feed = JSON.stringify(await (await sk.get('/sites/mats/dispatches/' + after.id)).json());
  expect(feed).toContain('second lorry');

  // the only 4xx/5xx anywhere were the planned 403 for the weigher
  expect(store.fails.filter((f) => !/^403 POST .*override/.test(f)), 'storekeeper calls').toEqual([]);
  expect(ceoUi.fails, 'ceo calls').toEqual([]); expect(mgr.fails, 'manager calls').toEqual([]);
  expect([...store.errs, ...ceoUi.errs, ...mgr.errs], 'console errors').toEqual([]);
  await store.ctx.close(); await ceoUi.ctx.close(); await mgr.ctx.close();
});
