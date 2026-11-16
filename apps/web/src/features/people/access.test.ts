import { describe, expect, it } from 'vitest';
import { peopleAccess } from './access';

describe('peopleAccess', () => {
  it('offers everything in demo mode', () => {
    expect(Object.values(peopleAccess('cashier', false)).every(Boolean)).toBe(true);
    expect(Object.values(peopleAccess(undefined, false)).every(Boolean)).toBe(true);
  });
  it('follows the server matrix in real mode', () => {
    expect(peopleAccess('supervisor', true)).toEqual({ mark: true, renew: false, end: false, archive: false, createRun: false, approveRun: false, pay: false });
    expect(peopleAccess('cashier', true)).toEqual({ mark: false, renew: false, end: false, archive: false, createRun: false, approveRun: false, pay: true });
    expect(peopleAccess('ceo', true)).toEqual({ mark: false, renew: false, end: false, archive: true, createRun: false, approveRun: true, pay: false });
    expect(Object.values(peopleAccess('site_manager', true)).every(Boolean)).toBe(true);
    expect(Object.values(peopleAccess('group_admin', true)).every(Boolean)).toBe(true);
    expect(Object.values(peopleAccess(undefined, true)).some(Boolean)).toBe(false);
  });
});
