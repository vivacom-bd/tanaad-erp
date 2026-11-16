// Real mode has no preset accounts: the people who sign in on this device are remembered from the server's own profile.
import type { User } from '@tanaad/domain';
import { useStore } from '../store';
import type { Profile } from './types';

export const userFromProfile = (p: Profile): User => ({
  id: p.code, name: p.name, avatarSeed: p.avatar_seed,
  roles: p.roles.map((r) => ({ role: r.role, ...(r.site_code ? { siteId: r.site_code } : {}) })),
});

export function rememberUser(p: Profile | undefined) {
  if (!p) return;
  const u = userFromProfile(p);
  useStore.setState((st) => ({ users: [...st.users.filter((x) => x.id !== u.id), u] }));
}
