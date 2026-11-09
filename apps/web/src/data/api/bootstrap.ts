import { api } from './http';
import type { SiteConfigDoc } from './types';

export function bootstrapSite(siteCode: string, token: string): Promise<SiteConfigDoc> {
  // TODO(Phase 1): merge into the store fields siteSettings, refs, products, machines,
  // benchmarks, zones, companyProfiles and fx.
  return api<SiteConfigDoc>(`/sites/${encodeURIComponent(siteCode)}/config`, { token });
}
