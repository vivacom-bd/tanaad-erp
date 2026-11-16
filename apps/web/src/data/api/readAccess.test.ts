import { describe, expect, it } from 'vitest';
import type { RoleGrant } from './types';
import { canRead } from './readAccess';

const at = (role: string, site: string | null): RoleGrant => ({ role, site_id: null, site_code: site } as RoleGrant);

describe('who may read what', () => {
  it('lets group roles read everything', () => {
    for (const w of ['purchases', 'clients', 'production_views', 'dispatches', 'stock_views', 'labels']) expect(canRead([at('ceo', null)], 'bags', w)).toBe(true);
    expect(canRead([at('group_admin', null)], 'mats', 'parts')).toBe(true);
  });
  it('keeps a supervisor out of money, spares stock, clients (the 403 spam)', () => {
    const sup = [at('supervisor', 'mats')];
    for (const w of ['purchases', 'parts', 'part_issues', 'clients', 'orders', 'invoices', 'payments']) expect(canRead(sup, 'mats', w), w).toBe(false);
    for (const w of ['people', 'cash_days', 'exceptions', 'production_entries', 'production_views', 'stock_on_hand', 'stock_views', 'labels', 'breakdowns', 'dispatches']) expect(canRead(sup, 'mats', w), w).toBe(true);
  });
  it('gives a cashier the money views only', () => {
    const c = [at('cashier', 'mats')];
    for (const w of ['purchases', 'clients', 'orders', 'invoices', 'payments', 'cash_entries', 'people']) expect(canRead(c, 'mats', w), w).toBe(true);
    for (const w of ['stock_on_hand', 'stock_views', 'production_views', 'labels', 'dispatches', 'parts', 'waste_weighings']) expect(canRead(c, 'mats', w), w).toBe(false);
  });
  it('uses the role held at that site, not at another', () => {
    const r = [at('storekeeper', 'mats'), at('cashier', 'bags')];
    expect(canRead(r, 'mats', 'stock_views')).toBe(true); expect(canRead(r, 'bags', 'stock_views')).toBe(false);
    expect(canRead(r, 'recycling', 'people')).toBe(false);
  });
  it('keeps the collector ledger (money owed) from the mechanic, gate and storekeeper', () => {
    for (const r of ['mechanic', 'gate', 'storekeeper'] as const) expect(canRead([at(r, 'recycling')], 'recycling', 'collector_ledger'), r).toBe(false);
    for (const r of ['supervisor', 'site_manager', 'cashier'] as const) expect(canRead([at(r, 'recycling')], 'recycling', 'collector_ledger'), r).toBe(true);
  });
  it('asks the server about a view it does not know', () => { expect(canRead([at('gate', 'mats')], 'mats', 'something_new')).toBe(true); });
});
