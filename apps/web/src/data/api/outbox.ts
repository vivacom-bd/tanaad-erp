import Dexie, { type Table } from 'dexie';
import { api } from './http';
import type { EnvelopeOut, IngestResponse, ProblemCode } from './types';

export interface OutboxItem {
  key?: number; // auto-increment, preserves enqueue order
  siteCode: string;
  envelope: EnvelopeOut;
  code?: ProblemCode;
  reason?: string;
}

class OutboxDb extends Dexie {
  outbox!: Table<OutboxItem, number>;
  rejected!: Table<OutboxItem, number>;
  constructor() {
    super('tanaad-outbox');
    this.version(1).stores({ outbox: '++key, siteCode', rejected: '++key, siteCode' });
  }
}

export const outboxDb = new OutboxDb();
export const BATCH_SIZE = 200;

export async function enqueue(siteCode: string, envelope: EnvelopeOut): Promise<void> {
  await outboxDb.outbox.add({ siteCode, envelope });
}

export function pending(siteCode: string): Promise<OutboxItem[]> {
  return outboxDb.outbox.where('siteCode').equals(siteCode).sortBy('key');
}

export function rejectedItems(siteCode: string): Promise<OutboxItem[]> {
  return outboxDb.rejected.where('siteCode').equals(siteCode).sortBy('key');
}

export type OnAssigned = (provisionalRef: string, number: string) => void;

export async function flushOutbox(
  siteCode: string, token: string, onAssigned?: OnAssigned,
): Promise<{ accepted: number; duplicate: number; rejected: OutboxItem[] }> {
  const items = await pending(siteCode);
  let accepted = 0, duplicate = 0;
  const rejected: OutboxItem[] = [];
  for (let i = 0; i < items.length; i += BATCH_SIZE) {
    const batch = items.slice(i, i + BATCH_SIZE);
    const res = await api<IngestResponse>(`/sites/${encodeURIComponent(siteCode)}/events`, {
      method: 'POST', token, body: { batch_id: crypto.randomUUID(), events: batch.map((b) => b.envelope) },
    });
    const byId = new Map(batch.map((b) => [b.envelope.id, b]));
    for (const r of res.results) {
      const item = byId.get(r.id);
      if (!item || item.key === undefined) continue;
      if (r.status === 'accepted') {
        accepted++;
        if (r.assigned && onAssigned) for (const [ref, num] of Object.entries(r.assigned)) onAssigned(ref, num);
      } else if (r.status === 'duplicate') {
        duplicate++;
      } else {
        const bad: OutboxItem = { siteCode, envelope: item.envelope, code: r.code, reason: r.reason };
        await outboxDb.rejected.add(bad);
        rejected.push(bad);
      }
      await outboxDb.outbox.delete(item.key);
    }
  }
  return { accepted, duplicate, rejected };
}
