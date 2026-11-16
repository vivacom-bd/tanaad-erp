import 'fake-indexeddb/auto';
import { describe, expect, it } from 'vitest';
import { refBody } from './configMap';
import type { DomainEvent, Label, Machine, ProductionEntry } from '@tanaad/domain';
import { SYSTEM_KINDS, toEnvelope } from './mapping';
import {
  batchPayload, closeShiftMessage, closeShiftPayload, closedFromRow, closedKey, emptyMass, refFromConfig, wasteFromRow, wastePayload, labelFromRow, machineFromConfig, massFromServer, productFromConfig, productionEntryFromRow, trailFromHistory, wallTilesFromServer, type WallRaw,
} from './productionMap';

const ev = (type: string, payload: unknown): DomainEvent =>
  ({ id: 'e1', siteId: 'mats', type, actorId: 'u', deviceId: 'd', occurredAt: '2026-10-10T08:00:00.000Z', payload, synced: false });
const site = () => 'mats' as const;
const MID = '0190a1b2-0000-7000-8000-000000000001', ZID = '0190a1b2-0000-7000-8000-000000000002';

describe('production envelopes', () => {
  const entry: ProductionEntry = { id: 'n1', siteId: 'mats', stageCode: 'weave', machineId: MID, shiftId: 'sh_mats_2026-10-10_day', day: '2026-10-10', goodUnits: 20, rejectUnits: 1, inputKg: 0, outputKg: 55.5, downtimeMin: 0, at: '' };
  it('records an entry with the shift split into day and kind, dropping empty fields', () => {
    const o = toEnvelope(ev('ProductionEntryRecorded', entry))!;
    expect(o.envelope.type).toBe('production.entry_recorded');
    expect(o.envelope.payload).toEqual({ entry_id: 'n1', day: '2026-10-10', kind: 'day', machine_id: MID, stage_code: 'weave', good_units: 20, reject_units: 1, input_kg: 0, output_kg: 55.5, downtime_min: 0 });
  });
  const label: Label = { id: 'l1', code: 'PENDING-ABC123', siteId: 'mats', kind: 'bundle', stageCode: 'finish', machineId: MID, shiftId: 'sh_mats_2026-10-10_night', day: '2026-10-10', qtyUnits: 10, qtyKg: 12, status: 'in_stock', zoneId: 'z_demo', parentIds: ['p1'] };
  it('creates a label without its provisional code, and leaves off a zone the server cannot know', () => {
    const o = toEnvelope(ev('LabelCreated', label))!;
    expect(o.envelope.type).toBe('labels.created');
    expect(o.envelope.payload).not.toHaveProperty('code');
    expect(o.envelope.payload).not.toHaveProperty('zone_id');
    expect(o.envelope.payload).toMatchObject({ label_id: 'l1', kind: 'bundle', shift_kind: 'night', day: '2026-10-10', qty_units: 10, qty_kg: 12, status: 'in_stock', parent_ids: ['p1'] });
    expect(toEnvelope(ev('LabelCreated', { ...label, zoneId: ZID }))!.envelope.payload).toMatchObject({ zone_id: ZID });
  });
  it('sends only created and in_stock as a starting status', () => {
    expect(toEnvelope(ev('LabelCreated', { ...label, status: 'scrapped' }))!.envelope.payload).not.toHaveProperty('status');
  });
  it('consumes a label by id', () => {
    expect(toEnvelope(ev('LabelConsumed', { labelId: 'l1' }))!.envelope).toMatchObject({ type: 'labels.consumed', payload: { label_id: 'l1' } });
  });
  it('treats the production exceptions as raised by the server', () => {
    for (const k of ['below_benchmark', 'not_logged', 'mass_gap'] as const) expect(SYSTEM_KINDS).toContain(k);
  });
});

describe('snapshot rows to store shapes', () => {
  it('maps an entry, rebuilding the store shift id from day and kind', () => {
    const e = productionEntryFromRow({ id: 'a', siteId: 'uuid-site', shiftId: 'server-shift', day: '2026-10-10', shiftKind: 'night', machineId: MID, stageCode: 'weave', goodUnits: 12, rejectUnits: '2', inputKg: '0.000', outputKg: '40.500', downtimeMin: 30, downtimeReason: 'Power', at: '2026-10-10 08:00:00' }, site);
    expect(e).toMatchObject({ id: 'a', shiftId: 'sh_mats_2026-10-10_night', goodUnits: 12, rejectUnits: 2, outputKg: 40.5, downtimeReason: 'Power', at: '2026-10-10T08:00:00Z' });
  });
  it('maps a label with the real code and numeric quantities', () => {
    const l = labelFromRow({ id: 'l1', siteId: 'x', code: 'MAT-BUN-000007', kind: 'bundle', stageCode: 'finish', shiftKind: 'day', day: '2026-10-10', qtyUnits: '10.000', qtyKg: '12.500', status: 'in_stock', parentIds: ['p'], zoneId: null }, site);
    expect(l).toMatchObject({ code: 'MAT-BUN-000007', shiftId: 'sh_mats_2026-10-10_day', qtyUnits: 10, qtyKg: 12.5, parentIds: ['p'] });
    expect(l.zoneId).toBeUndefined();
    expect(labelFromRow({ id: 'l2', siteId: 'x', code: 'C', kind: 'rack', stageCode: 's', day: '2026-10-10', status: 'created', parentIds: '["q"]' }, site).parentIds).toEqual(['q']);
  });
  it('maps site config rows with the server ids', () => {
    expect(machineFromConfig({ id: MID, code: 'Loom 1', kind: 'loom', stageCode: 'weave', status: 'active', capacityKgPerDay: null }, 'mats')).toMatchObject({ id: MID, code: 'Loom 1', siteId: 'mats', capacityKgPerDay: undefined });
    expect(productFromConfig({ id: ZID, internalName: 'Mat A', kind: 'mat', bundlePieces: 10, stdBundleWeightKg: '12.5', weightTolerancePct: '2', weightStatus: 'measured', prices: [{ channel: 'local', currency: 'UGX', amount: '5000', effectiveFrom: '2026-01-01' }], active: true }, 'mats'))
      .toMatchObject({ stdBundleWeightKg: 12.5, prices: [{ amount: 5000 }], active: true });
  });
});

describe('server views', () => {
  const machine: Machine = { id: MID, siteId: 'mats', code: 'Loom 1', kind: 'loom', stageCode: 'weave', status: 'active' };
  const raw: WallRaw = { day: '2026-10-10', shift: 'day', shiftId: 'srv', machines: [
    { machine: { id: MID, code: 'Loom 1', kind: 'loom', stageCode: 'weave', status: 'active' }, entry: { id: 'a', machineId: MID, stageCode: 'weave', goodUnits: 20, outputKg: '50', at: '2026-10-10 08:00:00' }, product: null, benchmark: 36, expectedSoFar: 36, progress: 1, operatorId: null, pct: 56, state: 'below' },
    { machine: { id: ZID, code: 'Loom 2', kind: 'loom', stageCode: 'weave', status: 'down' }, entry: null, product: null, benchmark: null, expectedSoFar: null, progress: 1, operatorId: null, pct: null, state: 'down' },
  ] };
  it("keeps the server's percent and state on each tile", () => {
    const t = wallTilesFromServer(raw, 'mats', '2026-10-10', 'day', [machine], [], []);
    expect(t[0]).toMatchObject({ pct: 56, state: 'below', benchmark: 36, expectedSoFar: 36 });
    expect(t[0].entry).toMatchObject({ shiftId: 'sh_mats_2026-10-10_day', goodUnits: 20, outputKg: 50 });
    expect(t[1]).toMatchObject({ state: 'down', entry: undefined, pct: undefined });
    expect(t[1].machine.code).toBe('Loom 2'); // a machine the store lacks still shows, from the server's own row
  });
  it('reads the mass balance exactly as the server sent it', () => {
    expect(massFromServer({ kgIn: 100, kgOut: 80, wasteKg: 5, wipDeltaKg: 3, allowedLossKg: 4, gapKg: 8, gapPct: 8, state: 'explainable' })).toEqual({ kgIn: 100, kgOut: 80, wasteKg: 5, wipDeltaKg: 3, allowedLossKg: 4, gapKg: 8, gapPct: 8, state: 'explainable' });
    expect(emptyMass.kgIn).toBe(0);
  });
  it('words a label history', () => {
    expect(trailFromHistory({ type: 'created', at: 'x', data: {} }).title).toBe('Created and printed');
    expect(trailFromHistory({ type: 'consumed', at: 'x', data: { consumed_by_stage: 'weave', consumed_ref: 'Loom 1' } }).detail).toBe('weave · Loom 1');
    expect(trailFromHistory({ type: 'zone_moved', at: 'x', data: {} }).title).toBe('Zone moved');
  });
});

describe('merging pulled rows into the store', () => {
  it('replaces the pending label code with the real one and keeps one entry per shift, machine and stage', async () => {
    const { useStore } = await import('../store');
    const { mergeProductionRows } = await import('./production');
    useStore.setState({
      labels: [{ id: 'l1', code: 'PENDING-ABC123', siteId: 'mats', kind: 'bundle', stageCode: 'finish', day: '2026-10-10', qtyUnits: 1, qtyKg: 1, status: 'in_stock', parentIds: [] }],
      production: [{ id: 'local', siteId: 'mats', stageCode: 'weave', machineId: MID, shiftId: 'sh_mats_2026-10-10_day', day: '2026-10-10', goodUnits: 1, rejectUnits: 0, inputKg: 0, outputKg: 0, downtimeMin: 0, at: '' }],
    });
    const ids = new Set<string>();
    mergeProductionRows('labels', [{ id: 'l1', siteId: 'x', code: 'MAT-BUN-000001', kind: 'bundle', stageCode: 'finish', day: '2026-10-10', qtyUnits: '1', qtyKg: '1', status: 'in_stock', parentIds: [] }], site, ids);
    mergeProductionRows('production_entries', [{ id: 'srv', siteId: 'x', day: '2026-10-10', shiftKind: 'day', machineId: MID, stageCode: 'weave', goodUnits: 5, rejectUnits: 0, inputKg: 0, outputKg: 0, downtimeMin: 0, at: '2026-10-10 09:00:00' }], site, ids);
    expect(useStore.getState().labels.map((l) => l.code)).toEqual(['MAT-BUN-000001']);
    expect(useStore.getState().production.map((e) => e.id)).toEqual(['srv']);
  });
});

describe('waste, batch and shift close', () => {
  const KIND = '0190a1b2-0000-7000-8000-000000000009';
  it('builds a waste weighing, dropping a machine the server does not know and a blank reason', () => {
    expect(wastePayload({ wasteId: 'w1', day: '2026-10-10', stageCode: 'weave', wasteKind: KIND, kg: 12.5, reason: '  ', machineId: 'm_demo' }))
      .toEqual({ waste_id: 'w1', day: '2026-10-10', stage_code: 'weave', waste_kind: KIND, kg: 12.5 });
    expect(wastePayload({ wasteId: 'w1', day: '2026-10-10', stageCode: 'weave', wasteKind: KIND, kg: 3, reason: 'Jam', machineId: MID })).toMatchObject({ machine_id: MID, reason: 'Jam' });
  });
  it('builds a shift close and plain refusal wording', () => {
    expect(closeShiftPayload('2026-10-10', 'day')).toEqual({ day: '2026-10-10', kind: 'day' });
    expect(closeShiftMessage(409)).toMatch(/already closed/);
    expect(closeShiftMessage(500, 'Boom')).toBe('Boom');
  });
  it('builds a batch with recipe lines and the output', () => {
    const p = batchPayload({ batchId: 'b1', day: '2026-10-10', kind: 'night', machineId: MID, lines: [{ material: 'Pellet', kg: 100 }, { material: 'Masterbatch', kg: 0 }], outputKg: 101, note: ' ' });
    expect(p).toEqual({ batch_id: 'b1', day: '2026-10-10', kind: 'night', machine_id: MID, lines: [{ material: 'Pellet', kg: 100 }], output_kg: 101 });
  });
  it('reads waste and closed-shift snapshot rows and server ref items', () => {
    expect(wasteFromRow({ id: 'w1', siteId: 'S', day: '2026-10-10', stageCode: 'weave', wasteKind: 'trim', kg: '4.250', reason: null, machineId: null }, site)).toEqual({ id: 'w1', siteId: 'mats', day: '2026-10-10', stageCode: 'weave', wasteKind: 'trim', kg: 4.25, reason: undefined, machineId: undefined });
    expect(closedFromRow({ id: 'sh', siteId: 'S', day: '2026-10-10 00:00:00', shiftKind: 'night' }, site)).toBe(closedKey('mats', '2026-10-10', 'night'));
    expect(refFromConfig({ id: KIND, list: 'waste_source', siteId: 'S', label: 'Edge trim', active: 1, order: 2 }, 'mats')).toMatchObject({ id: KIND, list: 'waste_source', siteId: 'mats', label: 'Edge trim', active: true, order: 2 });
  });
  it('keys a system expense category by its code and sends the server id back on a write', () => {
    const r = refFromConfig({ id: KIND, code: 'wages', list: 'expense_category', siteId: null, label: 'Wages', system: true, active: true, order: 0 }, 'mats');
    expect(r.id).toBe('wages');
    expect(refBody({ ...r, label: 'Pay' }, null)).toMatchObject({ id: KIND, list: 'expense_category', label: 'Pay' });
    // a non-system item keeps the server id
    expect(refFromConfig({ id: KIND, code: 'ref_unit_1', list: 'unit', label: 'kg' }, 'mats').id).toBe(KIND);
  });
});
