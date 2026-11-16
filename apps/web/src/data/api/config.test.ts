import { describe, expect, it } from 'vitest';
import type { SiteSettings } from '@tanaad/domain';
import { companyBody, configErrorMessage, machineBody, productBody, refBody, settingsBody, zoneBody } from './configMap';
import { userFromProfile } from './users';
import { emptyData, shiftCalendar } from '../emptyData';

const UUID = '0197a000-0000-7000-8000-000000000001';

describe('config request bodies', () => {
  it('sends a group list with siteId null and never sends a demo id', () => {
    expect(refBody({ id: 'ref_unit_1', list: 'unit', label: 'kg' }, null)).toMatchObject({ list: 'unit', siteId: null, label: 'kg', value: null });
    expect(refBody({ id: 'ref_unit_1', list: 'unit', label: 'kg' }, null)).not.toHaveProperty('id');
    expect(refBody({ id: UUID, list: 'sector', label: 'Weaving', order: 2, active: false }, 'site-uuid')).toMatchObject({ id: UUID, siteId: 'site-uuid', order: 2, active: false });
  });
  it('lays a rule change over every current rule', () => {
    const cur = { gateTolerancePct: 2, massGapPct: 1 } as SiteSettings;
    expect(settingsBody(cur, { massGapPct: 3 })).toEqual({ gateTolerancePct: 2, massGapPct: 3 });
  });
  it('keeps prices with an amount and drops the rest', () => {
    const b = productBody({ siteId: 'mats', internalName: 'A', kind: 'mat', bundlePieces: 1, stdBundleWeightKg: 1, weightTolerancePct: 2, weightStatus: 'measured', active: true,
      prices: [{ channel: 'local', currency: 'UGX', amount: 100, effectiveFrom: '2026-01-01' }, { channel: 'export', currency: 'USD', amount: 0, effectiveFrom: '2026-01-01' }] });
    expect(b.prices).toEqual([{ channel: 'local', currency: 'UGX', amount: 100, effectiveFrom: '2026-01-01' }]);
    expect(b).not.toHaveProperty('id');
  });
  it('machines, zones and company details', () => {
    expect(machineBody({ siteId: 'mats', code: 'L1', kind: 'loom', stageCode: 'weave', status: 'active', id: UUID })).toMatchObject({ id: UUID, code: 'L1', capacityKgPerDay: null });
    expect(zoneBody({ id: 'z_x', name: 'Bay 1' })).toEqual({ name: 'Bay 1' });
    expect(companyBody({ address: 'a', vatRatePct: 0 } as never, { vatRatePct: 18 })).toEqual({ address: 'a', vatRatePct: 18 });
  });
  it('explains refusals in plain words', () => {
    expect(configErrorMessage({ code: 'network' })).toMatch(/online/);
    expect(configErrorMessage({ status: 403, code: 'forbidden' })).toMatch(/role/);
    expect(configErrorMessage({ status: 422, code: 'invalid_payload', detail: 'label: required' })).toBe('label: required');
  });
});

describe('real build starting data', () => {
  it('has no accounts or records, and a shift calendar from the sites own configuration', () => {
    const { seed, master } = emptyData('2026-11-10');
    expect(seed.users).toEqual([]); expect(seed.people).toEqual([]); expect(master.refs).toEqual([]);
    expect(seed.shifts.length).toBeGreaterThan(0);
    expect(shiftCalendar('2026-11-10').some((s) => s.id === 'sh_mats_2026-11-10_day')).toBe(true);
  });
  it('remembers a signed-in profile as a user', () => {
    expect(userFromProfile({ id: 'x', code: 'amina', name: 'Amina', avatar_seed: 'AN', person_id: null, roles: [{ role: 'supervisor', site_id: 's', site_code: 'mats' }, { role: 'ceo', site_id: null, site_code: null }] }))
      .toEqual({ id: 'amina', name: 'Amina', avatarSeed: 'AN', roles: [{ role: 'supervisor', siteId: 'mats' }, { role: 'ceo' }] });
  });
});
