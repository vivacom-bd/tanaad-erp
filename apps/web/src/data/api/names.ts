// Display names for ids that come from the server: never show a raw UUID.
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
export const isUuid = (v: string | undefined) => !!v && UUID.test(v);

/** Server-sent name first, then the known users/people, else "Unknown user" for a UUID (demo codes pass through). */
export function userLabel(id: string | undefined, users: { id: string; name: string }[], hint?: string, people: { id: string; fullName: string }[] = []): string {
  if (hint?.trim()) return hint.trim();
  if (!id) return 'Unknown user';
  const u = users.find((x) => x.id === id)?.name ?? people.find((x) => x.id === id)?.fullName;
  if (u) return u;
  return isUuid(id) ? 'Unknown user' : id;
}
