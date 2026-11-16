import { expect, test } from '../../support/helpers';
import { api, chipIn, eventually, expectTilesMatchServer, fmt, go, labelsOf, openAs, saveEntry, serverWall, shoot, text, weighManually } from './util';

interface Delivery { id: string; collector: string; grossKg: number; tareKg: number; deductionPct: number; netKg: number; pricePerKg: number; amount: number; voucherNo: string | null; provisionalNo: string | null; paid: boolean; day: string }
const deliveriesOn = async (a: Awaited<ReturnType<typeof api>>, day: string): Promise<Delivery[]> => (await a.json(`/sites/recycling/collector-deliveries?day=${day}`)).deliveries;

async function fillIntake(page: import('@playwright/test').Page, opts: { collector: string; gross: string; tare?: string; deduction?: RegExp; price?: RegExp; newCollector?: boolean }) {
  const combo = page.getByPlaceholder('Search collectors');
  await combo.click();
  if (opts.newCollector) { await page.getByRole('option', { name: /New collector/ }).click(); await page.getByLabel('New collector name').fill(opts.collector); }
  else { await combo.fill(opts.collector); await page.getByRole('option').filter({ hasText: opts.collector }).first().click(); }
  await weighManually(page, opts.gross, /Intake scale/);
  const tare = page.locator('main input[inputmode="decimal"]').last();
  if (opts.tare !== undefined) await page.locator('main').getByText('Tare', { exact: true }).locator('xpath=ancestor::*[contains(@class,"grid") or self::div][1]').locator('input').first().fill(opts.tare).catch(async () => { await tare.fill(opts.tare!); });
  if (opts.deduction) await chipIn(page, 'Deduction', opts.deduction);
  if (opts.price) await chipIn(page, 'Price per kg', opts.price);
  await page.getByRole('button', { name: /Take scale photo/ }).click();
}

test.describe('recycling: line wall, intake, collector ledger, sacks, sheet, labels', () => {
  test('line wall: server numbers, tabs, a kg in/out entry with decimals', async ({ page, request, w }) => {
    await openAs(page, 'suprec', 'recycling', 'production/wall');
    const a = await api(request, 'suprec');
    let wall = await serverWall(a, 'recycling', 'day');
    if (!wall.machines.some((m) => m.state !== 'arriving' && m.entry)) {
      // Nothing logged yet on this business day (the seed's history ends on a fixed day): log one line from the latest logged
      // day's product and operator so the wall has an entry to open, then reload the page.
      type M = { machine: { id: string }; entry: { productId: string; operatorId: string } };
      let src: M | undefined;
      for (let back = 1; back <= 40 && !src; back++) {
        const d = new Date(Date.parse(wall.day) - back * 86_400_000).toISOString().slice(0, 10);
        for (const k of ['day', 'night'] as const) { src = (await serverWall(a, 'recycling', k, d)).machines.find((m) => m.state !== 'arriving' && m.entry) as unknown as M | undefined; if (src) break; }
      }
      expect(src, 'no logged recycling entry to copy').toBeTruthy();
      const r = await a.post('/sites/recycling/production/entries', { machineId: src!.machine.id, day: wall.day, kind: 'day', productId: src!.entry.productId, operatorId: src!.entry.operatorId, goodUnits: 0, rejectUnits: 0, inputKg: 500, outputKg: 480, downtimeMin: 0 });
      expect(r.status(), await r.text()).toBeLessThan(300);
      wall = await serverWall(a, 'recycling', 'day');
      await page.reload(); await page.waitForLoadState('networkidle');
    }
    const run = wall.machines.filter((m) => m.state !== 'arriving');
    const t = await text(page);
    expect(t).toContain(`${run.filter((m) => m.entry).length} logged`);
    const outKg = run.reduce((s, m) => s + (m.entry?.outputKg ?? 0), 0);
    expect(t).toMatch(new RegExp(`Pellets out ${fmt(outKg)} kg`));
    await expectTilesMatchServer(page, wall);
    await shoot(page, 'recycling-wall');
    await page.getByRole('tab', { name: 'Table View' }).click();
    await shoot(page, 'recycling-wall-table');
    await page.getByRole('tab', { name: 'Machine Wall' }).click();
    const code = run.find((m) => m.entry)!.machine.code;
    await page.locator(`[data-tile][aria-label^="${code},"]`).click();
    const dlg = page.getByRole('dialog');
    await expect(dlg).toContainText('Shift entry');
    const nums = dlg.locator('input[inputmode]');
    await nums.nth(0).fill('0'); await nums.nth(1).fill('0');
    await expect(dlg).toContainText('Still needed');
    await nums.nth(0).fill('900.5'); await nums.nth(1).fill('880.25');
    await expect(dlg).toContainText('% yield'.replace('% ', ''));
    await shoot(page, 'recycling-entry-sheet');
    await saveEntry(dlg);
    await expect.poll(async () => { const e = (await serverWall(a, 'recycling', 'day', wall.day)).machines.find((m) => m.machine.code === code)?.entry; return e && [e.inputKg, e.outputKg].join('/'); }, { timeout: 25_000 }).toBe('900.5/880.25');
    w.assertClean();
  });

  test('intake: validation, a voucher with a deduction preset, voucher number from the server', async ({ page, request, w }) => {
    await openAs(page, 'suprec', 'recycling', 'production/intake');
    const a = await api(request, 'suprec');
    await shoot(page, 'recycling-intake');
    const btn = page.getByRole('button', { name: 'Weigh in and issue voucher' });
    await expect(btn).toBeDisabled();
    await expect(page.getByText(/Still needed: a collector, the gross weight, the scale photo/)).toBeVisible();
    const day = (await a.json('/sites/recycling/machine-wall?shift=day')).day as string;
    const before = (await deliveriesOn(a, day)).length;
    await fillIntake(page, { collector: 'Okello Peter', gross: '1200', tare: '50', deduction: /^6%/, price: /900/ });
    await expect(btn).toBeEnabled();
    await expect(page.getByText(/Net weight/).first()).toBeVisible();
    await shoot(page, 'recycling-intake-filled');
    await btn.click();
    await expect(page.getByText(/Voucher .* issued/).first()).toBeVisible();
    const got = await eventually(() => deliveriesOn(a, day), (l) => l.length > before);
    const mine = got.find((d) => d.collector === 'Okello Peter' && d.grossKg === 1200)!;
    expect(mine, 'the 1,200 kg voucher is on the server').toBeTruthy();
    expect(mine.tareKg).toBe(50);
    expect(mine.deductionPct).toBe(6);
    expect(mine.netKg).toBeCloseTo((1200 - 50) * 0.94, 1);
    expect(mine.amount).toBe(Math.round(mine.netKg * 900));
    expect(mine.voucherNo).toMatch(/^IV-\d+$/);
    await expect.poll(async () => (await text(page)).includes(mine.voucherNo!), { timeout: 40_000 }).toBeTruthy();
    await expect(page.getByText('provisional')).toHaveCount(0);
    w.assertClean();
  });

  test('intake: gross not above the tare, zero and negative values are refused', async ({ page, w }) => {
    await openAs(page, 'suprec', 'recycling', 'production/intake');
    const btn = page.getByRole('button', { name: 'Weigh in and issue voucher' });
    await fillIntake(page, { collector: 'Juma Ssebunya', gross: '100', tare: '100' });
    await expect(btn).toBeDisabled();
    await expect(page.getByText(/a gross above the tare/).first()).toBeVisible();
    const tare = page.locator('main input[inputmode="decimal"]').last();
    await tare.fill('-20');
    await expect(tare).not.toHaveValue(/-/);
    await shoot(page, 'recycling-intake-invalid');
    w.assertClean();
  });

  test('intake offline: a provisional voucher is issued, the real number replaces it after sync', async ({ page, request, w }) => {
    await openAs(page, 'suprec', 'recycling', 'production/intake');
    const a = await api(request, 'suprec');
    const day = (await a.json('/sites/recycling/machine-wall?shift=day')).day as string;
    const before = (await deliveriesOn(a, day)).length;
    await page.context().setOffline(true);
    await expect(page.getByText(/Offline/).first()).toBeVisible({ timeout: 40_000 });
    await fillIntake(page, { collector: 'Offline Collector', gross: '777', tare: '7', newCollector: true });
    await page.getByRole('button', { name: 'Weigh in and issue voucher' }).click();
    await expect(page.getByText(/final number is set when it syncs/).first()).toBeVisible();
    await expect(page.getByText('provisional').first()).toBeVisible();
    await shoot(page, 'recycling-intake-offline');
    expect((await deliveriesOn(a, day)).length).toBe(before);
    await page.context().setOffline(false);
    const got = await eventually(() => deliveriesOn(a, day), (l) => l.some((d) => d.collector === 'Offline Collector'), 60_000);
    const mine = got.find((d) => d.collector === 'Offline Collector')!;
    expect(mine.grossKg).toBe(777);
    await expect.poll(async () => (await text(page)).includes(mine.voucherNo!), { timeout: 60_000 }).toBeTruthy();
    await expect(page.getByText('provisional')).toHaveCount(0);
    w.assertClean();
  });

  test('pay a collector: online only; unsynced waits; day closed gives a plain message; reopened day pays and reaches the cash book', async ({ page, request, browser, w }) => {
    const a = await api(request, 'suprec');
    const cashier = await api(request, 'cashrec');
    const manager = await api(request, 'smrec');
    const day = (await a.json('/sites/recycling/machine-wall?shift=day')).day as string;
    const unpaid = (await deliveriesOn(a, day)).filter((d) => !d.paid && d.voucherNo);
    expect(unpaid.length).toBeGreaterThan(1);
    await openAs(page, 'cashrec', 'recycling', 'production/intake');
    // offline: refused plainly
    await page.context().setOffline(true);
    const payBtn = page.getByRole('button', { name: 'Pay', exact: true }).first();
    await payBtn.click();
    await expect(page.getByText('Payment not recorded').first()).toBeVisible();
    await expect(page.getByText(/No connection\. Paying a collector needs the server/).first()).toBeVisible();
    await page.context().setOffline(false);
    // close the cash day: paying is refused with a plain message
    const cd = await cashier.json(`/sites/recycling/cash/days/${day}`);
    const closed = await cashier.post(`/sites/recycling/cash/days/${day}/close`, { counted: cd.totals?.expectedClosing ?? cd.day.opening, denominations: [] });
    expect([200, 201, 409], await closed.text()).toContain(closed.status()); // 409 day_locked: the seed already closed it
    w.allow(/409 POST \/sites\/recycling\/collector-deliveries\/.*\/pay|423|403 POST|422 POST/);
    await go(page, 'recycling', 'production/intake');
    await page.getByRole('button', { name: 'Pay', exact: true }).first().click();
    await expect(page.getByText('Payment not recorded').first()).toBeVisible();
    await expect(page.getByText(/cash book is closed/i).first()).toBeVisible();
    // reopen (site manager) and pay for real
    const re = await manager.post(`/sites/recycling/cash/days/${day}/reopen`, { reason: 'e2e: pay a collector' });
    expect([200, 201], await re.text()).toContain(re.status());
    await go(page, 'recycling', 'production/intake');
    const before = (await cashier.json(`/sites/recycling/cash/days/${day}`)).entries.length;
    // The first Pay button may belong to an earlier day's voucher, so count unpaid vouchers across the whole ledger, not just today's.
    const unpaidNow = async () => ((await a.json('/sites/recycling/collector-ledger')).collectors as { unpaidDeliveries: number }[]).reduce((t, r) => t + r.unpaidDeliveries, 0);
    const unpaidBefore = await unpaidNow();
    await page.getByRole('button', { name: 'Pay', exact: true }).first().click();
    await expect(page.getByText('Payment recorded').first()).toBeVisible();
    await expect.poll(async () => (await cashier.json(`/sites/recycling/cash/days/${day}`)).entries.length, { timeout: 20_000 }).toBe(before + 1);
    await expect.poll(unpaidNow, { timeout: 20_000 }).toBe(unpaidBefore - 1);
    await shoot(page, 'recycling-intake-paid');
    void browser;
    w.assertClean();
  });

  test('collector ledger: totals match the server; search, tabs, detail sheet, CSV', async ({ page, request, w }) => {
    await openAs(page, 'suprec', 'recycling', 'production/collectors');
    const a = await api(request, 'suprec');
    const ledger = await a.json('/sites/recycling/collector-ledger');
    const rows = ledger.collectors as { collector: string; deliveries: number; unpaidDeliveries: number; totalNetKg: number; balance: number }[];
    await expect.poll(() => text(page), { timeout: 20_000 }).toContain(`Collectors ${rows.length}`);
    const t = await text(page);
    expect(t).toContain(`${rows.reduce((s, r) => s + r.deliveries, 0)} deliveries`);
    for (const r of rows) expect(t).toContain(r.collector);
    expect(t).toContain(`${rows.reduce((s, r) => s + r.unpaidDeliveries, 0)} vouchers unpaid`);
    await shoot(page, 'recycling-ledger');
    await page.getByPlaceholder('Search collectors').fill('Rose');
    await expect(page.locator('main table tbody tr')).toHaveCount(1);
    await page.getByPlaceholder('Search collectors').fill('zzzz');
    await expect(page.getByText(/No collector|no match|Nothing/i).first()).toBeVisible();
    await page.getByPlaceholder('Search collectors').fill('');
    await page.getByRole('tab', { name: /^Unpaid/ }).click();
    await expect(page.locator('main table tbody tr')).toHaveCount(rows.filter((r) => r.unpaidDeliveries > 0).length);
    await page.getByRole('tab', { name: /^Paid up/ }).click();
    await expect(page.locator('main table tbody tr')).toHaveCount(rows.filter((r) => r.unpaidDeliveries === 0).length);
    await page.getByRole('tab', { name: /^All/ }).click();
    await page.locator('main table tbody tr').filter({ hasText: 'Namukasa Rose' }).first().click();
    const dlg = page.getByRole('dialog');
    await expect(dlg).toContainText('Namukasa Rose');
    await shoot(page, 'recycling-ledger-detail');
    const [dl] = await Promise.all([page.waitForEvent('download'), dlg.getByRole('button', { name: 'Export this collector' }).click()]);
    expect(dl.suggestedFilename()).toMatch(/\.csv$/);
    w.assertClean();
  });

  test('pellet sacks: validation, a run of sacks, pending codes replaced', async ({ page, request, w }) => {
    await openAs(page, 'suprec', 'recycling', 'production/sacks');
    const a = await api(request, 'suprec');
    await shoot(page, 'recycling-sacks');
    const btn = page.getByRole('button', { name: /^Bag and label/ });
    await expect(btn).toBeDisabled();
    await expect(page.getByText(/Still needed: the number of sacks/)).toBeVisible();
    await chipIn(page, 'Grade', /Black/);
    const count = page.locator('main input[inputmode="numeric"]').first();
    await count.fill('0'); await expect(btn).toBeDisabled();
    await count.fill('3');
    const before = (await labelsOf(a, 'recycling', 'kind=sack')).length;
    await expect(btn).toBeEnabled();
    await btn.click();
    const got = await eventually(() => labelsOf(a, 'recycling', 'kind=sack'), (l) => l.length >= before + 3);
    expect(got.length).toBe(before + 3);
    expect(got.filter((l) => /^REC-SAC-\d{6}$/.test(l.code)).length).toBe(got.length);
    await expect.poll(async () => !/PENDING-/.test(await text(page)), { timeout: 40_000 }).toBeTruthy();
    w.assertClean();
  });

  test('recycling shift sheet and print labels', async ({ page, request, w }) => {
    await openAs(page, 'suprec', 'recycling', 'production/sheet');
    const a = await api(request, 'suprec');
    let wall = await serverWall(a, 'recycling', 'day');
    if (!wall.machines.some((m) => m.state !== 'arriving' && m.entry)) {
      // Nothing logged yet on this business day (the seed's history ends on a fixed day): log one line from the latest logged
      // day's product and operator so the wall has an entry to open, then reload the page.
      type M = { machine: { id: string }; entry: { productId: string; operatorId: string } };
      let src: M | undefined;
      for (let back = 1; back <= 40 && !src; back++) {
        const d = new Date(Date.parse(wall.day) - back * 86_400_000).toISOString().slice(0, 10);
        for (const k of ['day', 'night'] as const) { src = (await serverWall(a, 'recycling', k, d)).machines.find((m) => m.state !== 'arriving' && m.entry) as unknown as M | undefined; if (src) break; }
      }
      expect(src, 'no logged recycling entry to copy').toBeTruthy();
      const r = await a.post('/sites/recycling/production/entries', { machineId: src!.machine.id, day: wall.day, kind: 'day', productId: src!.entry.productId, operatorId: src!.entry.operatorId, goodUnits: 0, rejectUnits: 0, inputKg: 500, outputKg: 480, downtimeMin: 0 });
      expect(r.status(), await r.text()).toBeLessThan(300);
      wall = await serverWall(a, 'recycling', 'day');
      await page.reload(); await page.waitForLoadState('networkidle');
    }
    const mb = await a.json(`/sites/recycling/mass-balance?day=${wall.day}`);
    const t = await text(page);
    expect(t).toContain(`${wall.machines.filter((m) => m.entry).length} of ${wall.machines.filter((m) => m.state !== 'arriving').length} logged`);
    expect(t).toContain(fmt(mb.kgIn));
    await shoot(page, 'recycling-sheet');
    await go(page, 'recycling', 'production/labels');
    await shoot(page, 'recycling-print-label');
    await page.getByRole('button', { name: /create sack label/i }).click();
    await expect(page.getByText('Label not recorded').first()).toBeVisible();
    w.assertClean();
  });
});
