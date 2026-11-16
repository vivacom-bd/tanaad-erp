// Display names for ids that come from the server: never show a raw UUID.
import { create } from 'zustand';
import { api } from './http';

export interface DirectoryEntry { id: string; name: string; code?: string; roles?: unknown }
/** Everyone on the server by user id, from GET /directory, so "who did it" shows a name even for people this device never loaded. */
export const useDirectory = create<{ byId: Record<string, DirectoryEntry>; loadedAt: number }>(() => ({ byId: {}, loadedAt: 0 }));
export const directoryName = (id: string | undefined) => (id ? useDirectory.getState().byId[id]?.name : undefined);
export function setDirectory(rows: DirectoryEntry[]) {
  useDirectory.setState({ byId: Object.fromEntries(rows.filter((r) => r && r.id && r.name).map((r) => [String(r.id), r])), loadedAt: Date.now() });
}
/** Fetch the directory at most every 5 minutes. A server without the endpoint (404) or a refusal just leaves the fallbacks in place. */
export async function loadDirectory(token: string, force = false): Promise<void> {
  if (!force && Date.now() - useDirectory.getState().loadedAt < 5 * 60_000) return;
  try {
    const res = await api<{ data?: DirectoryEntry[] } | DirectoryEntry[]>('/directory', { token });
    setDirectory(Array.isArray(res) ? res : res?.data ?? []);
  } catch { useDirectory.setState({ loadedAt: Date.now() }); }
}
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
export const isUuid = (v: string | undefined) => !!v && UUID.test(v);

/** Server-sent name first, then the known users/people, then the server directory, else "A colleague" for a UUID (demo codes pass through). */
export function userLabel(id: string | undefined, users: { id: string; name: string }[], hint?: string, people: { id: string; fullName: string }[] = []): string {
  if (hint?.trim()) return hint.trim();
  if (!id) return 'A colleague';
  const u = users.find((x) => x.id === id)?.name ?? people.find((x) => x.id === id)?.fullName ?? directoryName(id);
  if (u) return u;
  return isUuid(id) ? 'A colleague' : id;
}

const ACTION_NAMES: Record<string, string> = {
  'cash.entry_recorded': 'Record a cash entry', 'cash.entry_corrected': 'Correct a cash entry', 'cash.day_closed': 'Close the cash day', 'cash.day_reopened': 'Reopen the cash day', 'cash.opening_set': 'Set the opening balance',
  'group.exception_decided': 'Decide an exception', 'group.exception_raised': 'Raise an exception',
  'people.payout_recorded': 'Record a wage payout', 'people.advance_given': 'Give an advance', 'people.attendance_marked': 'Mark attendance', 'people.attendance_corrected': 'Correct attendance',
  'purchases.recorded': 'Record a purchase', 'purchases.received': 'Receive a purchase', 'purchases.changed': 'Change a purchase', 'orders.created': 'Create an order', 'stock.counted': 'Record a stock count',
};
/** What happened, from an event type: "cash.day_reopened" / "Cash.day_reopened" / "CashDayReopened" -> "Cash day reopened". */
export function eventName(type: string): string {
  const t = type.trim().replace(/([a-z0-9])([A-Z])/g, '$1 $2').replace(/[._]+/g, ' ').replace(/\s+/g, ' ').toLowerCase();
  return t ? t[0].toUpperCase() + t.slice(1) : type;
}
/** What the person was trying to do (for rejected submissions). */
export const actionName = (type: string) => ACTION_NAMES[type.trim().toLowerCase()] ?? eventName(type);
