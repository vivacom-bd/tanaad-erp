import { api } from './http';
import type { MeResponse, Session } from './types';

const KEY = 'tanaad.api.session';

export function loadSession(): Session | null {
  try { const raw = localStorage.getItem(KEY); return raw ? (JSON.parse(raw) as Session) : null; } catch { return null; }
}
export function saveSession(s: Session): void {
  try { localStorage.setItem(KEY, JSON.stringify(s)); } catch { /* storage unavailable */ }
}
export function clearSession(): void {
  try { localStorage.removeItem(KEY); } catch { /* storage unavailable */ }
}

export async function login(username: string, password: string): Promise<Session> {
  const s = await api<Session>('/auth/login', { method: 'POST', body: { username, password } });
  saveSession(s);
  return s;
}

export async function pinLogin(username: string, pin: string): Promise<Session> {
  const s = await api<Session>('/auth/pin-login', { method: 'POST', body: { username, pin } });
  saveSession(s);
  return s;
}

export async function pinUnlock(deviceToken: string, userId: string, pin: string): Promise<Session> {
  const s = await api<Session>('/auth/pin', { method: 'POST', body: { user_id: userId, pin }, token: deviceToken });
  saveSession(s);
  return s;
}

export async function logout(token: string): Promise<void> {
  try { await api<void>('/auth/session', { method: 'DELETE', token }); } finally { clearSession(); }
}

export function me(token: string): Promise<MeResponse> {
  return api<MeResponse>('/me', { token });
}
