import { describe, expect, it } from 'vitest';
import {
  advanceEnvelope, approveEnvelope, canCancel, cancelEnvelope, createRunEnvelope, payoutEnvelope, queuedFrom, runFromApi, advanceFromApi, emptyQueued, money2,
} from './settlement';
import type { OutboxItem } from './outbox';

const runRow = { id: 'r1', siteId: 's', kind: 'weekly_casual', periodFrom: '2026-11-02', periodTo: '2026-11-07', currency: 'UGX', status: 'approved', createdBy: 'u1', approvedBy: 'u2', totalNet: '120000.50', totalAdvances: '5000.00', totalGross: '125000.50' };
const lineRow = { id: 'l1', settlementRunId: 'r1', personId: 'p1', periodFrom: '2026-11-02T00:00:00.000000Z', periodTo: '2026-11-07', rateBasis: 'day', rate: '20000.00', units: '6.00', overtimeHours: '1.50', gross: '120000.00', overtime: '3750.00', bonus: '0.00', advancesDeducted: '5000.00', net: '118750.00', status: 'unpaid', payoutId: null };

describe('settlement mapping', () => {
  it('turns decimal strings into numbers and trims dates', () => {
    const r = runFromApi({ run: runRow, lines: [lineRow], payouts: [{ id: 'po1', settlementRunId: 'r1', settlementLineId: 'l1', personId: 'p1', amount: '118750.00', method: 'cash', paidAt: '2026-11-07T10:00:00Z' }] }, 'mats');
    expect(r).toMatchObject({ id: 'r1', siteCode: 'mats', kind: 'weekly_casual', from: '2026-11-02', to: '2026-11-07', totalNet: 120000.5, status: 'approved' });
    expect(r.lines[0]).toMatchObject({ units: 6, overtimeHours: 1.5, advances: 5000, net: 118750, periodFrom: '2026-11-02', status: 'unpaid', payoutId: undefined });
    expect(r.payouts[0]).toMatchObject({ lineId: 'l1', amount: 118750, method: 'cash' });
  });
  it('maps advances', () => {
    expect(advanceFromApi({ id: 'a1', personId: 'p1', amount: '10000.00', deducted: '2500.00', balance: '7500.00', reason: 'School fees', givenOn: '2026-11-03', givenBy: 'u1' }, 'bags'))
      .toMatchObject({ siteCode: 'bags', amount: 10000, deducted: 2500, balance: 7500, reason: 'School fees' });
  });
  it('keeps money at 2dp', () => { expect(money2(0.1 + 0.2)).toBe(0.3); expect(money2(10.005)).toBe(10.01); });
});

describe('settlement envelopes', () => {
  it('builds the five commands with the types and payload keys the API expects', () => {
    const c = createRunEnvelope('monthly', '2026-11-01', '2026-11-09', 'run-1');
    expect(c).toMatchObject({ type: 'people.settlement_run_created', version: 1, payload: { settlement_run_id: 'run-1', kind: 'monthly', from: '2026-11-01', to: '2026-11-09' } });
    expect(approveEnvelope('r1')).toMatchObject({ type: 'people.settlement_approved', payload: { settlement_run_id: 'r1' } });
    expect(cancelEnvelope('r1', ' wrong week ')).toMatchObject({ type: 'people.settlement_cancelled', reason: 'wrong week', payload: { settlement_run_id: 'r1', reason: 'wrong week' } });
    expect(cancelEnvelope('r1').payload).toEqual({ settlement_run_id: 'r1' });
    const p = payoutEnvelope('r1', 'p1', 'mobile_money');
    expect(p).toMatchObject({ type: 'people.payout_recorded', payload: { settlement_run_id: 'r1', person_id: 'p1', method: 'mobile_money' } });
    expect(typeof p.payload.payout_id).toBe('string');
    expect(advanceEnvelope('p1', 12000.456, ' fees ', '2026-11-09')).toMatchObject({ type: 'people.advance_given', payload: { person_id: 'p1', amount: 12000.46, reason: 'fees', given_on: '2026-11-09' } });
  });
  it('gives each envelope its own id', () => { expect(approveEnvelope('r1').id).not.toBe(approveEnvelope('r1').id); });
});

describe('queued state and cancel rules', () => {
  const item = (env: ReturnType<typeof approveEnvelope>): OutboxItem => ({ siteCode: 'mats', envelope: env });
  it('folds unsent envelopes into pending markers', () => {
    const q = queuedFrom([item(createRunEnvelope('weekly_casual', 'a', 'b')), item(approveEnvelope('r1')), item(cancelEnvelope('r2')), item(payoutEnvelope('r1', 'p1', 'cash')), item(advanceEnvelope('p9', 500, 'x', '2026-11-09'))]);
    expect(q).toMatchObject({ creates: 1, approved: ['r1'], cancelled: ['r2'], payouts: ['r1|p1'] });
    expect(q.advances[0]).toMatchObject({ siteCode: 'mats', personId: 'p9', amount: 500, queued: true });
  });
  it('allows cancel only for an unpaid created/approved run with nothing waiting', () => {
    const base = runFromApi({ run: runRow, lines: [lineRow] }, 'mats');
    expect(canCancel(base, emptyQueued())).toBe(true);
    expect(canCancel({ ...base, status: 'created' }, emptyQueued())).toBe(true);
    expect(canCancel({ ...base, status: 'paid' }, emptyQueued())).toBe(false);
    expect(canCancel({ ...base, status: 'cancelled' }, emptyQueued())).toBe(false);
    expect(canCancel({ ...base, lines: [{ ...base.lines[0], status: 'paid' }] }, emptyQueued())).toBe(false);
    expect(canCancel(base, { ...emptyQueued(), payouts: ['r1|p1'] })).toBe(false);
    expect(canCancel(base, { ...emptyQueued(), cancelled: ['r1'] })).toBe(false);
  });
});
