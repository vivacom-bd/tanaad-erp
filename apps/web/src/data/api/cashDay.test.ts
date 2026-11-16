import { describe, expect, it } from 'vitest';
import { ApiError } from './http';
import { reopenApprovalMessage, siteManagers } from './cashDay';

describe('reopen approval', () => {
  it('maps refusals to plain words', () => {
    expect(reopenApprovalMessage(new ApiError(403, 'forbidden', 'Manager approval failed.', { code: 'approval_failed' }))).toBe('Manager approval failed. Check the manager and PIN.');
    expect(reopenApprovalMessage(new ApiError(429, 'unknown', 'Too many attempts'))).toMatch(/locked/);
    expect(reopenApprovalMessage(new ApiError(0, 'network', 'fetch failed'))).toBe('Reopening needs a connection.');
    expect(reopenApprovalMessage(new ApiError(422, 'unknown', 'Give a reason.'))).toBe('Give a reason.');
  });
  it('lists only the active managers of the site', () => {
    const rows = [
      { id: 'a', name: 'Zed', active: true, roles: [{ role: 'site_manager', siteCode: 'mats' }] },
      { id: 'b', name: 'Amy', active: true, roles: [{ role: 'site_manager', siteCode: 'mats' }] },
      { id: 'c', name: 'Cat', active: true, roles: [{ role: 'cashier', siteCode: 'mats' }] },
      { id: 'd', name: 'Dan', active: true, roles: [{ role: 'site_manager', siteCode: 'bags' }] },
      { id: 'e', name: 'Eve', active: false, roles: [{ role: 'site_manager', siteCode: 'mats' }] },
    ];
    expect(siteManagers(rows, 'mats').map((m) => m.name)).toEqual(['Amy', 'Zed']);
  });
});
