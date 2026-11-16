import { describe, expect, it } from 'vitest';
import { breakdownFromRow, issueBody, issueFromRow, machineStatuses, partActive, partFromRow, problemText, receiveBody, reportBody, sparesMessage } from './sparesMap';

const site = () => 'mats' as const;
const PART = '0190a1b2-0000-7000-8000-0000000000a1', MID = '0190a1b2-0000-7000-8000-0000000000b1';

describe('spares rows to store shapes', () => {
  it('maps a part with numeric levels and the server code', () => {
    expect(partFromRow({ id: PART, siteId: 'x', code: 'BRG-01', name: 'Bearing', unit: 'pcs', minLevel: '4.000', unitCost: '12500.0000', onHand: '3.000', active: 1 }, site))
      .toEqual({ id: PART, siteId: 'mats', code: 'BRG-01', name: 'Bearing', unit: 'pcs', minLevel: 4, unitCost: 12500, onHand: 3 });
  });
  it('treats a switched-off part as inactive', () => {
    expect(partActive({ active: 0 })).toBe(false); expect(partActive({ active: false })).toBe(false); expect(partActive({ active: 1 })).toBe(true); expect(partActive({})).toBe(true);
  });
  it('maps an issue and a breakdown, normalising the server timestamps', () => {
    expect(issueFromRow({ id: 'i1', siteId: 'x', partId: PART, machineId: MID, mechanicId: null, qty: '2.000', at: '2026-10-10 08:00:00.123456' }, site)).toMatchObject({ qty: 2, mechanicId: '', at: '2026-10-10T08:00:00.123Z' });
    const open = breakdownFromRow({ id: 'b1', siteId: 'x', machineId: MID, cause: 'Belt snapped', startedAt: '2026-10-10 06:00:00', resolvedAt: null, sparesCost: '0.00' }, site);
    expect(open).toMatchObject({ cause: 'Belt snapped', startedAt: '2026-10-10T06:00:00Z', sparesCost: 0 }); expect(open.resolvedAt).toBeUndefined();
    expect(breakdownFromRow({ id: 'b2', siteId: 'x', machineId: MID, cause: 'c', startedAt: '2026-10-10 06:00:00', resolvedAt: '2026-10-10 07:30:00', sparesCost: '45000.00' }, site).resolvedAt).toBe('2026-10-10T07:30:00Z');
  });
});

describe('request bodies', () => {
  it('issues without a PIN, with the issue id for a safe retry', () => {
    const b = issueBody({ issueId: 'i1', qty: 2, machineId: MID, mechanicId: 'm1' });
    expect(b).toEqual({ issueId: 'i1', qty: 2, machineId: MID, mechanicId: 'm1' }); expect(b).not.toHaveProperty('pin');
    expect(issueBody({ issueId: 'i1', qty: 1, machineId: MID, mechanicId: 'm1', breakdownId: 'b1', reason: ' fix ' })).toMatchObject({ breakdownId: 'b1', reason: 'fix' });
  });
  it('receives and reports, leaving off empty extras', () => {
    expect(receiveBody({ receiptId: 'r1', qty: 5 })).toEqual({ receiptId: 'r1', qty: 5 });
    expect(receiveBody({ receiptId: 'r1', qty: 5, supplier: ' ACME ', unitCost: 100 })).toEqual({ receiptId: 'r1', qty: 5, supplier: 'ACME', unitCost: 100 });
    expect(reportBody({ id: 'b1', machineId: MID, cause: ' Jam ' })).toEqual({ id: 'b1', machineId: MID, cause: 'Jam' });
  });
});

describe('refusals in plain words', () => {
  it('shows the first field message of a 422, else the server sentence', () => {
    expect(problemText({ status: 422, code: 'invalid_payload', detail: 'The payload failed validation.', details: { errors: { qty: 'must be at least 1' } } })).toBe('must be at least 1');
    expect(problemText({ status: 409, code: 'state_conflict', detail: 'Only 3 pcs of Bearing on hand.' })).toBe('Only 3 pcs of Bearing on hand.');
  });
  it('explains offline, a role the server refused, and falls back', () => {
    expect(sparesMessage({ status: 0, code: 'network', detail: '' }, 'issue')).toMatch(/No connection/);
    expect(sparesMessage({ status: 403, code: 'forbidden', detail: 'Only the Storekeeper or Supervisor of this site may issue spares.' }, 'issue')).toMatch(/Storekeeper or Supervisor/);
    expect(sparesMessage({ status: 500, code: 'unknown', detail: '' }, 'receive')).toBe('The parts could not be booked in.');
  });
  it('marks a machine down while a breakdown is open, and back when all are resolved', () => {
    const m = [{ id: 'a', status: 'active' }, { id: 'b', status: 'down' }, { id: 'c', status: 'maintenance' }, { id: 'd', status: 'active' }];
    const r = machineStatuses([{ machineId: 'a', resolvedAt: undefined }, { machineId: 'b', resolvedAt: '2026-10-10T07:00:00Z' }, { machineId: 'c', resolvedAt: '2026-10-10T07:00:00Z' }], m);
    expect([...r]).toEqual([['a', 'down'], ['b', 'active']]);
  });
});
