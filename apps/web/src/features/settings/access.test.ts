import { describe, expect, it } from 'vitest';
import { settingsAccess } from './access';

describe('settings access follows the server in a real build', () => {
  it('the CEO reads every site but writes nothing', () => {
    expect(settingsAccess('ceo', true)).toEqual({ admin: false, canEdit: false, companyEdit: false, lockedSite: false });
  });
  it('a site manager edits their site, but not the exchange reference or company details', () => {
    expect(settingsAccess('site_manager', true)).toEqual({ admin: false, canEdit: true, companyEdit: false, lockedSite: true });
  });
  it('a group admin edits everything on every site', () => {
    expect(settingsAccess('group_admin', true)).toEqual({ admin: true, canEdit: true, companyEdit: true, lockedSite: false });
  });
  it('the demo keeps its wider rules', () => {
    expect(settingsAccess('ceo', false).canEdit).toBe(true);
    expect(settingsAccess('site_manager', false).companyEdit).toBe(true);
  });
});
