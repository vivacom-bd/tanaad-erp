import { describe, expect, it } from 'vitest';
import type { CollectorDelivery, DomainEvent } from '@tanaad/domain';
import { intakeNet } from '@tanaad/domain';
import { ApiError } from './http';
import { toEnvelope } from './mapping';
import { deliveryFromRow, intakePayload, isConfirmed, ledgerTotals, payErrorMessage, provisionalVoucher, voucherLabel } from './recycling';
import { voucherNo } from '../../features/recycling/kit';

const d = (o: Partial<CollectorDelivery> = {}): CollectorDelivery => ({
  id: 'd1', siteId: 'recycling', collector: 'Moses', grossKg: 1000, tareKg: 50, deductionPct: 8, netKg: 874, pricePerKg: 1200, currency: 'UGX', paid: false,
  day: '2026-11-09', at: '2026-11-09T08:00:00.000Z', hasScalePhoto: true, ...o,
});
const ev = (p: CollectorDelivery): DomainEvent => ({ id: 'e1', siteId: 'recycling', type: 'CollectorDeliveryWeighed', actorId: 'u', deviceId: 'dev', occurredAt: p.at, payload: p, synced: false });

describe('collector intake envelope', () => {
  it('sends inputs only, never net kg or amount', () => {
    const o = toEnvelope(ev(d({ provisionalNo: 'PV-261109-AB12' })))!;
    expect(o.siteCode).toBe('recycling');
    expect(o.envelope.type).toBe('recycling.collector_intake_recorded');
    expect(o.envelope.payload).toEqual({ delivery_id: 'd1', day: '2026-11-09', collector: 'Moses', gross_kg: 1000, tare_kg: 50, deduction_pct: 8, price_per_kg: 1200, has_scale_photo: true, provisional_no: 'PV-261109-AB12' });
  });
  it('sends the preset only when it is a server id', () => {
    expect(intakePayload(d({ deductionPresetId: 'ref_deduction_8' }))).not.toHaveProperty('deduction_preset_id');
    expect(intakePayload(d({ deductionPresetId: '0190a1b2-0000-7000-8000-000000000001' })).deduction_preset_id).toBe('0190a1b2-0000-7000-8000-000000000001');
  });
  it('shows the same net as the shared arithmetic until the server answers', () => {
    expect(intakeNet({ grossKg: 1000, tareKg: 50, deductionPct: 8, pricePerKg: 1200 }).netKg).toBe(874);
  });
});

describe('voucher numbers and snapshot rows', () => {
  it('shows the provisional number, then the server voucher', () => {
    const p = d({ provisionalNo: provisionalVoucher('2026-11-09', 'ab12') });
    expect(p.provisionalNo).toBe('PV-261109-AB12');
    expect(voucherNo(p, [p])).toBe('PV-261109-AB12');
    const s = { ...p, voucherNo: 'RV-000042' };
    expect(voucherNo(s, [s])).toBe('RV-000042');
    expect(voucherLabel(s)).toBe('RV-000042');
    expect(isConfirmed(p)).toBe(false); expect(isConfirmed(s)).toBe(true);
  });
  it('keeps the demo voucher format when no number is set', () => { expect(voucherNo(d(), [d()])).toBe('RV-261109-01'); });
  it('maps a snapshot row and lets the server value win', () => {
    const r = deliveryFromRow({ id: 'd1', collector: 'Moses', grossKg: '1000.000', tareKg: '50.000', deductionPct: '8.000', netKg: '874.000', pricePerKg: '1200.00', currency: 'UGX', paid: 1, day: '2026-11-09', at: '2026-11-09 08:00:00.000000', hasScalePhoto: 1, voucherNo: 'RV-000042' });
    expect(r).toMatchObject({ id: 'd1', netKg: 874, paid: true, voucherNo: 'RV-000042', hasScalePhoto: true, at: '2026-11-09T08:00:00.000Z' });
  });
});

describe('ledger and payment', () => {
  const amountOf = (x: CollectorDelivery) => Math.round(x.netKg * x.pricePerKg);
  it('adds deliveries still waiting to sync to the server totals', () => {
    const srv = { collector: 'Moses', deliveries: 3, unpaidDeliveries: 1, totalNetKg: 2000, totalOwed: 3000000, totalPaid: 2000000, balance: 1000000, lastDelivery: '2026-11-08' };
    const t = ledgerTotals([d({ voucherNo: 'RV-1' }), d({ id: 'd2', provisionalNo: 'PV-x' })], srv, amountOf)!;
    expect(t).toEqual({ amount: 3000000 + 1048800, paid: 2000000, unpaid: 1000000 + 1048800, unpaidCount: 2 });
    expect(ledgerTotals([d()], undefined, amountOf)).toBeNull();
  });
  it('words payment failures', () => {
    expect(payErrorMessage(new ApiError(409, 'day_locked', 'x'))).toMatch(/cash book is closed/);
    expect(payErrorMessage(new ApiError(409, 'state_conflict', 'Delivery RV-1 is already paid.'))).toBe('Delivery RV-1 is already paid.');
    expect(payErrorMessage(new ApiError(0, 'network', 'x'))).toMatch(/needs the server/);
  });
});
