import { ApiError, api } from './http';
import type { SnapshotPage, SnapshotRow } from './types';

const KEY = 'tanaad.api.hwm';
type Hwm = Record<string, number>;

function readHwm(): Hwm {
  try { const raw = localStorage.getItem(KEY); return raw ? (JSON.parse(raw) as Hwm) : {}; } catch { return {}; }
}

export function getHighWater(siteCode: string, projection: string): number {
  return readHwm()[`${siteCode}:${projection}`] ?? 0;
}

export function setHighWater(siteCode: string, projection: string, seq: number): void {
  try { localStorage.setItem(KEY, JSON.stringify({ ...readHwm(), [`${siteCode}:${projection}`]: seq })); } catch { /* storage unavailable */ }
}

export async function pullSnapshot<T>(
  siteCode: string, projection: string, token: string, since: number = getHighWater(siteCode, projection), limit = 500,
): Promise<{ rows: SnapshotRow<T>[]; snapshotSeq: number }> {
  const rows: SnapshotRow<T>[] = [];
  let cursor = since, snapshotSeq = since, more = true;
  while (more) {
    const page = await api<SnapshotPage<T>>(
      `/sites/${encodeURIComponent(siteCode)}/snapshots/${encodeURIComponent(projection)}?since=${cursor}&limit=${limit}`, { token },
    );
    rows.push(...page.data);
    for (const r of page.data) if (r.seq > cursor) cursor = r.seq;
    snapshotSeq = page.snapshot_seq;
    more = page.has_more && page.data.length > 0;
  }
  setHighWater(siteCode, projection, more ? cursor : Math.max(cursor, snapshotSeq));
  return { rows, snapshotSeq };
}

/* ───────────── batched pulls: many tables of one site in one request ───────────── */

export type BatchOutcome<T> =
  | { ok: true; rows: SnapshotRow<T>[]; snapshotSeq: number }
  | { ok: false; status: number; code?: string; detail?: string };

interface BatchTable { status: number; data?: SnapshotRow<unknown>[]; snapshot_seq?: number; has_more?: boolean; code?: string; detail?: string }
interface BatchBody { snapshot_seq: number; results: Record<string, BatchTable> }

/** Set once the server answers 404 to the batch route itself (an older API): from then on the single endpoints are used. */
let batchMissing = false;
export const __resetBatchProbe = () => { batchMissing = false; };

const MAX_TABLES = 80;
const MAX_ROUNDS = 100;

/**
 * Pull every table in `tables` for one site with GET /sites/{site}/snapshots?tables=…&cursors=…, each from its own high-water mark.
 * A table the role may not read (403) or the server does not know (404) comes back as `{ ok: false }` and the rest still answer.
 * A table with `has_more` is asked again from its new cursor until it is drained. Falls back to the single endpoints if the batch route is absent.
 * Returns the server's snapshot_seq for the site (the newest one seen).
 */
export async function pullBatch<T>(siteCode: string, tables: readonly string[], token: string, limit = 500): Promise<{ snapshotSeq: number; tables: Record<string, BatchOutcome<T>> }> {
  const out: Record<string, BatchOutcome<T>> = {};
  const names = [...new Set(tables)];
  if (!names.length) return { snapshotSeq: 0, tables: out };
  if (batchMissing || names.length > MAX_TABLES) return singly<T>(siteCode, names, token, limit);
  const rows = new Map<string, SnapshotRow<T>[]>(names.map((n) => [n, []]));
  const cursor = new Map(names.map((n) => [n, getHighWater(siteCode, n)]));
  let ask = names, snapshotSeq = 0;
  for (let round = 0; ask.length && round < MAX_ROUNDS; round++) {
    let body: BatchBody;
    try {
      body = await api<BatchBody>(
        `/sites/${encodeURIComponent(siteCode)}/snapshots?tables=${ask.map(encodeURIComponent).join(',')}&cursors=${ask.map((n) => `${encodeURIComponent(n)}:${cursor.get(n)}`).join(',')}&limit=${limit}`, { token },
      );
    } catch (e) {
      if (round === 0 && e instanceof ApiError && e.status === 404) { batchMissing = true; return singly<T>(siteCode, names, token, limit); }
      throw e;
    }
    snapshotSeq = Math.max(snapshotSeq, body.snapshot_seq ?? 0);
    const next: string[] = [];
    for (const n of ask) {
      const r = body.results?.[n];
      if (!r) { out[n] = { ok: false, status: 404, code: 'not_found', detail: `${n} was not answered` }; continue; }
      if (r.status !== 200) { out[n] = { ok: false, status: r.status, code: r.code, detail: r.detail }; continue; }
      const data = (r.data ?? []) as SnapshotRow<T>[];
      rows.get(n)!.push(...data);
      let c = cursor.get(n)!; for (const x of data) if (x.seq > c) c = x.seq;
      cursor.set(n, c);
      if (r.has_more && data.length > 0) { next.push(n); continue; }
      const seq = r.snapshot_seq ?? body.snapshot_seq ?? c;
      setHighWater(siteCode, n, Math.max(c, seq));
      out[n] = { ok: true, rows: rows.get(n)!, snapshotSeq: seq };
    }
    ask = next;
  }
  return { snapshotSeq, tables: out };
}

/** The one-table-per-request path: used when the batch route is absent. Same outcomes as the batch. */
async function singly<T>(siteCode: string, names: string[], token: string, limit: number) {
  const out: Record<string, BatchOutcome<T>> = {}; let snapshotSeq = 0;
  for (const n of names) {
    try { const r = await pullSnapshot<T>(siteCode, n, token, undefined, limit); out[n] = { ok: true, rows: r.rows, snapshotSeq: r.snapshotSeq }; snapshotSeq = Math.max(snapshotSeq, r.snapshotSeq); }
    catch (e) { if (e instanceof ApiError && (e.status === 403 || e.status === 404)) out[n] = { ok: false, status: e.status, code: e.code, detail: e.detail }; else throw e; }
  }
  return { snapshotSeq, tables: out };
}
