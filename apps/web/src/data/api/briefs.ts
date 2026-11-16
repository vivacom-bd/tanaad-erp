// Morning brief log (group-wide, CEO and group admin). POST /briefs and GET /briefs?day=.
import { api } from './http';
import { loadSession } from './auth';

export type BriefChannel = 'copy' | 'whatsapp' | 'log';
export interface BriefRow { id: string; day: string; channel: BriefChannel; recipientIds: string[]; lines: string[]; sentBy?: string; sentAt: string }

const uid = () => (typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`);

/** Request body for POST /briefs (camelCase). Blank lines are kept as spacers. */
export const briefBody = (day: string, channel: BriefChannel, lines: string[], recipientIds: string[] = [], briefId: string = uid()) =>
  ({ briefId, day, channel, ...(recipientIds.length ? { recipientIds } : {}), lines });

export async function sendBrief(day: string, channel: BriefChannel, lines: string[], recipientIds: string[] = []): Promise<BriefRow> {
  const token = loadSession()?.token;
  const res = await api<{ brief: BriefRow }>('/briefs', { method: 'POST', token, body: briefBody(day, channel, lines, recipientIds) });
  return res.brief;
}

export async function listBriefs(day: string): Promise<BriefRow[]> {
  const token = loadSession()?.token;
  return (await api<{ data: BriefRow[] }>(`/briefs?day=${encodeURIComponent(day)}`, { token })).data;
}
