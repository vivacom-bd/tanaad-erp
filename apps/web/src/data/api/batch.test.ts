import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { getHighWater, pullBatch, __resetBatchProbe } from './snapshots';
import { mediaUrl } from './media';

function memoryStorage(): Storage {
  const m = new Map<string, string>();
  return { get length() { return m.size; }, clear: () => m.clear(), getItem: (k) => m.get(k) ?? null, key: (i) => [...m.keys()][i] ?? null, removeItem: (k) => { m.delete(k); }, setItem: (k, v) => { m.set(k, v); } };
}
const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const fetchMock = vi.fn<typeof fetch>();
const urlOf = (i: number) => new URL(String(fetchMock.mock.calls[i][0]), 'http://x');

beforeEach(() => { fetchMock.mockReset(); vi.stubGlobal('fetch', fetchMock); vi.stubGlobal('localStorage', memoryStorage()); __resetBatchProbe(); });
afterEach(() => vi.unstubAllGlobals());

describe('pullBatch()', () => {
  it('reads a mix of 200, 403 and 404 in one request and stores each table cursor', async () => {
    fetchMock.mockResolvedValueOnce(json(200, { snapshot_seq: 50, results: {
      people: { status: 200, data: [{ id: 'p1', seq: 7 }, { id: 'p2', seq: 9 }], snapshot_seq: 50, has_more: false },
      labels: { status: 403, code: 'forbidden', detail: 'no' },
      nope: { status: 404, code: 'not_found', detail: 'unknown' },
    } }));
    const r = await pullBatch<{ id: string }>('mats', ['people', 'labels', 'nope'], 'tok');
    expect(fetchMock).toHaveBeenCalledTimes(1);
    const u = urlOf(0); expect(u.pathname).toBe('/api/v1/sites/mats/snapshots'); expect(u.searchParams.get('tables')).toBe('people,labels,nope');
    expect(u.searchParams.get('cursors')).toBe('people:0,labels:0,nope:0');
    expect(r.snapshotSeq).toBe(50);
    expect(r.tables.people).toMatchObject({ ok: true, snapshotSeq: 50 });
    expect(r.tables.people.ok && r.tables.people.rows.map((x) => x.id)).toEqual(['p1', 'p2']);
    expect(r.tables.labels).toMatchObject({ ok: false, status: 403 });
    expect(r.tables.nope).toMatchObject({ ok: false, status: 404 });
    expect(getHighWater('mats', 'people')).toBe(50);
    expect(getHighWater('mats', 'labels')).toBe(0);
  });

  it('sends each table from its own stored cursor', async () => {
    localStorage.setItem('tanaad.api.hwm', JSON.stringify({ 'mats:people': 12, 'mats:cash_days': 40 }));
    fetchMock.mockResolvedValueOnce(json(200, { snapshot_seq: 40, results: { people: { status: 200, data: [], snapshot_seq: 40, has_more: false }, cash_days: { status: 200, data: [], snapshot_seq: 40, has_more: false } } }));
    await pullBatch('mats', ['people', 'cash_days'], 'tok');
    expect(urlOf(0).searchParams.get('cursors')).toBe('people:12,cash_days:40');
  });

  it('loops on has_more with the advanced cursor, asking only for the tables still open', async () => {
    fetchMock
      .mockResolvedValueOnce(json(200, { snapshot_seq: 30, results: {
        a: { status: 200, data: [{ id: '1', seq: 1 }, { id: '2', seq: 2 }], snapshot_seq: 30, has_more: true },
        b: { status: 200, data: [{ id: 'x', seq: 5 }], snapshot_seq: 30, has_more: false } } }))
      .mockResolvedValueOnce(json(200, { snapshot_seq: 30, results: { a: { status: 200, data: [{ id: '3', seq: 3 }], snapshot_seq: 30, has_more: false } } }));
    const r = await pullBatch<{ id: string }>('mats', ['a', 'b'], 'tok', 2);
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(urlOf(1).searchParams.get('tables')).toBe('a');
    expect(urlOf(1).searchParams.get('cursors')).toBe('a:2');
    expect(r.tables.a.ok && r.tables.a.rows.map((x) => x.id)).toEqual(['1', '2', '3']);
    expect(r.tables.b.ok && r.tables.b.rows).toHaveLength(1);
    expect(getHighWater('mats', 'a')).toBe(30);
  });

  it('falls back to the single endpoints when the batch route is 404, and remembers it', async () => {
    fetchMock.mockImplementation(async (input) => {
      const url = String(input);
      if (url.includes('/snapshots?tables=')) return json(404, { code: 'not_found', detail: 'no route' });
      if (url.includes('/snapshots/labels')) return json(403, { code: 'forbidden', detail: 'no' });
      return json(200, { projection: 'people', data: [{ id: 'p1', seq: 3 }], snapshot_seq: 9, has_more: false });
    });
    const r = await pullBatch('mats', ['people', 'labels'], 'tok');
    expect(r.tables.people).toMatchObject({ ok: true }); expect(r.tables.labels).toMatchObject({ ok: false, status: 403 });
    expect(fetchMock).toHaveBeenCalledTimes(3); // 1 failed batch + 2 singles
    fetchMock.mockClear();
    await pullBatch('mats', ['people'], 'tok');
    expect(fetchMock).toHaveBeenCalledTimes(1); // straight to the single endpoint
    expect(String(fetchMock.mock.calls[0][0])).toContain('/snapshots/people?');
  });

  it('throws on a server error so the sync reports it', async () => {
    fetchMock.mockResolvedValueOnce(json(500, { code: 'unknown', detail: 'boom' }));
    await expect(pullBatch('mats', ['people'], 'tok')).rejects.toMatchObject({ status: 500 });
  });
});

describe('mediaUrl()', () => {
  beforeEach(() => { localStorage.setItem('tanaad.api.session', JSON.stringify({ token: 't', user: { roles: [] }, sites: null, device: null })); });
  it('uses a signed URL when the server returns one', async () => {
    fetchMock.mockResolvedValueOnce(json(200, { url: 'https://s3.example/x?sig=1' }));
    expect(await mediaUrl('abc')).toBe('https://s3.example/x?sig=1');
  });
  it('turns a streamed file into an object URL', async () => {
    const create = vi.fn(() => 'blob:local'); vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: create }));
    fetchMock.mockResolvedValueOnce(new Response('png', { status: 200, headers: { 'Content-Type': 'image/png' } }));
    expect(await mediaUrl('abc')).toBe('blob:local');
  });
});

import { fmtDateTime, fmtTime, kampalaDay } from '../format';
describe('Kampala time display', () => {
  it('renders UTC instants in Africa/Kampala', () => {
    expect(fmtTime('2026-11-10T07:00:00Z')).toBe('10:00');
    expect(kampalaDay('2026-11-10T22:30:00Z')).toBe('2026-11-11');
    expect(fmtDateTime('2026-11-10T22:30:00.250Z')).toBe('11 Nov 01:30');
    expect(fmtTime('2026-11-10T09:15:00+03:00')).toBe('09:15');
    expect(kampalaDay('2026-11-10')).toBe('2026-11-10');
  });
});
