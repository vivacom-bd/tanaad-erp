// Pure translation for Settings writes: store shapes out as the Config API's request bodies. No store import.
import type { Benchmark, CompanyProfile, Machine, Product, RefItem, SiteSettings } from '@tanaad/domain';
import { isUuid } from './names';
import { refServerId } from './productionMap';

const opt = <T extends Record<string, unknown>>(o: T) => Object.fromEntries(Object.entries(o).filter(([, v]) => v !== undefined)) as T;

/** POST /sites/{site}/refs. A group list sends siteId null; a site list sends the site's UUID. Ids that are not UUIDs are never sent. */
export function refBody(r: Pick<RefItem, 'list' | 'label'> & Partial<Pick<RefItem, 'id' | 'value' | 'unit' | 'color' | 'parentId' | 'active' | 'order' | 'example'>>, siteUuid: string | null): Record<string, unknown> {
  return opt({
    id: r.id && isUuid(refServerId(r.id)) ? refServerId(r.id) : undefined, list: r.list, siteId: siteUuid, label: r.label, value: r.value ?? null, unit: r.unit ?? null, color: r.color ?? null,
    parentId: r.parentId ?? null, active: r.active, order: r.order, example: r.example ?? false,
  });
}

/** PUT /sites/{site}/settings takes every rule at once; the changed ones are laid over the current values. */
export function settingsBody(current: SiteSettings, patch: Partial<SiteSettings>): Record<string, number> {
  return { ...current, ...patch } as unknown as Record<string, number>;
}

export function productBody(p: Omit<Product, 'id'> & { id?: string }): Record<string, unknown> {
  return opt({
    id: isUuid(p.id) ? p.id : undefined, internalName: p.internalName, commercialName: p.commercialName ?? null, kind: p.kind, bundlePieces: p.bundlePieces,
    stdBundleWeightKg: p.stdBundleWeightKg, weightTolerancePct: p.weightTolerancePct, weightStatus: p.weightStatus, colour: p.colour ?? null, unit: p.unit ?? null,
    thicknessMicron: p.thicknessMicron ?? null, active: p.active ?? true,
    prices: (p.prices ?? []).filter((x) => x.amount > 0).map((x) => ({ channel: x.channel, currency: x.currency, amount: x.amount, effectiveFrom: x.effectiveFrom })),
  });
}

export function machineBody(m: Omit<Machine, 'id'> & { id?: string }): Record<string, unknown> {
  return opt({
    id: isUuid(m.id) ? m.id : undefined, code: m.code, kind: m.kind, stageCode: m.stageCode, status: m.status, make: m.make ?? null, model: m.model ?? null,
    commissioned: m.commissioned ?? null, capacityKgPerDay: m.capacityKgPerDay ?? null,
  });
}

export const benchmarkBody = (b: Benchmark): Record<string, unknown> => ({ machineId: b.machineId, productId: b.productId, unitsPerShift: b.unitsPerShift });
export const zoneBody = (z: { id?: string; name: string }) => opt({ id: isUuid(z.id) ? z.id : undefined, name: z.name });
export const companyBody = (current: CompanyProfile, patch: Partial<CompanyProfile>): Record<string, unknown> => ({ ...current, ...patch });
export const fxBody = (day: string, rate: number): Record<string, unknown> => ({ day, base: 'USD', quote: 'UGX', rate });

/** Plain wording for a refused settings change. The server's own validation message is shown when it sent one. */
export function configErrorMessage(e: { status?: number; code?: string; detail?: string } | null | undefined): string {
  if (!e) return 'The change could not be saved.';
  if (e.code === 'network') return 'No connection. Settings can only be changed while online; try again when connected.';
  if (e.status === 403 || e.code === 'forbidden') return e.detail || 'Your role may not change this setting.';
  if (e.status === 409 || e.code === 'state_conflict') return e.detail || 'This conflicts with what is already saved. Refresh and try again.';
  return e.detail || 'The change could not be saved.';
}
