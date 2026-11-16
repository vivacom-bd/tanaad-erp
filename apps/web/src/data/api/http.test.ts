import { afterEach, describe, expect, it, vi } from 'vitest';
import { api, setSessionExpiredHandler } from './http';

const reply = (status: number, body: unknown) => vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })));

describe('session expiry', () => {
  afterEach(() => { setSessionExpiredHandler(null); vi.unstubAllGlobals(); });
  it('tells the app when a signed-in request is answered 401', async () => {
    const seen = vi.fn(); setSessionExpiredHandler(seen); reply(401, { code: 'unauthenticated', detail: 'Unauthenticated.' });
    await expect(api('/sites/mats/orders', { token: 'dead' })).rejects.toMatchObject({ status: 401 });
    expect(seen).toHaveBeenCalledTimes(1);
  });
  it('does not treat a failed sign-in as an expired session', async () => {
    const seen = vi.fn(); setSessionExpiredHandler(seen); reply(401, { code: 'unauthenticated', detail: 'Wrong password.' });
    await expect(api('/auth/login', { method: 'POST', body: {} })).rejects.toMatchObject({ status: 401 });
    expect(seen).not.toHaveBeenCalled();
  });
  it('ignores other refusals', async () => {
    const seen = vi.fn(); setSessionExpiredHandler(seen); reply(403, { code: 'forbidden', detail: 'No.' });
    await expect(api('/sites/mats/orders', { token: 'ok' })).rejects.toMatchObject({ status: 403 });
    expect(seen).not.toHaveBeenCalled();
  });
});
