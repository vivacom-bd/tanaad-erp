import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, api } from './http';
import { loadSession, login } from './auth';
import { enqueue, flushOutbox, outboxDb, pending, rejectedItems } from './outbox';
import { getHighWater, pullSnapshot } from './snapshots';
import type { EnvelopeOut, IngestResult } from './types';

function memoryStorage(): Storage {
  const m = new Map<string, string>();
  return {
    get length() { return m.size; },
    clear: () => m.clear(),
    getItem: (k) => m.get(k) ?? null,
    key: (i) => [...m.keys()][i] ?? null,
    removeItem: (k) => { m.delete(k); },
    setItem: (k, v) => { m.set(k, v); },
  };
}

function json(status: number, body: unknown, type = 'application/json'): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': type } });
}

const fetchMock = vi.fn<typeof fetch>();

beforeEach(async () => {
  fetchMock.mockReset();
  vi.stubGlobal('fetch', fetchMock);
  vi.stubGlobal('localStorage', memoryStorage());
  await outboxDb.outbox.clear();
  await outboxDb.rejected.clear();
});

const env = (i: number): EnvelopeOut => ({
  id: `e${i}`, type: 'test.happened', version: 1, occurred_at: '2026-11-09T00:00:00Z', payload: {},
  provisional_ref: i === 0 ? 'P-1' : undefined,
});

describe('api()', () => {
  it('throws ApiError with the problem code on 403', async () => {
    fetchMock.mockResolvedValue(json(403, { type: 'about:blank', title: 'Forbidden', status: 403, code: 'forbidden', detail: 'No' }, 'application/problem+json'));
    const err = await api('/x').catch((e: unknown) => e);
    expect(err).toBeInstanceOf(ApiError);
    expect(err).toMatchObject({ status: 403, code: 'forbidden', detail: 'No' });
  });

  it('returns undefined for 204', async () => {
    fetchMock.mockResolvedValue(new Response(null, { status: 204 }));
    expect(await api('/x', { method: 'DELETE', token: 't' })).toBeUndefined();
  });
});

describe('login()', () => {
  it('stores the session', async () => {
    const body = { token: 'tok', user: { id: 'u1', code: 'U1', name: 'A', avatar_seed: 's', person_id: null, roles: [] }, sites: null, device: null };
    fetchMock.mockResolvedValue(json(201, body));
    await login('a@b.c', 'pw');
    expect(loadSession()).toEqual(body);
    const init = fetchMock.mock.calls[0]?.[1];
    expect(init?.method).toBe('POST');
  });
});

describe('flushOutbox()', () => {
  it('handles mixed results across two batches and applies assigned numbers', async () => {
    for (let i = 0; i < 205; i++) await enqueue('mats', env(i));
    fetchMock.mockImplementation(async (_url, init) => {
      const { events } = JSON.parse(String(init?.body)) as { events: EnvelopeOut[] };
      const results: IngestResult[] = events.map((e) => {
        const n = Number(e.id.slice(1));
        if (n === 0) return { id: e.id, status: 'accepted', seq: 1, assigned: { 'P-1': 'INV-0001' } };
        if (n === 1) return { id: e.id, status: 'duplicate', seq: 2 };
        if (n === 204) return { id: e.id, status: 'rejected', code: 'day_locked', reason: 'Day is locked' };
        return { id: e.id, status: 'accepted', seq: n + 1 };
      });
      return json(200, { results, snapshot_seq: 300 });
    });
    const onAssigned = vi.fn();
    const out = await flushOutbox('mats', 'tok', onAssigned);
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(out.accepted).toBe(203);
    expect(out.duplicate).toBe(1);
    expect(out.rejected).toHaveLength(1);
    expect(out.rejected[0]).toMatchObject({ code: 'day_locked', reason: 'Day is locked' });
    expect(onAssigned).toHaveBeenCalledWith('P-1', 'INV-0001');
    expect(await pending('mats')).toHaveLength(0);
    expect(await rejectedItems('mats')).toHaveLength(1);
  });
});

describe('pullSnapshot()', () => {
  it('follows has_more and updates the high-water mark', async () => {
    fetchMock
      .mockResolvedValueOnce(json(200, { projection: 'stock', data: [{ seq: 3 }, { seq: 5 }], snapshot_seq: 9, has_more: true }))
      .mockResolvedValueOnce(json(200, { projection: 'stock', data: [{ seq: 9, deletedAt: '2026-11-09' }], snapshot_seq: 9, has_more: false }));
    const out = await pullSnapshot<{ id?: string }>('mats', 'stock', 'tok', 0);
    expect(out.rows).toHaveLength(3);
    expect(out.snapshotSeq).toBe(9);
    expect(String(fetchMock.mock.calls[1]?.[0])).toContain('since=5');
    expect(getHighWater('mats', 'stock')).toBe(9);
  });
});
