import { describe, expect, it } from 'vitest';
import { canConfirmDelivery } from './pod';

describe('who may confirm a delivery', () => {
  it('lets the store, gate, site manager and driver confirm', () => { for (const r of ['storekeeper', 'gate', 'site_manager', 'driver']) expect(canConfirmDelivery(r), r).toBe(true); });
  it('keeps the CEO, sales, cashier and mechanic read-only (the server would answer 403)', () => { for (const r of ['ceo', 'sales', 'cashier', 'mechanic', 'supervisor', undefined]) expect(canConfirmDelivery(r), String(r)).toBe(false); });
});
