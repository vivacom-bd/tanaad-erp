// Pure translation for production and labels: store events out, snapshot/config/query rows in. No store import.
import type { Benchmark, Label, Machine, Person, Product, ProductionEntry, RefItem, ShiftKind, SiteCode, Zone } from '@tanaad/domain';
import { isUuid } from './names';

type Row = Record<string, unknown>;
import type { SiteOf } from './mapping';

const num = (v: unknown) => (v === null || v === undefined ? 0 : Number(v));
const optNum = (v: unknown) => (v === null || v === undefined ? undefined : Number(v));
const str = (v: unknown) => (v === null || v === undefined || v === '' ? undefined : String(v));
const iso = (v: unknown) => { const s = str(v); return s ? (s.includes('T') ? s : s.replace(' ', 'T').replace(/(\.\d{3})\d*$/, '$1') + 'Z') : ''; };
const list = (v: unknown): unknown[] => { if (Array.isArray(v)) return v; if (typeof v === 'string') { try { const j = JSON.parse(v); return Array.isArray(j) ? j : []; } catch { return []; } } return []; };
const opt = <T extends Record<string, unknown>>(o: T) => Object.fromEntries(Object.entries(o).filter(([, v]) => v !== undefined && v !== '')) as T;

/** The store's shift id for a site, day and shift kind (the server's shift ids are its own UUIDs). */
export const localShiftId = (site: string, day: string, kind: string) => `sh_${site}_${day}_${kind}`;
const dayOf = (v: unknown) => String(v ?? '').slice(0, 10);

/* ───────────── outgoing payloads ───────────── */

/** production.entry_recorded payload from a store entry. */
export function entryPayload(e: ProductionEntry, day?: string, kind?: string): Record<string, unknown> {
  return opt({
    entry_id: e.id, day: day ?? e.day, kind, machine_id: e.machineId, stage_code: e.stageCode, product_id: e.productId, operator_id: e.operatorId,
    good_units: e.goodUnits, reject_units: e.rejectUnits, input_kg: e.inputKg, output_kg: e.outputKg, downtime_min: e.downtimeMin, downtime_reason: e.downtimeReason,
  });
}

/** labels.created payload. The client's provisional code is never sent: the server allocates the real one. */
export function labelCreatedPayload(l: Label, kind?: string): Record<string, unknown> {
  return opt({
    label_id: l.id, kind: l.kind, product_id: l.productId, stage_code: l.stageCode, machine_id: l.machineId, operator_id: l.operatorId,
    shift_kind: kind, day: l.day, qty_units: l.qtyUnits, qty_kg: l.qtyKg,
    status: l.status === 'created' || l.status === 'in_stock' ? l.status : undefined,
    zone_id: isUuid(l.zoneId) ? l.zoneId : undefined, // a zone the server does not know is left off rather than invented
    parent_ids: l.parentIds?.length ? l.parentIds : undefined, colour: l.colour, grade: l.grade,
  });
}

/** production.waste_weighed payload. The machine is left off unless it is a server machine; the kind is the server ref item's id. */
export function wastePayload(w: { wasteId: string; day: string; stageCode: string; wasteKind: string; kg: number; reason?: string; machineId?: string }): Record<string, unknown> {
  return opt({ waste_id: w.wasteId, day: w.day, stage_code: w.stageCode, waste_kind: w.wasteKind, kg: w.kg, reason: w.reason?.trim() || undefined, machine_id: isUuid(w.machineId) ? w.machineId : undefined });
}

/** production.shift_closed payload (the close endpoint takes the same body). */
export const closeShiftPayload = (day: string, kind: string): Record<string, unknown> => ({ day, kind });

export interface BatchLine { material: string; kg: number }
/** production.batch_recorded payload: the recipe lines, the weighed output, and the mixer when the server knows it. */
export function batchPayload(b: { batchId: string; day: string; kind?: string; machineId?: string; lines: BatchLine[]; outputKg: number; labelCode?: string; note?: string }): Record<string, unknown> {
  return opt({
    batch_id: b.batchId, day: b.day, kind: b.kind, machine_id: isUuid(b.machineId) ? b.machineId : undefined,
    lines: b.lines.filter((l) => l.kg > 0).map((l) => ({ material: l.material, kg: l.kg })), output_kg: b.outputKg, note: b.note?.trim() || undefined,
  });
}

/** Server wording for a refused close or weighing; a 409 means the shift was already closed. */
export function closeShiftMessage(status: number, detail?: string): string {
  if (status === 409) return 'This shift is already closed. It cannot be closed twice.';
  if (status === 403) return 'Only a supervisor or site manager can close a shift.';
  return detail || 'The shift could not be closed.';
}

/* ───────────── incoming: snapshot rows ───────────── */

export interface WasteWeighing { id: string; siteId: SiteCode; day: string; stageCode: string; wasteKind: string; kg: number; reason?: string; machineId?: string }
export const wasteFromRow = (r: Row, site: SiteOf): WasteWeighing => ({
  id: String(r.id), siteId: site(r.siteId), day: dayOf(r.day), stageCode: String(r.stageCode ?? ''), wasteKind: String(r.wasteKind ?? ''), kg: num(r.kg), reason: str(r.reason), machineId: str(r.machineId),
});
/** Key of a closed shift in ServerViews.closed. */
export const closedKey = (site: string, day: string, kind: string) => `${site}|${day}|${kind}`;
export const closedFromRow = (r: Row, site: SiteOf): string => closedKey(site(r.siteId), dayOf(r.day), String(r.shiftKind ?? ''));

/** A server ref item (config document) in the store's shape. */
/** The seven enforced expense categories are known to the screens by their key ("wages"), not by the server's UUID; writes map the key back. */
const refUuidByKey = new Map<string, string>();
export const refServerId = (id: string) => refUuidByKey.get(id) ?? id;
const refKey = (r: Row) => {
  if (r.list === 'expense_category' && r.system && typeof r.code === 'string' && r.code) { refUuidByKey.set(r.code, String(r.id)); return r.code; }
  return String(r.id);
};

export const refFromConfig = (r: Row, site: SiteCode): RefItem => ({
  id: refKey(r), list: r.list as RefItem['list'], siteId: r.siteId ? site : undefined, label: String(r.label ?? ''), value: optNum(r.value), unit: str(r.unit), color: str(r.color),
  parentId: str(r.parentId), active: r.active === undefined ? true : !!r.active, order: num(r.order), system: !!r.system, example: !!r.example,
});

/* ───────────── incoming: snapshot rows ───────────── */

export function productionEntryFromRow(r: Row, site: SiteOf): ProductionEntry {
  const siteCode = site(r.siteId); const day = dayOf(r.day);
  return {
    id: String(r.id), siteId: siteCode, stageCode: String(r.stageCode ?? ''), machineId: String(r.machineId), shiftId: localShiftId(siteCode, day, String(r.shiftKind ?? 'day')),
    day, operatorId: str(r.operatorId), productId: str(r.productId), goodUnits: num(r.goodUnits), rejectUnits: num(r.rejectUnits),
    inputKg: num(r.inputKg), outputKg: num(r.outputKg), downtimeMin: num(r.downtimeMin), downtimeReason: str(r.downtimeReason), at: iso(r.at),
  };
}

export function labelFromRow(r: Row, site: SiteOf): Label {
  const siteCode = site(r.siteId); const day = dayOf(r.day); const kind = str(r.shiftKind);
  return {
    id: String(r.id), code: String(r.code ?? ''), siteId: siteCode, kind: r.kind as Label['kind'], productId: str(r.productId), stageCode: String(r.stageCode ?? ''),
    machineId: str(r.machineId), operatorId: str(r.operatorId), shiftId: kind ? localShiftId(siteCode, day, kind) : undefined, day,
    qtyUnits: num(r.qtyUnits), qtyKg: num(r.qtyKg), status: r.status as Label['status'], zoneId: str(r.zoneId),
    parentIds: list(r.parentIds).map(String), colour: str(r.colour), grade: str(r.grade),
  };
}

/* ───────────── incoming: site config (ids the production forms must use) ───────────── */

export const machineFromConfig = (r: Row, site: SiteCode): Machine => ({
  id: String(r.id), siteId: site, code: String(r.code ?? ''), kind: String(r.kind ?? ''), stageCode: String(r.stageCode ?? ''), status: r.status as Machine['status'],
  make: str(r.make), model: str(r.model), commissioned: str(r.commissioned), capacityKgPerDay: optNum(r.capacityKgPerDay),
});

export const productFromConfig = (r: Row, site: SiteCode): Product => ({
  id: String(r.id), siteId: site, internalName: String(r.internalName ?? ''), commercialName: str(r.commercialName), kind: r.kind as Product['kind'],
  bundlePieces: num(r.bundlePieces), stdBundleWeightKg: num(r.stdBundleWeightKg), weightTolerancePct: num(r.weightTolerancePct),
  weightStatus: (r.weightStatus as Product['weightStatus']) ?? 'to_be_measured',
  prices: list(r.prices).map((p) => { const x = p as Row; return { channel: x.channel as Product['prices'][number]['channel'], currency: x.currency as Product['prices'][number]['currency'], amount: num(x.amount), effectiveFrom: dayOf(x.effectiveFrom) }; }),
  colour: str(r.colour), unit: str(r.unit), thicknessMicron: optNum(r.thicknessMicron), active: r.active === undefined ? true : !!Number(r.active),
});

export const benchmarkFromConfig = (r: Row): Benchmark => ({ machineId: String(r.machineId), productId: String(r.productId), unitsPerShift: num(r.unitsPerShift) });
export const zoneFromConfig = (r: Row, site: SiteCode): Zone => ({ id: String(r.id), siteId: site, name: String(r.name ?? '') });

/* ───────────── incoming: query results (machine wall, mass balance, output) ───────────── */

export interface WallRaw { day: string; shift: string; shiftId: string; machines: Row[] }
export interface MassView { kgIn: number; kgOut: number; wasteKg: number; wipDeltaKg: number; allowedLossKg: number; gapKg: number; gapPct: number; state: 'balanced' | 'explainable' | 'missing' }
export interface OutputRaw { byProduct: Row[]; yieldByLine: Row[] }
/** Server answers kept in the store in real mode (undefined in demo mode: the selectors compute locally). */
export interface ServerViews { wall: Record<string, WallRaw>; mass: Record<string, MassView>; output: Record<string, OutputRaw>; waste?: WasteWeighing[]; closed?: string[] }

export const wallKey = (site: string, day: string, kind: string) => `${site}|${day}|${kind}`;
export const dayKey = (site: string, day: string) => `${site}|${day}`;
export const emptyMass: MassView = { kgIn: 0, kgOut: 0, wasteKg: 0, wipDeltaKg: 0, allowedLossKg: 0, gapKg: 0, gapPct: 0, state: 'balanced' };

export const massFromServer = (r: Row): MassView => ({
  kgIn: num(r.kgIn), kgOut: num(r.kgOut), wasteKg: num(r.wasteKg), wipDeltaKg: num(r.wipDeltaKg), allowedLossKg: num(r.allowedLossKg),
  gapKg: num(r.gapKg), gapPct: num(r.gapPct), state: (r.state as MassView['state']) ?? 'balanced',
});

export interface WallTile {
  machine: Machine; entry: ProductionEntry | undefined; product: Product | undefined; benchmark: number | undefined; expectedSoFar: number | undefined; progress: number; operator: Person | undefined; pct: number | undefined;
  state: 'arriving' | 'down' | 'not_logged' | 'below' | 'ok' | 'logged';
}

/** The server's wall rows in the tile shape the screens already use. Percentages and states are the server's own. */
export function wallTilesFromServer(w: WallRaw, site: SiteCode, day: string, kind: string, machines: Machine[], products: Product[], people: Person[]): WallTile[] {
  return w.machines.map((row) => {
    const m = row.machine as Row; const e = row.entry as Row | null;
    const machine = machines.find((x) => x.id === String(m.id)) ?? machineFromConfig(m, site);
    const entry: ProductionEntry | undefined = e ? {
      id: String(e.id), siteId: site, stageCode: String(e.stageCode ?? ''), machineId: String(e.machineId), shiftId: localShiftId(site, day, kind), day,
      operatorId: str(e.operatorId), productId: str(e.productId), goodUnits: num(e.goodUnits), rejectUnits: num(e.rejectUnits), inputKg: num(e.inputKg),
      outputKg: num(e.outputKg), downtimeMin: num(e.downtimeMin), downtimeReason: str(e.downtimeReason), at: iso(e.at),
    } : undefined;
    const productId = str((row.product as Row | null)?.id) ?? entry?.productId;
    const operatorId = str(row.operatorId);
    return {
      machine, entry, product: productId ? products.find((p) => p.id === productId) : undefined, benchmark: optNum(row.benchmark), expectedSoFar: optNum(row.expectedSoFar),
      progress: num(row.progress), operator: operatorId ? people.find((p) => p.id === operatorId) : undefined, pct: optNum(row.pct), state: row.state === 'logged' || row.state === 'ok' || row.state === 'below' || row.state === 'down' || row.state === 'arriving' ? row.state : 'not_logged',
    };
  });
}

/* ───────────── label history ───────────── */

export interface HistoryItem { type: string; at: string; by?: string; data: Record<string, unknown> }
export interface LabelHistory { label: Row; product: Row | null; machine: Row | null; operator: Row | null; parents: Row[]; history: HistoryItem[] }

/** One line of a label's trail, from the server's history. */
export function trailFromHistory(h: HistoryItem): { at: string; title: string; detail?: string; tone: 'neutral' | 'ok' | 'info' | 'bad' | 'warn' } {
  const d = h.data ?? {};
  switch (h.type) {
    case 'created': return { at: h.at, title: 'Created and printed', tone: 'neutral' };
    case 'consumed': return { at: h.at, title: 'Consumed by the next stage', detail: [str(d.consumed_by_stage), str(d.consumed_ref)].filter(Boolean).join(' · ') || undefined, tone: 'neutral' };
    default: return { at: h.at, title: h.type.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase()), tone: 'info' };
  }
}

export type { ShiftKind };
