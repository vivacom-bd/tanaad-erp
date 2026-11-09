import { api } from './http';
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
