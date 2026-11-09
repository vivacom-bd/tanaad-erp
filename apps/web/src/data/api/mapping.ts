// Pure translation between the prototype store's shapes and the API's (envelopes out, snapshot rows in).
import type {
  Attendance, BusinessException, CashDay, CashEntry, DomainEvent, Engagement, ExceptionKind, ExceptionStatus, Person, SiteCode,
} from '@tanaad/domain';
import type { EnvelopeOut } from './types';

/* ───────────── exception status wording ───────────── */

/** API exception status → the prototype's wording. approved stays approved; rejected reads "held"; acknowledged reads "resolved".
 *  A server "held" is not final: it maps to "open" so it stays actionable (exceptionFromRow marks it onHold). */
export function exceptionStatusFromApi(status: string): ExceptionStatus {
  switch (status) {
    case 'approved': return 'approved';
    case 'rejected': return 'held';
    case 'acknowledged': return 'resolved';
    case 'held': return 'open';
    default: return 'open';
  }
}

/** The prototype's decision verb → the API's decision value (the inverse of exceptionStatusFromApi). */
export function decisionToApi(d: 'approved' | 'held' | 'resolved'): 'approve' | 'hold' | 'acknowledge' {
  return d === 'approved' ? 'approve' : d === 'held' ? 'hold' : 'acknowledge';
}

/** A hold keeps the exception open on the server, which only accepts it with a note. */
export const decisionNeedsNote = (d: 'approved' | 'held' | 'resolved') => decisionToApi(d) === 'hold';

/** Kinds the server raises itself (policies, scheduler); people raise the rest by hand. Mirrors ExceptionRules::SYSTEM_KINDS. */
export const SYSTEM_KINDS: ExceptionKind[] = ['cash_variance', 'ghost_worker', 'contract_expiring'];

/* ───────────── outgoing: store event → envelope ───────────── */

export interface Outgoing { siteCode: SiteCode; envelope: EnvelopeOut }

/** sh_{site}_{day}_{kind} → { day, kind } */
export function parseShiftId(shiftId: string): { day?: string; kind?: string } {
  const m = /^sh_[a-z]+_(\d{4}-\d{2}-\d{2})_([a-z]+)$/.exec(shiftId);
  return m ? { day: m[1], kind: m[2] } : {};
}

export const provisionalPersonRef = (personId: string) => `tmp-person-${personId}`;

const opt = <T extends Record<string, unknown>>(o: T) => Object.fromEntries(Object.entries(o).filter(([, v]) => v !== undefined && v !== '')) as T;

/** Translate one store event into the ingest envelope. Returns null for events the API does not take from this client. */
export function toEnvelope(ev: DomainEvent, deviceId?: string): Outgoing | null {
  if (!ev.siteId) return null;
  const base = { version: 1, occurred_at: ev.occurredAt, ...(deviceId ? { device_id: deviceId } : {}) };
  const out = (type: string, payload: Record<string, unknown>, extra: Partial<EnvelopeOut> = {}, id = ev.id): Outgoing =>
    ({ siteCode: ev.siteId!, envelope: { id, type, ...base, payload, ...(ev.reason ? { reason: ev.reason } : {}), ...extra } });
  const p = ev.payload as Record<string, unknown>;
  switch (ev.type) {
    case 'PersonRegistered': {
      const { person, eng } = p as { person: Person; eng: Engagement };
      return out('people.person_registered', opt({
        person_id: person.id, full_name: person.fullName, id_number: person.idNumber, phone_momo: person.phoneMomo,
        sector: person.sector, joined_on: person.joinedOn,
        engagement: opt({ type: eng.type, rate_basis: eng.rateBasis, rate: eng.rate, currency: eng.currency, settlement_cycle: eng.settlementCycle, start_date: eng.startDate, end_date: eng.endDate, term_days: eng.termDays }),
      }), { provisional_ref: provisionalPersonRef(person.id) });
    }
    case 'AttendanceMarked':
    case 'AttendanceCorrected': {
      const a = p as unknown as Attendance; const { day, kind } = parseShiftId(a.shiftId);
      const payload = opt({ person_id: a.personId, status: a.status, overtime_hours: a.overtimeHours, day, shift_kind: kind });
      if (ev.type === 'AttendanceMarked') return out('people.attendance_marked', payload, {}, a.id); // attendance id = event id on the server
      return out('people.attendance_corrected', payload, { reason: ev.reason ?? 'Corrected on the attendance sheet' });
    }
    case 'EngagementRenewed': {
      const e = p as unknown as Engagement & { renewsEngagementId?: string };
      return out('people.engagement_renewed', opt({ engagement_id: e.renewsEngagementId, new_engagement_id: e.id, term_days: e.termDays, start_date: e.startDate }));
    }
    case 'EngagementEnded':
      return out('people.engagement_ended', opt({ engagement_id: p.engagementId, end_date: p.endDate, reason: ev.reason }));
    case 'PersonArchived': return out('people.person_archived', opt({ person_id: p.personId, reason: ev.reason }));
    case 'PersonReactivated': return out('people.person_reactivated', { person_id: p.personId });
    case 'CashEntryRecorded': {
      const e = p as unknown as CashEntry;
      return out('cash.entry_recorded', opt({
        entry_id: e.id, day: e.day, direction: e.direction, category: e.category, subcategory: e.subcategory, amount: e.amount,
        currency: e.currency, machine_id: e.machineId, product_id: e.productId, payee: e.payee, note: e.note, has_receipt_photo: e.hasReceiptPhoto,
      }));
    }
    case 'CashEntryCorrected': {
      const e = p as unknown as CashEntry & { correctsEntryId: string };
      return out('cash.entry_corrected', opt({
        entry_id: e.id, corrects_entry_id: e.correctsEntryId, day: e.day, direction: e.direction, category: e.category, subcategory: e.subcategory,
        amount: e.amount, currency: e.currency, machine_id: e.machineId, product_id: e.productId, payee: e.payee, note: e.note,
        has_receipt_photo: e.hasReceiptPhoto, reason: ev.reason,
      }));
    }
    case 'ExceptionRaisedManual': {
      const x = p as unknown as BusinessException;
      return out('group.exception_raised', opt({ exception_id: x.id, kind: x.kind, severity: x.severity, title: x.title, detail: x.detail, subject_ref: x.subjectRef, owner_role: x.ownerRole }));
    }
    case 'CashOpeningSet': return out('cash.opening_set', { day: p.day, amount: p.amount });
    case 'CashDayClosed': return out('cash.day_closed', opt({ day: p.day, counted: p.counted, denominations: p.denominations }));
    case 'CashDayReopened': return out('cash.day_reopened', { day: p.day, reason: ev.reason ?? '' });
    case 'ExceptionDecided': return out('group.exception_decided', opt({ exception_id: p.id, decision: decisionToApi(p.decision as 'approved'), note: ev.reason }));
    default: return null; // other modules are not on the API yet; exceptions are raised server-side
  }
}

/* ───────────── incoming: snapshot row → store shape ───────────── */

type Row = Record<string, unknown>;
const num = (v: unknown) => (v === null || v === undefined ? 0 : Number(v));
const optNum = (v: unknown) => (v === null || v === undefined ? undefined : Number(v));
const str = (v: unknown) => (v === null || v === undefined ? undefined : String(v));
const iso = (v: unknown) => { const s = str(v); return s ? (s.includes('T') ? s : s.replace(' ', 'T').replace(/(\.\d{3})\d*$/, '$1') + 'Z') : ''; };

export type SiteOf = (siteId: unknown) => SiteCode;

export const personFromRow = (r: Row, site: SiteOf): Person => ({
  id: String(r.id), homeSiteId: site(r.siteId), fullName: String(r.fullName ?? ''), idNumber: String(r.idNumber ?? ''), phoneMomo: String(r.phoneMomo ?? ''),
  sector: String(r.sector ?? ''), shared: !!Number(r.shared), sharedSites: ((r.sharedSites as unknown[]) ?? []).map(site),
  avatarSeed: String(r.avatarSeed ?? ''), status: r.status === 'archived' ? 'archived' : 'active', joinedOn: String(r.joinedOn ?? ''), number: str(r.number),
});

export const engagementFromRow = (r: Row, site: SiteOf): Engagement => ({
  id: String(r.id), personId: String(r.personId), siteId: site(r.siteId), type: r.type as Engagement['type'], startDate: String(r.startDate),
  endDate: str(r.endDate), termDays: optNum(r.termDays), rateBasis: r.rateBasis as Engagement['rateBasis'], rate: num(r.rate),
  currency: r.currency as Engagement['currency'], settlementCycle: r.settlementCycle as Engagement['settlementCycle'], status: r.status as Engagement['status'],
});

export const attendanceFromRow = (r: Row, site: SiteOf): Attendance => ({
  id: String(r.id), shiftId: String(r.shiftId), personId: String(r.personId), workedSiteId: site(r.siteId), status: r.status as Attendance['status'],
  overtimeHours: num(r.overtimeHours), markedBy: String(r.markedBy ?? ''), markedByName: str(r.markedByName), at: iso(r.markedAt),
});

export const cashDayFromRow = (r: Row, site: SiteOf): CashDay => ({
  id: String(r.id), siteId: site(r.siteId), day: String(r.day), currency: r.currency as CashDay['currency'], opening: num(r.opening),
  status: r.status as CashDay['status'], countedClosing: optNum(r.countedClosing), signedBy: str(r.signedBy), closedAt: r.closedAt ? iso(r.closedAt) : undefined,
  denominations: Array.isArray(r.denominations) ? {} : (r.denominations as Record<string, number> | undefined),
});

export const cashEntryFromRow = (r: Row, site: SiteOf): CashEntry => ({
  id: String(r.id), cashDayId: String(r.cashDayId), siteId: site(r.siteId), day: String(r.day), direction: r.direction as CashEntry['direction'],
  category: r.category as CashEntry['category'], subcategory: str(r.subcategory), amount: num(r.amount), currency: r.currency as CashEntry['currency'],
  fxReference: num(r.fxReference), machineId: str(r.machineId), productId: str(r.productId), payee: str(r.payee), note: str(r.note),
  hasReceiptPhoto: !!Number(r.hasReceiptPhoto), at: iso(r.at), by: String(r.recordedBy ?? ''), byName: str(r.recordedByName),
});

export const exceptionFromRow = (r: Row, site: SiteOf): BusinessException => ({
  id: String(r.id), siteId: r.siteCode ? (r.siteCode as SiteCode) : site(r.siteId), kind: r.kind as BusinessException['kind'],
  severity: r.severity as BusinessException['severity'], title: String(r.title ?? ''), detail: String(r.detail ?? ''), subjectRef: str(r.subjectRef),
  status: exceptionStatusFromApi(String(r.status)), ownerRole: r.ownerRole as BusinessException['ownerRole'], raisedAt: iso(r.raisedAt),
  decidedBy: str(r.decidedBy), decidedAt: r.decidedAt ? iso(r.decidedAt) : undefined, decisionReason: str(r.decisionReason) ?? str(r.decisionNote),
  ...(r.status === 'held' ? { onHold: true } : {}),
});

/** Merge server rows into a local list by id; server wins; tombstoned rows are removed. */
export function mergeById<T extends { id: string }>(local: T[], incoming: { row: T; deleted: boolean }[]): T[] {
  if (!incoming.length) return local;
  const map = new Map(local.map((x) => [x.id, x]));
  for (const { row, deleted } of incoming) { if (deleted) map.delete(row.id); else map.set(row.id, row); }
  return [...map.values()];
}
