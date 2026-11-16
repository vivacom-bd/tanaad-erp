import { expect, test } from '../../support/helpers';
import { enter, spaGo } from '../../support/nav';
import { apiAs, shot } from '../../support/cashgroup';

test.use({ actionTimeout: 20_000 });
test.describe.configure({ mode: 'serial', timeout: 180_000 });

type Order = { id: string; clientId?: string; number: string; clientName: string | null; total: number; status: string; overLimit: boolean; holdReason?: string | null };
const waiting = async (request: import('@playwright/test').APIRequestContext): Promise<Order[]> => (await (await apiAs(request, 'admin')).json('/group/approvals')).data.orders;

test('approvals page lists the orders waiting with client names; a hold needs a note, an approval goes through', async ({ page, request, w }) => {
  // The seed holds no within-limit order that is still waiting: sales raises a small export order for a client that has one on record.
  const clientId = (await waiting(request)).find((o) => !o.overLimit)?.clientId;
  expect(clientId, 'a within-limit export order in the seed to borrow its client').toBeTruthy();
  const sales = await apiAs(request, 'salesmats');
  const products = (await sales.json('/sites/mats/config')).products as { id: string }[];
  let made: Order | undefined;
  for (const p of products) {
    const r = await sales.post('/sites/mats/orders', { clientId, lines: [{ product_id: p.id, bundles: 1 }] });
    if (r.status() === 201) { made = (await r.json()).order; break; }
  }
  expect(made, 'an export order created by sales').toBeTruthy();
  const o7 = (await waiting(request)).find((o) => o.id === made!.id)!;
  expect(o7.status).toBe('awaiting_approval');
  await enter(page, 'ceo1', '/g/approvals');
  const panel = page.locator('div').filter({ hasText: /^Orders waiting for approval/ }).first();
  await expect(page.getByText(o7.clientName!).first()).toBeVisible();
  await expect(page.getByText('Client not shown')).toHaveCount(0);
  await shot(page, 'approvals-orders-waiting');
  const row = page.locator('div.px-\\[var\\(--pad\\)\\]').filter({ hasText: o7.number }).first();
  await row.getByRole('button', { name: 'Hold' }).click();
  await row.getByRole('button', { name: 'Confirm hold' }).click();
  await expect(row.getByRole('alert')).toHaveText('Add a short note. A hold needs a reason.');
  expect((await waiting(request)).find((o) => o.number === o7.number)!.status).toBe('awaiting_approval');
  await row.getByRole('button', { name: 'Cancel' }).click();
  // within its limit: approve with no reason
  await row.getByRole('button', { name: 'Approve' }).click();
  await row.getByRole('button', { name: 'Confirm approve' }).click();
  await expect(page.getByText(`${o7.number} approved`)).toBeVisible();
  await expect.poll(async () => (await waiting(request)).find((o) => o.number === o7.number)?.status ?? 'gone', { timeout: 20_000 }).not.toBe('awaiting_approval');
  void panel; w.assertClean();
});

test('credit desk: queue, credit picture and receivables render from the server', async ({ page, request, w }) => {
  await enter(page, 'ceo1', '/g/credit');
  await expect(page.getByRole('heading', { name: 'Credit desk' })).toBeVisible();
  const left = (await waiting(request)).filter((o) => o.status === 'awaiting_approval').length;
  await expect(page.getByText(`${left} awaiting approval`)).toBeVisible();
  await expect(page.getByText('Credit picture')).toBeVisible();
  await expect(page.getByText('Export receivables')).toBeVisible();
  await shot(page, 'credit-desk');
  w.assertClean();
});

test('credit desk: holding an over-limit order needs a note and lands on the server', async ({ page, request, w }) => {
  w.allow(/409|422/);
  await enter(page, 'ceo1', '/g/credit');
  const o8 = (await waiting(request)).find((o) => o.status === 'awaiting_approval' && o.overLimit);
  test.skip(!o8, 'no over-limit order is waiting (already decided on this database)');
  const n8 = o8!.number;
  await page.getByRole('button', { name: new RegExp(n8) }).first().click();
  await expect(page.getByText('Credit picture')).toBeVisible();
  await page.getByRole('button', { name: 'Hold', exact: true }).click();
  await expect(page.getByRole('button', { name: new RegExp(`^Put ${n8} on hold`) })).toBeDisabled(); // a hold needs a note
  await page.getByLabel('Reason').fill('Over the limit: collect payment first');
  await page.getByRole('button', { name: new RegExp(`^Put ${n8} on hold`) }).click();
  await expect(page.getByText(`${n8} on hold`)).toBeVisible();
  await expect.poll(async () => (await waiting(request)).find((o) => o.number === n8)?.status ?? 'gone', { timeout: 20_000 }).not.toBe('awaiting_approval');
  await shot(page, 'credit-desk-after-hold');
  w.assertClean();
});
