import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { approvalsFromApi, auditFromApi, auditPageFromApi, cashNote, cockpitFromApi, groupPnlFromApi, pnlFromApi, shiftMonth, unitCostGap } from './groupMap';
import { auditPath, canCloseCosting, canReadGroupViews, tolerantGet, writeError } from './group';
import { ApiError } from './http';

const site = (id: unknown) => (id === 'bags' ? 'bags' : 'mats') as 'mats' | 'bags';
const fetchMock = vi.fn<typeof fetch>();
beforeEach(() => { fetchMock.mockReset(); vi.stubGlobal('fetch', fetchMock); });
const res = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

describe('cockpit', () => {
  it('maps numbers and keeps receivables null when the server has none', () => {
    const c = cockpitFromApi({ day: '2026-11-10', sites: [{ siteId: 'u1', siteCode: 'mats', name: 'Mats', currency: 'UGX', output: { entries: 2, goodUnits: '40', rejectUnits: 1, outputKg: 12.5, inputKg: 0, belowBenchmark: 1 }, headcount: { present: 5, half: 1, absent: 0, total: 6, overtimeHours: 2 }, cash: { inSafe: 1500, asOf: '2026-11-09', closeStatus: 'none', variance: null }, exceptions: { critical: 1, warning: 0, info: 0, held: 0, total: 1 }, stock: { bundles: 3, kg: 9, products: 1 }, dispatches: { pending: 1, held: 0, inTransit: 0 }, machinesDown: { count: 1, machines: ['M1'] }, receivables: null }] }, site);
    expect(c.sites[0]).toMatchObject({ siteCode: 'mats', output: { goodUnits: 40 }, cash: { variance: null, asOf: '2026-11-09' }, receivables: null, machinesDown: { machines: ['M1'] } });
    expect(cashNote(c.sites[0].cash, c.day)).toMatch(/none opened today/);
    expect(cashNote({ inSafe: 0, asOf: null, closeStatus: 'none', variance: null }, c.day)).toMatch(/No cash page/);
  });
  it('survives an empty answer', () => { expect(cockpitFromApi(undefined, site)).toEqual({ day: '', sites: [] }); });
});

describe('approvals', () => {
  it('maps pending orders, tolerating missing columns', () => {
    const a = approvalsFromApi({ orders: [{ id: 'o1', site_id: 'bags', number: 'SO-7', total: '5000.00', currency: 'USD' }, { id: 'o2', site_id: 'mats' }], counts: { exceptions: 2, orders: 2, critical: 1 } }, site);
    expect(a.orders[0]).toMatchObject({ id: 'o1', siteCode: 'bags', number: 'SO-7', total: 5000, currency: 'USD', clientName: null });
    expect(a.orders[1].total).toBeNull();
    expect(a.counts).toMatchObject({ orders: 2, critical: 1, warning: 0 });
  });
  it('reads the API order row as it is sent (camelCase client name and raised time)', () => {
    const a = approvalsFromApi({ orders: [{ id: 'o3', siteCode: 'mats', number: 'SO-00007', clientName: 'Nyabugogo Distributors', total: 1920, currency: 'USD', at: '2026-11-10 09:30:00.000000' }], counts: { exceptions: 0, orders: 1 } }, site);
    expect(a.orders[0]).toMatchObject({ clientName: 'Nyabugogo Distributors', createdAt: '2026-11-10 09:30:00.000000', total: 1920 });
  });
});

describe('audit', () => {
  it('maps the server flag and facts; an unknown flag reads as normal; the device is not invented', () => {
    const a = auditFromApi({ id: 'x', seq: 9, type: 'cash.day_reopened', at: '2026-11-10T08:00:00Z', siteCode: 'mats', actorId: 'u', actorName: 'Amina', summary: 'Cash day reopened', reason: 'Wrong count', flag: 'reopen', facts: { day: '2026-11-09' } }, site);
    expect(a).toMatchObject({ kind: 'cash.day_reopened', flag: 'reopen', deviceId: '', siteId: 'mats', reason: 'Wrong count', facts: { day: '2026-11-09' } });
    expect(auditFromApi({ id: 'y', flag: 'weird', actorId: null, actorName: null }, site)).toMatchObject({ flag: 'normal', actorName: 'A colleague' });
    expect(auditFromApi({ id: 'z', siteCode: null }, site).siteId).toBeUndefined();
  });
  it('maps a page with its meta, and builds the query string from filters', () => {
    const p = auditPageFromApi({ data: [{ id: 'a' }], meta: { page: 2, lastPage: 4, total: 310 } }, site);
    expect(p).toMatchObject({ page: 2, lastPage: 4, total: 310 }); expect(p.rows).toHaveLength(1);
    expect(auditPath({ site: 'bags', type: 'cash', from: '2026-11-01' }, 2, 50)).toBe('/group/audit?site=bags&type=cash&from=2026-11-01&page=2&per_page=50');
    expect(auditPath({ site: 'all' }, 1)).toBe('/group/audit?page=1&per_page=100');
  });
});

describe('costing', () => {
  const raw = { site: 'mats', period: '2026-11', from: '2026-11-01', to: '2026-11-30', provisional: true, status: 'open', version: 0, closed_at: null, closed_days: ['2026-11-03'], open_days: 29, days_in_range: 30, revenue: { cash_sales: 100, invoiced: 50, total: 150, unconverted_lines: 1 }, costs: { by_category: { wages: 40, materials: 20 }, cash: 50, non_cash: 10, excluded: 0, total: 60 }, net: 90, margin_pct: 60, fx_usd_ugx: null, material: { per_kg: 2.5, source: 'purchases' }, unit_costs: [{ product_id: 'p', product: 'Mat', kind: 'mat', basis: 'unit', units: 10, material: 1, overhead: 2, total: 3, price_ugx: 5, margin_pct: 40 }] };
  it('maps a P&L and keeps the provisional flag', () => {
    const p = pnlFromApi(raw, site);
    expect(p).toMatchObject({ siteCode: 'mats', provisional: true, openDays: 29, revenue: { total: 150, unconvertedLines: 1 }, costs: { byCategory: { wages: 40, materials: 20 }, total: 60 }, net: 90, fx: null, materialPerKg: 2.5 });
    expect(p.unitCosts[0]).toMatchObject({ product: 'Mat', total: 3, priceUgx: 5, marginPct: 40 });
    expect(pnlFromApi({ ...raw, provisional: false, status: 'closed', version: 2 }).provisional).toBe(false);
  });
  it('treats a missing provisional flag as provisional, never as final', () => { expect(pnlFromApi({}).provisional).toBe(true); });
  it('maps the group answer with a null USD when there is no rate', () => {
    const g = groupPnlFromApi({ period: '2026-11', provisional: true, sites: [raw], group: { revenue: 150, costs: 60, net: 90, by_category: { wages: 40 }, margin_pct: 60, fx_usd_ugx: null, usd: { revenue: null, costs: null, net: null } } }, site);
    expect(g.sites).toHaveLength(1); expect(g.group.usd.net).toBeNull(); expect(g.group.byCategory.wages).toBe(40);
  });
  it('explains an empty unit cost list and steps months across a year', () => {
    expect(unitCostGap(pnlFromApi({ closed_days: [] }))).toMatch(/No cash day is closed/);
    expect(unitCostGap(pnlFromApi(raw))).toMatch(/No product/);
    expect(shiftMonth('2026-01', -1)).toBe('2025-12'); expect(shiftMonth('2026-12', 1)).toBe('2027-01');
  });
});

describe('role-tolerant fetch', () => {
  it('returns null on 403 and 404, the body on 200, and throws on a network failure', async () => {
    fetchMock.mockResolvedValueOnce(res(403, { code: 'forbidden', detail: 'Group only' }));
    expect(await tolerantGet('/group/cockpit', 't')).toBeNull();
    fetchMock.mockResolvedValueOnce(res(404, { code: 'not_found' }));
    expect(await tolerantGet('/x', 't')).toBeNull();
    fetchMock.mockResolvedValueOnce(res(200, { data: 1 }));
    expect(await tolerantGet('/ok', 't')).toEqual({ data: 1 });
    fetchMock.mockRejectedValueOnce(new Error('down'));
    await expect(tolerantGet('/ok', 't')).rejects.toMatchObject({ code: 'network' });
    fetchMock.mockResolvedValueOnce(res(500, { detail: 'boom' }));
    await expect(tolerantGet('/ok', 't')).rejects.toBeInstanceOf(ApiError);
  });
  it('words refusals plainly and gates buttons by role', () => {
    expect(writeError(new ApiError(0, 'network', 'x'))).toMatch(/Cannot reach/);
    expect(writeError(new ApiError(409, 'state_conflict', 'The period is not closed.'))).toBe('The period is not closed.');
    expect(canCloseCosting(['site_manager'])).toBe(true); expect(canCloseCosting(['cashier'])).toBe(false);
    expect(canReadGroupViews(['ceo'])).toBe(true); expect(canReadGroupViews(['site_manager'])).toBe(false);
  });
});
