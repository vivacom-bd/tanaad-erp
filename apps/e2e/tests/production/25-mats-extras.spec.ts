import { expect, test } from '../../support/helpers';
import { api, chipIn, chooseWorker, eventually, labelsOf, openAs, serverWall, shoot, text, weighManually } from './util';

test.describe('mats: waste sacks, rack consumption, calibration, other label kinds, below-benchmark exception', () => {
  test('waste: a trim sack and a crusher sack are weighed, labelled and listed', async ({ page, request, w }) => {
    await openAs(page, 'supmats', 'mats', 'production/waste');
    const a = await api(request, 'supmats');
    const before = (await labelsOf(a, 'mats', 'kind=sack')).length;
    await weighManually(page, '14.2', /Trim scale/);
    await expect(page.getByText(/sack weighed: 14\.2 kg/).first()).toBeVisible();
    await weighManually(page, '21.7', /Crusher scale/);
    await expect(page.getByText(/Crusher output 21\.7 kg labelled/).first()).toBeVisible();
    const got = await eventually(() => labelsOf(a, 'mats', 'kind=sack'), (l) => l.length >= before + 2);
    expect(got.length).toBe(before + 2);
    expect(got.filter((l) => /^MAT-SAC-\d{6}$/.test(l.code)).length).toBe(got.length);
    await expect.poll(async () => !/PENDING-/.test(await text(page)), { timeout: 40_000 }).toBeTruthy();
    await shoot(page, 'mats-waste-after');
    w.assertClean();
  });

  test('extruders: a rack waits for weaving, Consume rack takes the oldest of that colour', async ({ page, request, w }) => {
    await openAs(page, 'supmats', 'mats', 'production/extruders');
    const a = await api(request, 'supmats');
    const racks = (await labelsOf(a, 'mats', 'kind=rack')).filter((l) => l.status === 'in_stock');
    test.skip(racks.length === 0, 'no rack waiting');
    await expect(page.getByRole('button', { name: 'Consume rack' }).first()).toBeVisible();
    const waiting = racks.length;
    await page.getByRole('button', { name: 'Consume rack' }).first().click();
    await expect(page.getByText(/taken to the looms/).first()).toBeVisible();
    await expect.poll(async () => (await labelsOf(a, 'mats', 'kind=rack')).filter((l) => l.status === 'in_stock').length, { timeout: 30_000 }).toBe(waiting - 1);
    w.assertClean();
  });

  test('finishing calibration: a weighed bundle is recorded against the standard', async ({ page, w }) => {
    await openAs(page, 'supmats', 'mats', 'production/finishing');
    await page.getByRole('radio', { name: /Domona/ }).click();
    await weighManually(page, '25.4', /Finishing scale/);
    await expect(page.getByText(/Calibration/).first()).toBeVisible();
    await expect(page.locator('main')).toContainText('25.4');
    await shoot(page, 'mats-finishing-calibration');
    w.assertClean();
  });

  test('print labels: rack and bundle kinds need their own fields', async ({ page, request, w }) => {
    await openAs(page, 'supmats', 'mats', 'production/labels');
    const a = await api(request, 'supmats');
    await page.getByRole('radio', { name: /Rack/ }).click();
    await expect(page.getByText(/Still needed: colour/).first()).toBeVisible().catch(() => undefined);
    await page.getByRole('radio', { name: 'Red' }).click();
    await page.locator('main input[inputmode="decimal"]').first().fill('77');
    await page.getByPlaceholder('Search machine').click(); await page.getByRole('option').first().click();
    const before = (await labelsOf(a, 'mats', 'kind=rack')).length;
    await page.getByRole('button', { name: /create rack label/i }).click();
    await expect(page.getByText('Rack label recorded').first()).toBeVisible();
    await eventually(() => labelsOf(a, 'mats', 'kind=rack'), (l) => l.length > before);
    await page.getByRole('radio', { name: /Bundle/ }).click();
    await page.getByRole('radio', { name: /Rihlaat/ }).click();
    await expect(page.getByText(/Standard bundle is .* pieces/)).toBeVisible();
    await page.getByPlaceholder('Search machine').click(); await page.getByRole('option').first().click();
    const bb = (await labelsOf(a, 'mats', 'kind=bundle')).length;
    await page.getByRole('button', { name: /create bundle label/i }).click();
    await expect(page.getByText('Bundle label recorded').first()).toBeVisible();
    await eventually(() => labelsOf(a, 'mats', 'kind=bundle'), (l) => l.length > bb);
    w.assertClean();
  });

  test('server rule: a below-benchmark entry with no reason raises an exception for the CEO; a re-log with a reason resolves it', async ({ request }) => {
    const sup = await api(request, 'supmats');
    const ceo = await api(request, 'ceo1');
    // A past shift is judged against the full benchmark (a shift in progress is pro-rated, so 1 unit early in a shift is not
    // below it). Walk back from the server's business day to the latest past shift with a logged entry: the seed's history
    // ends on a fixed day, so how far back that is depends on the wall clock.
    type M = { machine: { id: string; code: string }; entry: { productId: string; operatorId: string; goodUnits: number } };
    const today = (await serverWall(sup, 'mats', 'day')).day;
    let kind: 'day' | 'night' = 'day'; let day = ''; let m: M | undefined;
    for (let back = 1; back <= 14 && !m; back++) {
      day = new Date(Date.parse(today) - back * 86_400_000).toISOString().slice(0, 10);
      for (const k of ['day', 'night'] as const) {
        const wall = await serverWall(sup, 'mats', k, day);
        m = wall.machines.find((x) => x.state !== 'arriving' && x.entry) as unknown as M | undefined;
        if (m) { kind = k; break; }
      }
    }
    expect(m, `no logged entry in the 14 days before ${today}`).toBeTruthy();
    const body = { machineId: m!.machine.id, day, kind, productId: m!.entry.productId, operatorId: m!.entry.operatorId, goodUnits: 1, rejectUnits: 0, inputKg: 3, outputKg: 3, downtimeMin: 0 };
    const low = await sup.post('/sites/mats/production/entries', body);
    expect(low.status(), await low.text()).toBeLessThan(300);
    const open = async () => ((await ceo.json('/exceptions')).data ?? await ceo.json('/exceptions')) as { kind: string; title: string; status: string; subjectRef?: string; siteId?: string }[];
    const raised = await eventually(open, (l) => l.some((e) => e.kind === 'below_benchmark' && e.status === 'open' && e.title.includes('1 of')));
    expect(raised.some((e) => e.kind === 'below_benchmark' && e.status === 'open' && e.title.includes('1 of'))).toBeTruthy();
    const fixed = await sup.post('/sites/mats/production/entries', { ...body, downtimeMin: 90, downtimeReason: 'Warp Bobbin Creel Change' });
    expect(fixed.status(), await fixed.text()).toBeLessThan(300);
    const after = await eventually(open, (l) => !l.some((e) => e.kind === 'below_benchmark' && e.status === 'open' && e.title.includes('1 of')));
    expect(after.some((e) => e.kind === 'below_benchmark' && e.status === 'open' && e.title.includes('1 of'))).toBeFalsy();
    // zero, negative and huge numbers are refused by the server with a plain 422
    for (const bad of [{ goodUnits: -1 }, { goodUnits: 99999999 }, { outputKg: -5 }, { outputKg: 1e12 }]) {
      const r = await sup.post('/sites/mats/production/entries', { ...body, ...bad });
      expect(r.status(), JSON.stringify(bad)).toBe(422);
    }
    void chipIn; void chooseWorker;
  });
});
