import 'fake-indexeddb/auto';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useStore, eventSink } from '../store';
import { decisionNeedsNote, decisionToApi, exceptionStatusFromApi, toEnvelope } from './mapping';
import { outboxDb, pending, rejectedItems } from './outbox';
import { __setReal, applyAssigned, startSync, stopSync, syncNow, undoRejectedCash, useSync, viewedSite } from './sync';
import type { EnvelopeOut, IngestResult } from './types';

function memoryStorage(): Storage {
  const m = new Map<string, string>();
  return {
    get length() { return m.size; }, clear: () => m.clear(), getItem: (k) => m.get(k) ?? null,
    key: (i) => [...m.keys()][i] ?? null, removeItem: (k) => { m.delete(k); }, setItem: (k, v) => { m.set(k, v); },
  };
}
const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const fetchMock = vi.fn<typeof fetch>();
const SESSION = { token: 'tok', user: { id: 'u1', code: 'u_sup_mats', name: 'A', avatar_seed: 'A', person_id: null, roles: [{ role: 'supervisor', site_id: 'S1', site_code: 'mats' }] }, sites: ['S1'], device: null };
const emptySnapshot = (url: string) => json(200, { projection: url.split('/snapshots/')[1]?.split('?')[0], data: [], snapshot_seq: 0, has_more: false });

beforeEach(async () => {
  fetchMock.mockReset();
  vi.stubGlobal('fetch', fetchMock);
  vi.stubGlobal('localStorage', memoryStorage());
  await outboxDb.outbox.clear(); await outboxDb.rejected.clear();
  useSync.setState({ pending: 0, rejected: [] });
});
afterEach(() => { stopSync(); __setReal(false); });

describe('exception status mapping', () => {
  it('maps API statuses to the prototype wording and back', () => {
    expect(exceptionStatusFromApi('approved')).toBe('approved');
    expect(exceptionStatusFromApi('rejected')).toBe('held');
    expect(exceptionStatusFromApi('acknowledged')).toBe('resolved');
    expect(exceptionStatusFromApi('open')).toBe('open');
    expect(exceptionStatusFromApi('held')).toBe('open'); // a server hold stays actionable
    expect(exceptionStatusFromApi('resolved')).toBe('resolved'); // closed by the server (e.g. a renewed contract), no longer waiting for the CEO
    for (const d of ['approved', 'held', 'resolved'] as const) {
      const api = decisionToApi(d);
      expect(exceptionStatusFromApi({ approve: 'approved', hold: 'open', acknowledge: 'acknowledged' }[api])).toBe(d === 'held' ? 'open' : d);
    }
  });
});

describe('hold decision', () => {
  it('needs a note (sent as a reject) while approve and resolve do not', () => {
    expect(decisionNeedsNote('held')).toBe(true);
    expect(decisionNeedsNote('approved')).toBe(false);
    expect(decisionNeedsNote('resolved')).toBe(false);
  });
  it('carries the typed reason as the note on the envelope', () => {
    const out = toEnvelope({ id: 'ev', siteId: 'mats', type: 'ExceptionDecided', actorId: 'a', deviceId: 'd', occurredAt: '2026-11-09T07:00:00Z', synced: false, reason: 'Waiting for the weighbridge slip', payload: { id: 'ex1', decision: 'held' } });
    expect(out?.envelope).toMatchObject({ type: 'group.exception_decided', payload: { exception_id: 'ex1', decision: 'hold', note: 'Waiting for the weighbridge slip' } });
  });
});

describe('toEnvelope()', () => {
  it('turns a first attendance mark into people.attendance_marked keyed by the attendance id', () => {
    const out = toEnvelope({ id: 'ev', siteId: 'mats', type: 'AttendanceMarked', actorId: 'a', deviceId: 'd', occurredAt: '2026-11-09T07:00:00Z', synced: false,
      payload: { id: 'att1', shiftId: 'sh_mats_2026-11-09_day', personId: 'p1', workedSiteId: 'mats', status: 'present', overtimeHours: 0, markedBy: 'a', at: '' } });
    expect(out).toMatchObject({ siteCode: 'mats', envelope: { id: 'att1', type: 'people.attendance_marked', payload: { person_id: 'p1', day: '2026-11-09', shift_kind: 'day', status: 'present' } } });
  });
});

describe('demo mode', () => {
  it('never calls fetch', async () => {
    __setReal(false);
    startSync();
    expect(eventSink.current).toBeNull();
    const st = useStore.getState();
    const p = st.registerCasual({ siteId: 'mats', fullName: 'Test Person', phoneMomo: '0700', sector: 'Weaving', rate: 10000, idNumber: 'X1' });
    st.addCashEntry({ siteId: 'mats', day: st.today, direction: 'out', category: 'utilities', amount: 1000, hasReceiptPhoto: false });
    st.archivePerson(p.id);
    await syncNow();
    await new Promise((r) => setTimeout(r, 10));
    expect(fetchMock).not.toHaveBeenCalled();
    expect(await outboxDb.outbox.count()).toBe(0);
  });
});

describe('real mode', () => {
  const sent: EnvelopeOut[] = [];
  let offline = false;
  beforeEach(() => {
    sent.length = 0; offline = false;
    __setReal(true);
    localStorage.setItem('tanaad.api.session', JSON.stringify(SESSION));
    fetchMock.mockImplementation(async (input, init) => {
      const url = String(input);
      if (offline) throw new TypeError('Failed to fetch');
      if (url.includes('/snapshots/')) return emptySnapshot(url);
      if (url.endsWith('/events')) {
        const { events } = JSON.parse(String(init?.body)) as { events: EnvelopeOut[] };
        sent.push(...events);
        const results: IngestResult[] = events.map((e) =>
          e.type === 'cash.day_closed' ? { id: e.id, status: 'rejected', code: 'day_locked', reason: 'Day is locked' }
          : e.provisional_ref ? { id: e.id, status: 'accepted', seq: 1, assigned: { [e.provisional_ref]: 'MAT-0099' } }
          : { id: e.id, status: 'accepted', seq: 1 });
        return json(200, { results, snapshot_seq: 1 });
      }
      return json(404, { code: 'not_found', detail: url });
    });
  });

  it('applies assigned numbers, moves rejections to the rejected table and records them for the badge', async () => {
    const { enqueue } = await import('./outbox');
    const st = useStore.getState();
    const person = st.registerCasual({ siteId: 'mats', fullName: 'Zed Test', phoneMomo: '0700', sector: 'Weaving', rate: 10000, idNumber: 'Z1' });
    const reg = toEnvelope({ id: 'e-reg', siteId: 'mats', type: 'PersonRegistered', actorId: 'a', deviceId: 'd', occurredAt: '2026-11-09T07:00:00Z', synced: false,
      payload: { person, eng: st.engagements[0] } })!;
    await enqueue('mats', reg.envelope);
    await enqueue('mats', { id: 'e-close', type: 'cash.day_closed', version: 1, occurred_at: '2026-11-09T18:00:00Z', payload: { day: '2026-11-09', counted: 1 } });
    await syncNow();
    expect(useStore.getState().people.find((p) => p.id === person.id)?.number).toBe('MAT-0099');
    expect(await pending('mats')).toHaveLength(0);
    expect(await rejectedItems('mats')).toHaveLength(1);
    expect(useSync.getState().rejected[0]).toMatchObject({ code: 'day_locked', reason: 'Day is locked' });
  });

  it('drops an optimistic cash line the server refused and re-reads the cash pages', async () => {
    const st = useStore.getState();
    st.addCashEntry({ siteId: 'mats', day: st.today, direction: 'in', category: 'receipt', amount: 5, payee: 'Refused', hasReceiptPhoto: false });
    const line = useStore.getState().cashEntries.find((e) => e.payee === 'Refused')!;
    const keep = useStore.getState().cashEntries.length;
    await undoRejectedCash('mats', [{ siteCode: 'mats', code: 'day_locked', reason: 'Day is locked', envelope: { id: 'x', type: 'cash.entry_recorded', version: 1, occurred_at: '', payload: { entry_id: line.id } } }]);
    expect(useStore.getState().cashEntries.some((e) => e.id === line.id)).toBe(false);
    expect(useStore.getState().cashEntries.length).toBe(keep - 1);
    // other rejections leave the cash pages alone
    const n = useStore.getState().cashEntries.length;
    await undoRejectedCash('mats', [{ siteCode: 'mats', code: 'forbidden', envelope: { id: 'y', type: 'people.person_archived', version: 1, occurred_at: '', payload: {} } }]);
    expect(useStore.getState().cashEntries.length).toBe(n);
  });

  it('keeps writes made offline queued and sends them in order once online', async () => {
    startSync();
    await new Promise((r) => setTimeout(r, 20));
    offline = true;
    const st = useStore.getState();
    st.addCashEntry({ siteId: 'mats', day: st.today, direction: 'out', category: 'utilities', amount: 1, hasReceiptPhoto: false });
    st.addCashEntry({ siteId: 'mats', day: st.today, direction: 'out', category: 'utilities', amount: 2, hasReceiptPhoto: false });
    st.addCashEntry({ siteId: 'mats', day: st.today, direction: 'out', category: 'utilities', amount: 3, hasReceiptPhoto: false });
    await new Promise((r) => setTimeout(r, 50));
    await syncNow();
    expect(await pending('mats')).toHaveLength(3);
    expect(sent).toHaveLength(0);
    offline = false;
    await syncNow();
    expect(sent.map((e) => e.payload.amount)).toEqual([1, 2, 3]);
    expect(await pending('mats')).toHaveLength(0);
  });

  it('sends a write queued while a sync is running right after it, without waiting for the timer', async () => {
    const { enqueue } = await import('./outbox');
    const env = (n: number): EnvelopeOut => ({ id: `e${n}`, type: 'cash.entry_recorded', version: 1, occurred_at: '2026-11-09T07:00:00Z', payload: { amount: n } });
    const inner = fetchMock.getMockImplementation()!;
    let release!: () => void; const gate = new Promise<void>((r) => { release = r; });
    let started!: () => void; const posting = new Promise<void>((r) => { started = r; });
    let held = false;
    fetchMock.mockImplementation(async (input, init) => { if (String(input).endsWith('/events') && !held) { held = true; started(); await gate; } return inner(input, init); });
    await enqueue('mats', env(1));
    const first = syncNow();
    await posting; // the first POST is in flight, carrying only e1
    await enqueue('mats', env(2));
    void syncNow();
    release();
    await first;
    await vi.waitFor(async () => { expect(sent.map((e) => e.id)).toEqual(['e1', 'e2']); expect(await pending('mats')).toHaveLength(0); });
  });

  it('is back online when the server answers, even if one read is refused (429)', async () => {
    useStore.setState({ online: false });
    fetchMock.mockImplementation(async (input) => { const url = String(input); return url.includes('/snapshots/people') ? json(429, { code: 'rate_limited', detail: 'Slow down' }) : emptySnapshot(url); });
    await syncNow();
    expect(useStore.getState().online).toBe(true);
    expect(useSync.getState().lastError).toBeTruthy();
    fetchMock.mockImplementation(async () => { throw new TypeError('Failed to fetch'); });
    await syncNow();
    expect(useStore.getState().online).toBe(false);
  });

  it('treats the site in the address as the one on screen for a group role with several sites', () => {
    vi.stubGlobal('location', { pathname: '/s/bags/people/settlement' });
    expect(viewedSite(['mats', 'bags', 'recycling'])).toBe('bags');
    vi.stubGlobal('location', { pathname: '/g/people' });
    expect(viewedSite(['mats', 'bags', 'recycling'])).toBeUndefined();
    expect(viewedSite(['mats'])).toBe('mats');
    vi.unstubAllGlobals(); vi.stubGlobal('fetch', fetchMock); vi.stubGlobal('localStorage', memoryStorage());
  });

  it('applyAssigned ignores refs it does not own', () => {
    const before = useStore.getState().people;
    applyAssigned('INV-tmp', 'X');
    expect(useStore.getState().people).toBe(before);
  });
});
