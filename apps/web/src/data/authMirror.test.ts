import { beforeEach, describe, expect, it, vi } from 'vitest';
import { readMirror, restorable, writeMirror, type AuthMirror } from './authMirror';

beforeEach(() => {
  const m = new Map<string, string>();
  vi.stubGlobal('localStorage', { getItem: (k: string) => m.get(k) ?? null, setItem: (k: string, v: string) => { m.set(k, v); }, removeItem: (k: string) => { m.delete(k); } });
});

const mirror: AuthMirror = { auth: { userId: 'u1', method: 'pin', at: 't' }, session: { userId: 'u1', role: 'ceo' as never, deviceId: 'd' } };

describe('auth mirror', () => {
  it('round-trips and clears on sign-out', () => {
    writeMirror(mirror); expect(readMirror()).toEqual(mirror);
    writeMirror({ auth: null, session: null }); expect(readMirror()).toBeNull();
  });
  it('restores only when the store lost the sign-in and the API user matches', () => {
    expect(restorable({ auth: null, session: null }, mirror, 'u1')).toEqual(mirror);
    expect(restorable({ auth: null, session: null }, mirror, 'u2')).toBeNull();
    expect(restorable({ auth: null, session: null }, mirror, null)).toBeNull();
    expect(restorable(mirror, mirror, 'u1')).toBeNull();
  });
  it('restores the workspace when the store kept the sign-in but lost the session', () => {
    expect(restorable({ auth: mirror.auth, session: null }, mirror, 'u1')).toEqual(mirror);
    expect(restorable({ auth: { userId: 'u2', method: 'pin', at: 't' }, session: null }, mirror, 'u1')).toBeNull();
    expect(restorable({ auth: mirror.auth, session: null }, { ...mirror, session: null }, 'u1')).toBeNull();
  });
});
