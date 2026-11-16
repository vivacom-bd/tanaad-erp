// One place where a Settings change happens. Demo mode edits the local store as before; real mode sends it to the Config API (online only),
// reloads the site's config, and shows the server's own message when it refuses.
import type { Benchmark, CompanyProfile, Machine, Product, RefItem, SiteCode, SiteSettings } from '@tanaad/domain';
import { useStore } from '@/data/store';
import { DEMO } from '@/data/api/env';
import * as cfg from '@/data/api/config';

type Ok = { title: string; detail?: string } | undefined;

/** Resolves true when the change was applied. A refusal shows a toast and resolves false. */
async function run(demo: () => void, real: () => Promise<void>, ok: Ok): Promise<boolean> {
  const { toast } = useStore.getState();
  try { if (DEMO) demo(); else await real(); } catch (e) { toast({ kind: 'error', title: 'Not saved', detail: e instanceof Error ? e.message : 'The change could not be saved.' }); return false; }
  if (ok) toast({ kind: 'ok', ...ok });
  return true;
}
const st = () => useStore.getState();

export const change = {
  addRef: (site: SiteCode, r: Omit<RefItem, 'id' | 'order' | 'active'>, ok: Ok) => run(() => void st().addRef(r), () => cfg.addRef(site, r), ok),
  updateRef: (site: SiteCode, id: string, patch: Partial<Omit<RefItem, 'id' | 'list'>>, ok?: Ok) => run(() => st().updateRef(id, patch), () => cfg.updateRef(site, id, patch), ok),
  moveRef: (site: SiteCode, id: string, dir: -1 | 1) => run(() => st().moveRef(id, dir), () => cfg.moveRef(site, id, dir), undefined),
  siteSettings: (site: SiteCode, patch: Partial<SiteSettings>, ok: Ok) => run(() => st().updateSiteSettings(site, patch), () => cfg.updateSiteSettings(site, patch), ok),
  product: (site: SiteCode, p: Omit<Product, 'id'> & { id?: string }, ok: Ok) => run(() => void st().saveProduct(p), () => cfg.saveProduct(site, p), ok),
  machine: (site: SiteCode, m: Omit<Machine, 'id'> & { id?: string }, ok: Ok) => run(() => void st().saveMachine(m), () => cfg.saveMachine(site, m), ok),
  benchmark: (site: SiteCode, b: Benchmark, ok?: Ok) => run(() => st().setBenchmark(b), () => cfg.setBenchmark(site, b), ok),
  zone: (site: SiteCode, z: { id?: string; name: string }, ok: Ok) => run(() => st().saveZone({ ...z, siteId: site }), () => cfg.saveZone(site, z), ok),
  fx: (site: SiteCode, rate: number, ok: Ok) => run(() => st().setFx(rate), () => cfg.setFx(site, rate), ok),
  company: (site: SiteCode, patch: Partial<CompanyProfile>, ok: Ok) => run(() => st().updateCompanyProfile(site, patch), () => cfg.updateCompanyProfile(site, patch), ok),
};
