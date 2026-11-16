// The store's starting data in a real build: nothing invented. Every record arrives from the server after sign-in.
// The only things made here are the shift calendar (days and shift kinds come from each site's own configuration) and blank rule/company shells.
import type { CompanyProfile, Shift, SiteCode, SiteSettings } from '@tanaad/domain';
import type { SeedData } from './seed/build';
import { SITE_LIST } from '@/config/sites';

const minuteOf = (hhmm: string) => { const [h, m] = hhmm.split(':').map(Number); return h * 60 + (m || 0); };
/**
 * Today's business date in Kampala (UTC+3). Inside the tail of a night shift that crosses midnight (00:00 to its end, 07:00 here) the
 * business day is still yesterday, exactly as the server decides it (Calendar::businessDay); otherwise the server rejects entries
 * for "today" as being in the future.
 */
export const realToday = (now: number = Date.now()) => {
  const local = new Date(now + 3 * 3600_000);
  const tailEnd = Math.max(0, ...SITE_LIST.flatMap((s) => s.shifts).filter((sh) => minuteOf(sh.ends) < minuteOf(sh.starts)).map((sh) => minuteOf(sh.ends)));
  const minute = local.getUTCHours() * 60 + local.getUTCMinutes();
  return new Date(local.getTime() - (minute < tailEnd ? 86_400_000 : 0)).toISOString().slice(0, 10);
};

const addDays = (day: string, n: number) => { const d = new Date(day + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };

/** The last 14 days of shifts for each site, skipping the site's closed days (same ids the screens build with shiftId()). */
export function shiftCalendar(today: string): Shift[] {
  const out: Shift[] = [];
  for (const site of SITE_LIST) for (let n = 13; n >= 0; n--) {
    const day = addDays(today, -n); if (site.closedDays.includes(new Date(day + 'T00:00:00Z').getUTCDay())) continue;
    for (const sk of site.shifts) out.push({ id: `sh_${site.id}_${day}_${sk.kind}`, siteId: site.id, day, kind: sk.kind });
  }
  return out;
}

export function emptyData(today: string): { seed: SeedData; master: { refs: never[]; purchases: never[]; siteSettings: Record<SiteCode, SiteSettings>; companyProfiles: Record<SiteCode, CompanyProfile> } } {
  const seed: SeedData = {
    users: [], machines: [], products: [], benchmarks: [], people: [], engagements: [], shifts: shiftCalendar(today), attendance: [], cashDays: [], cashEntries: [],
    labels: [], production: [], zones: [], stockCounts: [], clients: [], orders: [], dispatches: [], invoices: [], payments: [], parts: [], partIssues: [],
    breakdowns: [], collectorDeliveries: [], exceptions: [], audit: [],
  };
  const rules: SiteSettings = { gateTolerancePct: 0, massGapPct: 0, cashVariance: 0, benchmarkPct: 0, expenseApprovalLimit: 0, archiveAfterAbsentDays: 0, contractWarningDays: 0, standardDayHours: 0, monthWorkingDays: 0, downtimeReasonAfterMin: 0, materialCostPerKg: 0 };
  const blank: CompanyProfile = { address: '', phone: '', email: '', tin: '', bankDetails: '', mobileMoney: '', vatRatePct: 0, efrisConnected: false };
  const per = <T,>(v: T) => Object.fromEntries(SITE_LIST.map((x) => [x.id, { ...v }])) as Record<SiteCode, T>;
  return { seed, master: { refs: [], purchases: [], siteSettings: per(rules), companyProfiles: per(blank) } };
}
