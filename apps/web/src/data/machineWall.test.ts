import { describe, expect, it } from 'vitest';
import { machineWall, shiftId } from './selectors';
import { useStore } from './store';
import { buildSeed } from './seed/build';

describe('machineWall (local view)', () => {
  it('counts only entries of the wall stage: a raw-material issue to an extruder is not a shift entry', () => {
    const seed = buildSeed();
    const machine = seed.machines.find((m) => m.siteId === 'bags' && m.stageCode === 'extrude' && m.status !== 'down' && m.status !== 'arriving')!;
    const day = '2026-10-09'; const sh = shiftId('bags', day, 'day');
    const base = useStore.getState();
    const production = [{ id: 'p_raw', siteId: 'bags', stageCode: 'raw', machineId: machine.id, shiftId: sh, day, goodUnits: 0, rejectUnits: 0, inputKg: 100, outputKg: 0, downtimeMin: 0 } as never];
    const state = { ...base, machines: seed.machines, production, serverViews: undefined } as typeof base;
    const tile = machineWall(state, 'bags', day, 'day').find((t) => t.machine.id === machine.id)!;
    expect(tile.entry).toBeUndefined();
    expect(tile.state).toBe('not_logged');
  });
});

describe('machineWall (server view)', () => {
  it('a later local re-log wins over the server entry even when the server time carries a +03:00 offset (compare instants, not text)', () => {
    const seed = buildSeed();
    const machine = seed.machines.find((m) => m.siteId === 'mats' && m.stageCode === 'weave' && m.status !== 'down' && m.status !== 'arriving')!;
    const day = '2026-11-10'; const sh = shiftId('mats', day, 'day');
    const base = useStore.getState();
    const mk = (at: string, goodUnits: number) => ({ id: 'p1', siteId: 'mats', stageCode: 'weave', machineId: machine.id, shiftId: sh, day, goodUnits, rejectUnits: 0, inputKg: 0, outputKg: 0, downtimeMin: 0, at }) as never;
    const wall = { day, shift: 'day', shiftId: 's', machines: [{ machine: { id: machine.id, code: machine.code, status: 'running' }, entry: { id: 'e1', machineId: machine.id, stageCode: 'weave', goodUnits: 71, rejectUnits: 0, inputKg: 0, outputKg: 0, downtimeMin: 0, at: '2026-11-10T07:40:00+03:00' }, state: 'logged' }] };
    const state = { ...base, machines: seed.machines, production: [mk('2026-11-10T04:55:00.000Z', 61)], serverViews: { wall: { [`mats|${day}|day`]: wall }, mass: {}, output: {} } } as unknown as typeof base;
    expect(machineWall(state, 'mats', day, 'day').find((t) => t.machine.id === machine.id)!.entry!.goodUnits).toBe(61);
  });
});
