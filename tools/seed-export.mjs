// Exports the web prototype's demo people, engagements, attendance and cash history to
// apps/api/database/seed-data/*.json for the API's PeopleSeeder and CashSeeder, which
// replay them through the ledger. Run from the repo root: pnpm seed:export
import { register } from 'node:module';
import { mkdirSync, writeFileSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = new URL('../', import.meta.url);
const hooks = `
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
const domain = ${JSON.stringify(new URL('packages/domain/src/index.ts', root).href)};
const web = ${JSON.stringify(new URL('apps/web/src/', root).href)};
export async function resolve(spec, ctx, next) {
  if (spec === '@tanaad/domain') return { url: domain, shortCircuit: true };
  let url = spec.startsWith('@/') ? new URL(spec.slice(2), web).href
    : (spec.startsWith('./') || spec.startsWith('../')) && ctx.parentURL ? new URL(spec, ctx.parentURL).href : null;
  if (url !== null && !/\\.[cm]?[jt]s$/.test(url)) {
    for (const c of [url + '.ts', url + '/index.ts']) if (existsSync(fileURLToPath(c))) { url = c; break; }
  }
  return url === null ? next(spec, ctx) : { url, shortCircuit: true };
}`;
register('data:text/javascript,' + encodeURIComponent(hooks), pathToFileURL('./'));

const { buildSeed, TODAY } = await import(new URL('apps/web/src/data/seed/build.ts', root).href);
const s = buildSeed();
const shiftOf = new Map(s.shifts.map((sh) => [sh.id, sh]));
const out = {
  people: s.people.map((p) => ({ id: p.id, site: p.homeSiteId, full_name: p.fullName, id_number: p.idNumber, phone_momo: p.phoneMomo, sector: p.sector, shared: p.shared, shared_sites: p.sharedSites, status: p.status, joined_on: p.joinedOn })),
  engagements: s.engagements.map((e) => ({ id: e.id, person_id: e.personId, site: e.siteId, type: e.type, start_date: e.startDate, end_date: e.endDate ?? null, term_days: e.termDays ?? null, rate_basis: e.rateBasis, rate: e.rate, currency: e.currency, settlement_cycle: e.settlementCycle, status: e.status })),
  attendance: s.attendance.map((a) => { const sh = shiftOf.get(a.shiftId); return { id: a.id, person_id: a.personId, site: a.workedSiteId, day: sh.day, shift: sh.kind, status: a.status, overtime_hours: a.overtimeHours, marked_by: a.markedBy, at: a.at }; }),
  cash: {
    days: s.cashDays.map((d) => ({ id: d.id, site: d.siteId, day: d.day, currency: d.currency, opening: d.opening, status: d.status, counted: d.countedClosing ?? null, signed_by: d.signedBy ?? null, closed_at: d.closedAt ?? null, denominations: d.denominations ?? {} })),
    entries: s.cashEntries.map((e) => ({ id: e.id, cash_day_id: e.cashDayId, site: e.siteId, day: e.day, direction: e.direction, category: e.category, subcategory: e.subcategory ?? null, amount: e.amount, currency: e.currency, fx_reference: e.fxReference, machine: e.machineId ?? null, product: e.productId ?? null, payee: e.payee ?? null, note: e.note ?? null, has_receipt_photo: e.hasReceiptPhoto, by: e.by, at: e.at })),
  },
};
const dir = new URL('apps/api/database/seed-data/', root);
mkdirSync(dir, { recursive: true });
for (const [name, data] of Object.entries(out)) writeFileSync(new URL(name + '.json', dir), JSON.stringify({ today: TODAY, rows: data }, null, 1) + '\n');
console.log(Object.entries(out).map(([k, v]) => `${k}: ${Array.isArray(v) ? v.length : `${v.days.length} days, ${v.entries.length} entries`}`).join(', '));
