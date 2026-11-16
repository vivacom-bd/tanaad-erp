// Real-mode production and labels: site config into the store, snapshot rows merged, and the server's machine wall,
// mass balance and output kept in the store for the selectors. Nothing here runs in demo mode.
import type { SiteCode } from '@tanaad/domain';
import { useStore, type AppState } from '../store';
import { api } from './http';
import { canRead } from './readAccess';
import { loadSession } from './auth';
import { mergeById } from './mapping';
import {
  benchmarkFromConfig, closedFromRow, dayKey, refFromConfig, wasteFromRow, labelFromRow, machineFromConfig, massFromServer, productFromConfig, productionEntryFromRow, wallKey, zoneFromConfig,
  type LabelHistory, type OutputRaw, type ServerViews, type WallRaw,
} from './productionMap';
import type { SiteConfigDoc } from './types';
import type { SiteOf } from './mapping';

type Rows = Record<string, unknown>[];
export const PRODUCTION_PROJECTIONS = ['production_entries', 'labels', 'waste_weighings', 'production_shifts_closed'] as const;
export type ProductionProj = typeof PRODUCTION_PROJECTIONS[number];

export const emptyViews = (): ServerViews => ({ wall: {}, mass: {}, output: {} });

const siteUuids = new Map<SiteCode, string>();
/** The server's id for a site (needed when a Settings list belongs to one site). */
export const siteUuidOf = (site: SiteCode): string | undefined => siteUuids.get(site);

/** Master data every screen must use: the server's own refs, rules, company details, machines, products, benchmarks and zones for the site. */
export function applyConfig(site: SiteCode, cfg: SiteConfigDoc) {
  const sid = (cfg.site as { id?: unknown } | undefined)?.id; if (typeof sid === 'string') siteUuids.set(site, sid);
  useStore.setState((st): Partial<AppState> => {
    const gone = new Set(st.machines.filter((m) => m.siteId === site).map((m) => m.id));
    const machines = (cfg.machines as Rows).map((m) => machineFromConfig(m, site));
    // Every option list is the server's own, replaced even when a list is empty, so no placeholder value survives. Group lists arrive with each site; the first wins.
    const refs = Array.isArray(cfg.refs) ? [...new Map((cfg.refs as Rows).map((r) => [String(r.id), refFromConfig(r, site)])).values()] : null;
    const fx = typeof cfg.fx === 'number' ? cfg.fx : cfg.fx && typeof (cfg.fx as { rate?: unknown }).rate === 'number' ? (cfg.fx as { rate: number }).rate : undefined;
    const settings = cfg.settings && typeof cfg.settings === 'object' ? { ...st.siteSettings[site], ...(cfg.settings as unknown as Partial<AppState['siteSettings'][SiteCode]>) } : undefined;
    const profile = cfg.companyProfile ? { ...st.companyProfiles[site], ...(cfg.companyProfile as unknown as Partial<AppState['companyProfiles'][SiteCode]>) } : undefined;
    return {
      ...(fx ? { fx } : {}),
      ...(settings ? { siteSettings: { ...st.siteSettings, [site]: settings } } : {}),
      ...(profile ? { companyProfiles: { ...st.companyProfiles, [site]: profile } } : {}),
      ...(refs ? { refs: [...st.refs.filter((r) => r.siteId !== site && r.siteId !== undefined), ...refs] } : {}),
      machines: [...st.machines.filter((m) => m.siteId !== site), ...machines],
      products: [...st.products.filter((p) => p.siteId !== site), ...(cfg.products as Rows).map((p) => productFromConfig(p, site))],
      benchmarks: [...st.benchmarks.filter((b) => !gone.has(b.machineId)), ...(cfg.benchmarks as Rows).map(benchmarkFromConfig)],
      zones: [...st.zones.filter((z) => z.siteId !== site), ...(cfg.zones as Rows).map((z) => zoneFromConfig(z, site))],
    };
  });
}

/** Merge pulled production or label rows. Server rows win; a re-logged entry keeps one row per shift, machine and stage. */
export function mergeProductionRows(proj: ProductionProj, rows: Rows, siteOf: SiteOf, serverIds: Set<string>) {
  const wrap = <T extends { id: string }>(f: (r: Record<string, unknown>, s: SiteOf) => T) =>
    rows.map((r) => { serverIds.add(String(r.id)); return { row: f(r, siteOf), deleted: !!r.deletedAt }; });
  if (proj === 'waste_weighings' || proj === 'production_shifts_closed') {
    useStore.setState((st): Partial<AppState> => {
      const v = st.serverViews ?? emptyViews();
      if (proj === 'waste_weighings') return { serverViews: { ...v, waste: mergeById(v.waste ?? [], rows.map((r) => ({ row: wasteFromRow(r, siteOf), deleted: !!r.deletedAt }))) } };
      return { serverViews: { ...v, closed: [...new Set([...(v.closed ?? []), ...rows.filter((r) => !r.deletedAt).map((r) => closedFromRow(r, siteOf))])] } };
    });
    return;
  }
  useStore.setState((st): Partial<AppState> => {
    if (proj === 'labels') return { labels: mergeById(st.labels, wrap(labelFromRow)) }; // same id as the optimistic label: the real code replaces the pending one
    const merged = mergeById(st.production, wrap(productionEntryFromRow));
    const key = (e: { shiftId: string; machineId: string; stageCode: string }) => `${e.shiftId}|${e.machineId}|${e.stageCode}`;
    const server = new Set(merged.filter((e) => serverIds.has(e.id)).map(key));
    return { production: merged.filter((e) => serverIds.has(e.id) || !server.has(key(e))) };
  });
}

const KINDS = ['day', 'night'] as const;

/** Fetch the server's answers for one site and business day into the store. Roles that may not read production are skipped. */
export async function refreshProductionDay(site: SiteCode, day: string, token: string): Promise<void> {
  const base = `/sites/${encodeURIComponent(site)}`; const q = `day=${encodeURIComponent(day)}`;
  const get = async <T>(path: string): Promise<T | null> => { try { return await api<T>(path, { token }); } catch (e) { if ((e as { code?: string }).code === 'network') throw e; return null; } };
  const [mass, output, ...walls] = await Promise.all([
    get<Record<string, unknown>>(`${base}/mass-balance?${q}`),
    get<{ byProduct: Rows; yieldByLine: Rows }>(`${base}/production/output?${q}`),
    ...KINDS.map((k) => get<WallRaw>(`${base}/machine-wall?${q}&shift=${k}`)),
  ]);
  useStore.setState((st): Partial<AppState> => {
    const v = st.serverViews ?? emptyViews();
    const wall = { ...v.wall }; walls.forEach((w, i) => { if (w) wall[wallKey(site, day, KINDS[i])] = w; });
    return { serverViews: { ...v, wall, mass: mass ? { ...v.mass, [dayKey(site, day)]: massFromServer(mass) } : v.mass, output: output ? { ...v.output, [dayKey(site, day)]: output as OutputRaw } : v.output } };
  });
}

/** Refresh today's views for every site the user can see. Called after each sync. */
export async function refreshProduction(sites: SiteCode[]): Promise<void> {
  const s = loadSession(); if (!s) return;
  const day = useStore.getState().today;
  for (const site of sites) if (canRead(s.user.roles, site, 'production_views')) await refreshProductionDay(site, day, s.token);
}

/** A screen showing another day asks for it once. */
export async function ensureProductionDay(site: SiteCode, day: string): Promise<void> {
  const s = loadSession(); if (!s) return;
  const v = useStore.getState().serverViews;
  if (!canRead(s.user.roles, site, 'production_views')) return;
  if (v && day === useStore.getState().today) return; // today is refreshed by the sync
  await refreshProductionDay(site, day, s.token);
}

export const fetchLabelHistory = (code: string): Promise<LabelHistory> => {
  const s = loadSession();
  return api<LabelHistory>(`/labels/${encodeURIComponent(code)}/history`, { token: s?.token });
};

export function resetProduction() {
  useStore.setState({ production: [], labels: [], serverViews: emptyViews() });
}
