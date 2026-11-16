import type { Page } from '@playwright/test';
import { expect, test } from '../../support/helpers';
import { approvedOrder, go, loadViaApi, openAs, shoot, stockCodes, api } from '../../support/stock';

test.use({ actionTimeout: 15_000 }); // a missing control fails in 15s, not at the test timeout

// 1x1 PNG
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
const draw = async (page: Page, label: RegExp) => {
  const c = page.locator('canvas').filter({ has: page.locator(':scope') }).first();
  const box = (await page.locator(`canvas[aria-label]`).first().boundingBox())!;
  await page.mouse.move(box.x + 20, box.y + 40); await page.mouse.down();
  await page.mouse.move(box.x + 80, box.y + 20, { steps: 6 }); await page.mouse.move(box.x + 140, box.y + 70, { steps: 6 }); await page.mouse.up();
  void c; void label;
};


/** Save the drawn signature. Returns false when the media store is down (the dev stack has no S3/MinIO): then the form must stay unconfirmable. */
async function saveSignature(page: Page, w: { allow: (re: RegExp) => void }): Promise<boolean> {
  w.allow(/POST \/media/);
  const dlg = page.getByRole('dialog');
  await dlg.getByRole('button', { name: 'Save signature' }).click();
  const saved = dlg.getByText('Signature saved'); const failed = dlg.locator('.text-\\[var\\(--bad\\)\\]').filter({ hasText: /./ });
  await expect(saved.or(failed).first()).toBeVisible({ timeout: 20_000 });
  if (await saved.isVisible()) return true;
  test.info().annotations.push({ type: 'untestable', description: `POD signature upload failed: "${(await failed.first().innerText()).trim()}" (POST /media needs the S3/MinIO media disk, not running on this box). Confirm-delivery paths not exercised.` });
  await expect(dlg.getByRole('button', { name: 'Confirm delivery' })).toBeDisabled();
  return false;
}

async function passedLoad(request: Parameters<typeof api>[0], product = 'Sajaada heavy', bundles = 3) {
  const sk = await api(request, 'storemats'); const sm = await api(request, 'smmats'); const ceo = await api(request, 'ceo1');
  const order = await approvedOrder(sm, ceo, [[product, bundles]]);
  const codes = (await stockCodes(sk, 'mats', product)).slice(0, bundles);
  expect(codes.length, `free ${product} bundles to load`).toBe(bundles);
  const d = await loadViaApi(sk, order, codes, { weigh: 'expected' });
  return { sk, sm, ceo, order, d };
}

test.describe('deliveries and proof of delivery', () => {
  test('list renders the API, filters, search; sales and CEO see it read-only', async ({ page, request, w }) => {
    test.setTimeout(240_000);
    const { d } = await passedLoad(request);
    expect(d.gateStatus, 'gate result for an exact-weight load').toBe('pass');
    const sk = await api(request, 'storemats');
    const all = (await sk.json('/sites/mats/dispatches')).dispatches as { deliveryNoteNo: string; gateStatus: string; pod: unknown }[];
    await openAs(page, 'storemats', { role: 'Storekeeper' });
    await go(page, 'stock/deliveries');
    await expect(page.locator('table tbody tr')).toHaveCount(all.length);
    await expect(page.getByText(`${all.length} delivery notes`).first()).toBeVisible();
    await page.getByRole('tab', { name: /^Held/ }).click();
    await expect(page.locator('table tbody tr')).toHaveCount(all.filter((x) => x.gateStatus === 'hold').length);
    await page.getByRole('tab', { name: /^Delivered/ }).click();
    await expect(page.locator('table tbody tr')).toHaveCount(all.filter((x) => x.pod).length);
    await page.getByRole('tab', { name: /^All/ }).click();
    await page.getByPlaceholder('Delivery note, client, driver, receiver').fill(d.deliveryNoteNo);
    await expect(page.locator('table tbody tr')).toHaveCount(1);
    await page.getByPlaceholder('Delivery note, client, driver, receiver').fill('');
    await shoot(page, 'storemats-stock_deliveries');
    // the seeded overridden dispatch is labelled as overridden, not as a plain pass
    const overridden = all.find((x) => x.gateStatus === 'overridden');
    if (overridden) {
      const row = page.locator('table tbody tr').filter({ hasText: overridden.deliveryNoteNo });
      if (!/overrid/i.test(await row.innerText())) test.info().annotations.push({ type: 'defect', description: `Deliveries list shows ${overridden.deliveryNoteNo} (gate status overridden on the server) as a plain "Gate pass"` });
    }
    w.assertClean();
  });

  test('storekeeper records a short delivery (signature canvas + photo upload) -> discrepancy; invalid forms are blocked', async ({ page, request, w }) => {
    test.setTimeout(240_000);
    const { d, sk } = await passedLoad(request);
    await openAs(page, 'storemats', { role: 'Storekeeper' });
    await go(page, 'stock/deliveries');
    await page.locator('table tbody tr').filter({ hasText: d.deliveryNoteNo }).first().click();
    const dlg = page.getByRole('dialog');
    const confirm = dlg.getByRole('button', { name: 'Confirm delivery' });
    await expect(confirm).toBeDisabled();
    await dlg.getByPlaceholder('Who signed for it').fill('Store clerk');
    await dlg.locator('input[inputmode="numeric"]').fill('2'); // one short of 3
    await expect(dlg.getByText(/1 bundle short of the 3 that passed the gate/)).toBeVisible();
    await expect(confirm).toBeDisabled(); // no signature yet
    await dlg.locator('input[inputmode="numeric"]').fill('9');
    await expect(dlg.getByText(/6 more than the 3 that passed the gate/)).toBeVisible();
    await dlg.locator('input[inputmode="numeric"]').fill('2');
    // empty canvas cannot be saved
    await expect(dlg.getByRole('button', { name: 'Save signature' })).toBeDisabled();
    await draw(page, /./);
    if (!(await saveSignature(page, w))) { await shoot(page, 'storemats-stock_deliveries-pod-media-down'); w.assertClean(); return; }
    // photo: a file upload
    await dlg.locator('input[type="file"]').setInputFiles({ name: 'lorry.png', mimeType: 'image/png', buffer: PNG });
    await expect(dlg.getByRole('button', { name: /Photo saved/ })).toBeVisible();
    await shoot(page, 'storemats-stock_deliveries-pod-form');
    await expect(confirm).toBeEnabled();
    await confirm.click();
    await expect(dlg.getByText('1 bundle short')).toBeVisible();
    await expect(dlg.getByText(/An exception went to the CEO/)).toBeVisible();
    const after = ((await sk.json('/sites/mats/dispatches')).dispatches as { id: string; pod: { bundlesCounted: number; bundlesExpected: number; receiverName: string; hasSignature: boolean } }[]).find((x) => x.id === d.id)!;
    expect(after.pod).toMatchObject({ bundlesCounted: 2, bundlesExpected: 3, receiverName: 'Store clerk', hasSignature: true });
    // confirming twice is refused by the server (409)
    const again = await sk.post(`/sites/mats/dispatches/${d.id}/delivery`, { receivedBy: 'x', bundlesReceived: 3, signatureHash: 'x' });
    expect([409, 422]).toContain(again.status());
    await shoot(page, 'storemats-stock_deliveries-short');
    w.assertClean();
  });

  test('a full delivery is "Delivered in full"; CEO and sales have no confirm button', async ({ page, request, browser, w }) => {
    test.setTimeout(240_000);
    const { d } = await passedLoad(request);
    await openAs(page, 'smmats');
    await go(page, 'stock/deliveries');
    await page.locator('table tbody tr').filter({ hasText: d.deliveryNoteNo }).first().click();
    const dlg = page.getByRole('dialog');
    await dlg.getByPlaceholder('Who signed for it').fill('Receiving manager');
    await dlg.locator('input[inputmode="numeric"]').fill('3');
    await expect(dlg.getByText('Matches the 3 bundles that passed the gate.')).toBeVisible();
    await draw(page, /./);
    if (!(await saveSignature(page, w))) { await shoot(page, 'smmats-stock_deliveries-pod-media-down'); } else {
    await dlg.getByRole('button', { name: 'Confirm delivery' }).click();
    await expect(dlg.getByText('Delivered in full')).toBeVisible();
    await shoot(page, 'smmats-stock_deliveries-delivered'); }
    // read-only roles
    for (const [user, site] of [['ceo1', 'mats'], ['salesmats', 'mats']] as const) {
      const ctx = await browser.newContext(); const pg = await ctx.newPage();
      await openAs(pg, user, { site });
      await go(pg, 'stock/deliveries');
      if (user === 'salesmats') {
        // DEFECT (reported): the nav gives Sales a Deliveries screen but the server refuses sales the dispatch list (403 is skipped
        // by the app), so the screen claims "No deliveries yet" with 0 delivery notes while the site has many.
        await expect(pg.getByText('No deliveries yet.', { exact: false })).toBeVisible();
        test.info().annotations.push({ type: 'defect', description: 'Sales > Deliveries shows "0 delivery notes / No deliveries yet" (server denies sales the dispatch list)' });
      } else {
        await pg.locator('table tbody tr').filter({ hasText: d.deliveryNoteNo }).first().click();
        await expect(pg.getByRole('dialog')).toContainText('Gate');
        await expect(pg.getByRole('button', { name: 'Confirm delivery' })).toHaveCount(0);
      }
      await ctx.close();
    }
    w.assertClean();
  });
});
