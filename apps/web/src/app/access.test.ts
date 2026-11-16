import { describe, expect, it } from 'vitest';
import { canOpenPath } from './access';

describe('route guard', () => {
  it('blocks pages outside the role', () => {
    expect(canOpenPath('/s/mats/production/mixer', { role: 'cashier', siteId: 'mats' })).toBe(false);
    expect(canOpenPath('/s/mats/settings', { role: 'sales', siteId: 'mats' })).toBe(false);
    expect(canOpenPath('/s/mats/reports', { role: 'supervisor', siteId: 'mats' })).toBe(false);
    expect(canOpenPath('/g/cockpit', { role: 'site_manager', siteId: 'mats' })).toBe(false);
  });
  it('allows own pages and group roles', () => {
    expect(canOpenPath('/s/mats/cash', { role: 'cashier', siteId: 'mats' })).toBe(true);
    expect(canOpenPath('/s/mats/today', { role: 'driver', siteId: 'mats' })).toBe(true);
    expect(canOpenPath('/g/audit', { role: 'ceo' })).toBe(true);
    expect(canOpenPath('/s/bags/cash', { role: 'ceo' })).toBe(true);
  });
  it('blocks another site and site-specific pages', () => {
    expect(canOpenPath('/s/bags/cash', { role: 'cashier', siteId: 'mats' })).toBe(false);
    expect(canOpenPath('/s/bags/production/mixer', { role: 'supervisor', siteId: 'bags' })).toBe(false);
  });
});
