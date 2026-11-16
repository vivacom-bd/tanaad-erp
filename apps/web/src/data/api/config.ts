// Real-mode Settings writes (online only). Each call goes to the Config API, then the site's config is reloaded into the store so every screen
// uses the server's answer. Errors are thrown as Error with the server's plain message. Never used in demo mode.
import type { Benchmark, CompanyProfile, Machine, Product, RefItem, SiteCode, SiteSettings } from '@tanaad/domain';
import { useStore } from '../store';
import { ApiError, api } from './http';
import { loadSession } from './auth';
import { bootstrapSite } from './bootstrap';
import { applyConfig, siteUuidOf } from './production';
import { benchmarkBody, companyBody, configErrorMessage, fxBody, machineBody, productBody, refBody, settingsBody, zoneBody } from './configMap';

async function write(site: SiteCode, method: 'POST' | 'PUT', path: string, body: Record<string, unknown>) {
  const s = loadSession(); if (!s) throw new Error('Sign in again to change settings.');
  try { await api(path === '/fx' ? path : `/sites/${encodeURIComponent(site)}${path}`, { method, token: s.token, body }); }
  catch (e) { throw e instanceof ApiError ? new Error(configErrorMessage(e)) : e; }
}

/** Fetch the site's config again and put it in the store. */
export async function reloadConfig(site: SiteCode): Promise<void> {
  const s = loadSession(); if (!s) return;
  applyConfig(site, await bootstrapSite(site, s.token));
}
async function done(site: SiteCode) { await reloadConfig(site).catch(() => undefined); }

type RefIn = Pick<RefItem, 'list' | 'label'> & Partial<Omit<RefItem, 'list' | 'label'>>;
const scopeUuid = (r: { siteId?: string }, site: SiteCode) => (r.siteId ? siteUuidOf(site) ?? null : null);

export async function addRef(site: SiteCode, r: RefIn) {
  const order = useStore.getState().refs.filter((x) => x.list === r.list && x.siteId === r.siteId).length;
  await write(site, 'POST', '/refs', refBody({ ...r, order, active: true }, scopeUuid(r, site))); await done(site);
}
export async function updateRef(site: SiteCode, id: string, patch: Partial<RefItem>) {
  const cur = useStore.getState().refs.find((x) => x.id === id); if (!cur) return;
  await write(site, 'POST', '/refs', refBody({ ...cur, ...patch, id }, scopeUuid(cur, site))); await done(site);
}
/** Move an item one place. The whole run is renumbered so the move sticks even if two items shared an order. */
export async function moveRef(site: SiteCode, id: string, dir: -1 | 1) {
  const all = useStore.getState().refs; const it = all.find((x) => x.id === id); if (!it) return;
  const sibs = all.filter((x) => x.list === it.list && x.siteId === it.siteId && x.parentId === it.parentId).sort((a, b) => a.order - b.order);
  const i = sibs.findIndex((x) => x.id === id); if (!sibs[i + dir]) return;
  const ids = sibs.map((x) => x.id); ids.splice(i, 1); ids.splice(i + dir, 0, id);
  for (const [n, rid] of ids.entries()) { const x = sibs.find((y) => y.id === rid)!; if (x.order !== n) await write(site, 'POST', '/refs', refBody({ ...x, order: n }, scopeUuid(x, site))); }
  await done(site);
}
export async function updateSiteSettings(site: SiteCode, patch: Partial<SiteSettings>) {
  await write(site, 'PUT', '/settings', settingsBody(useStore.getState().siteSettings[site], patch)); await done(site);
}
export async function saveProduct(site: SiteCode, p: Omit<Product, 'id'> & { id?: string }) { await write(site, 'POST', '/products', productBody(p)); await done(site); }
export async function saveMachine(site: SiteCode, m: Omit<Machine, 'id'> & { id?: string }) { await write(site, 'POST', '/machines', machineBody(m)); await done(site); }
export async function setBenchmark(site: SiteCode, b: Benchmark) { await write(site, 'POST', '/benchmarks', benchmarkBody(b)); await done(site); }
export async function saveZone(site: SiteCode, z: { id?: string; name: string }) { await write(site, 'POST', '/zones', zoneBody(z)); await done(site); }
export async function updateCompanyProfile(site: SiteCode, patch: Partial<CompanyProfile>) {
  await write(site, 'POST', '/company-profile', companyBody(useStore.getState().companyProfiles[site], patch)); await done(site);
}
/** The exchange reference is group-wide (POST /fx); the opened site's config is reloaded to pick it up. */
export async function setFx(site: SiteCode, rate: number) { await write(site, 'POST', '/fx', fxBody(useStore.getState().today, rate)); await done(site); }
