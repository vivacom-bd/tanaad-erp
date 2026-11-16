/**
 * Who may change what in Settings. In a real build this mirrors the server (ConfigAuthorizer): a Group Admin changes everything, a Site
 * Manager changes the lists, products, machines, zones and rules of their own site, and nobody else (the CEO included) writes settings.
 * The exchange reference and the company profile are Group Admin only. The offline-only demo keeps its wider rules.
 */
export interface SettingsAccess { admin: boolean; canEdit: boolean; companyEdit: boolean; lockedSite: boolean }

export function settingsAccess(role: string, real: boolean): SettingsAccess {
  const groupAdmin = role === 'group_admin'; const viewsAllSites = groupAdmin || role === 'ceo'; const siteManager = role === 'site_manager';
  return {
    admin: real ? groupAdmin : viewsAllSites,
    canEdit: real ? groupAdmin || siteManager : viewsAllSites || siteManager,
    companyEdit: real ? groupAdmin : viewsAllSites || siteManager,
    lockedSite: !viewsAllSites,
  };
}
