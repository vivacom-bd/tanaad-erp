import type { Session } from './store';

/**
 * The store lives in IndexedDB and is written asynchronously, so a reload right after sign-in or after choosing a
 * workspace can land before that write finishes. This small synchronous copy of who is signed in survives that gap.
 */
const KEY = 'tanaad.auth.mirror';
export interface AuthMirror { auth: { userId: string; method: 'password' | 'pin' | 'link'; at: string } | null; session: Session | null }

export function readMirror(): AuthMirror | null {
  try { const raw = localStorage.getItem(KEY); return raw ? (JSON.parse(raw) as AuthMirror) : null; } catch { return null; }
}
export function writeMirror(m: AuthMirror): void {
  try { if (!m.auth && !m.session) localStorage.removeItem(KEY); else localStorage.setItem(KEY, JSON.stringify(m)); } catch { /* storage unavailable */ }
}

/**
 * Mirror to apply after hydration, when the API session is still held and the mirror is for that user: either the store
 * lost the sign-in altogether, or it kept the sign-in but lost the workspace chosen just before the reload.
 */
export function restorable(current: AuthMirror, mirror: AuthMirror | null, apiUserCode: string | null): AuthMirror | null {
  if (!mirror || !apiUserCode) return null;
  if (mirror.auth?.userId !== apiUserCode) return null;
  if (mirror.session && mirror.session.userId !== apiUserCode) return null;
  if (!current.auth) return mirror;
  if (current.auth.userId === apiUserCode && !current.session && mirror.session) return { auth: current.auth, session: mirror.session };
  return null;
}
