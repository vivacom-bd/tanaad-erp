import { describe, expect, it } from 'vitest';
import type { DomainEvent } from '@tanaad/domain';
import { toEnvelope } from './mapping';
import { createRunEnvelope, lineSettled, payoutEnvelope, type SettlementLineRow } from './settlement';
import { briefBody } from './briefs';
import { isUuid, userLabel } from './names';
import { signInMessage } from './sync';
import { ApiError } from './http';

const ev = (type: string, payload: unknown, reason?: string): DomainEvent =>
  ({ id: 'e1', siteId: 'mats', type, actorId: 'u', deviceId: 'd', occurredAt: '2026-11-09T08:00:00.000Z', payload, reason, synced: false });

describe('new command envelopes', () => {
  it('ends an engagement with its reason', () => {
    const o = toEnvelope(ev('EngagementEnded', { engagementId: 'g1', personId: 'p1', endDate: '2026-11-09' }, 'Left the site'))!;
    expect(o.envelope.type).toBe('people.engagement_ended');
    expect(o.envelope.payload).toEqual({ engagement_id: 'g1', end_date: '2026-11-09', reason: 'Left the site' });
    expect(o.envelope.reason).toBe('Left the site');
  });
  it('corrects a cash entry, pointing at the original', () => {
    const o = toEnvelope(ev('CashEntryCorrected', { id: 'n1', correctsEntryId: 'o1', day: '2026-11-08', direction: 'out', category: 'fuel', amount: 5000, currency: 'UGX', hasReceiptPhoto: false, note: '' }, 'Typo'))!;
    expect(o.envelope.type).toBe('cash.entry_corrected');
    expect(o.envelope.payload).toMatchObject({ entry_id: 'n1', corrects_entry_id: 'o1', day: '2026-11-08', amount: 5000, reason: 'Typo' });
    expect(o.envelope.payload).not.toHaveProperty('note');
  });
  it('raises an exception by hand and sets the opening balance', () => {
    const r = toEnvelope(ev('ExceptionRaisedManual', { id: 'x1', kind: 'low_stock', severity: 'warning', title: 'T', detail: 'D', ownerRole: 'site_manager' }))!;
    expect(r.envelope.type).toBe('group.exception_raised');
    expect(r.envelope.payload).toEqual({ exception_id: 'x1', kind: 'low_stock', severity: 'warning', title: 'T', detail: 'D', owner_role: 'site_manager' });
    const o = toEnvelope(ev('CashOpeningSet', { day: '2026-11-09', amount: 20000 }))!;
    expect(o.envelope).toMatchObject({ type: 'cash.opening_set', payload: { day: '2026-11-09', amount: 20000 } });
  });
  it('sends the attendance correction reason', () => {
    const a = { id: 'a1', shiftId: 'sh_mats_2026-11-09_day', personId: 'p1', workedSiteId: 'mats', status: 'half', overtimeHours: 0, markedBy: 'u', at: '' };
    expect(toEnvelope(ev('AttendanceCorrected', a, 'Wrong row'))!.envelope.reason).toBe('Wrong row');
  });
});

describe('settlement commands', () => {
  it('adds bonuses only when positive', () => {
    expect(createRunEnvelope('weekly_casual', '2026-11-02', '2026-11-07', 'r1').payload).not.toHaveProperty('bonuses');
    const e = createRunEnvelope('weekly_casual', '2026-11-02', '2026-11-07', 'r1', [{ personId: 'p1', amount: 5000 }, { personId: 'p2', amount: 0 }]);
    expect(e.payload.bonuses).toEqual([{ person_id: 'p1', amount: 5000 }]);
  });
  it('sends the payout reference only when filled', () => {
    expect(payoutEnvelope('r', 'p', 'mobile_money').payload).not.toHaveProperty('reference');
    expect(payoutEnvelope('r', 'p', 'mobile_money', '  ').payload).not.toHaveProperty('reference');
    expect(payoutEnvelope('r', 'p', 'mobile_money', ' TX123 ').payload.reference).toBe('TX123');
  });
  it('treats a net-0 unpaid line as settled', () => {
    const l = { status: 'unpaid', net: 0 } as SettlementLineRow;
    expect(lineSettled(l)).toBe(true);
    expect(lineSettled({ ...l, net: 100 })).toBe(false);
  });
});

describe('briefs, names, sign-in', () => {
  it('builds the camelCase brief body', () => {
    expect(briefBody('2026-11-09', 'copy', ['a', ''], [], 'b1')).toEqual({ briefId: 'b1', day: '2026-11-09', channel: 'copy', lines: ['a', ''] });
  });
  it('never shows a UUID as a name', () => {
    const id = '0190a1b2-0000-7000-8000-000000000001';
    expect(isUuid(id)).toBe(true);
    expect(userLabel(id, [])).toBe('A colleague');
    expect(userLabel(id, [], 'Moses')).toBe('Moses');
    expect(userLabel(id, [{ id, name: 'Grace' }])).toBe('Grace');
    expect(userLabel(id, [], undefined, [{ id, fullName: 'Ann' }])).toBe('Ann');
    expect(userLabel('u_sm_mats', [])).toBe('u_sm_mats');
  });
  it('words throttling and network failures', () => {
    expect(signInMessage(new ApiError(429, 'unknown', 'x'))).toMatch(/Too many attempts/);
    expect(signInMessage(new ApiError(403, 'forbidden', 'Username or PIN is wrong.'))).toBe('Username or PIN is wrong.');
    expect(signInMessage(new ApiError(0, 'network', 'x'))).toBe('Cannot reach the server.');
  });
});

import { actionName, eventName, setDirectory } from './names';
describe('plain wording', () => {
  it('names actions and events', () => {
    expect(actionName('cash.entry_recorded')).toBe('Record a cash entry');
    expect(eventName('Cash.day_reopened')).toBe('Cash day reopened');
    expect(eventName('CashDayReopened')).toBe('Cash day reopened');
  });
  it('uses the server directory before falling back to a colleague', () => {
    const id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';
    expect(userLabel(id, [])).toBe('A colleague');
    setDirectory([{ id, name: 'Grace N.' }]);
    expect(userLabel(id, [])).toBe('Grace N.');
  });
});
