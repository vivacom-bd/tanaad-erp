import 'fake-indexeddb/auto';
import { describe, expect, it } from 'vitest';
import { mayReadAlerts } from './alerts';

describe('who asks the server for alerts', () => {
  it('only the CEO, group admins and site managers (the server answers 403 to the rest)', () => {
    expect(mayReadAlerts([{ role: 'ceo' }])).toBe(true);
    expect(mayReadAlerts([{ role: 'group_admin' }])).toBe(true);
    expect(mayReadAlerts([{ role: 'site_manager' }])).toBe(true);
    for (const r of ['supervisor', 'cashier', 'storekeeper', 'gate', 'mechanic', 'sales', 'driver']) expect(mayReadAlerts([{ role: r }])).toBe(false);
  });
});
