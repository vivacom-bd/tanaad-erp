// Media upload for proof of delivery: POST /media with the file, and the server answers with its sha256 hash.
import { API_URL } from './env';
import { ApiError } from './http';
import { loadSession } from './auth';

export type MediaPurpose = 'photo' | 'document';

/** Upload a captured image and return its content hash. Throws ApiError (network, 403, 422) with the server's wording. */
export async function uploadMedia(file: Blob, purpose: MediaPurpose = 'photo', name = 'capture.png'): Promise<string> {
  const s = loadSession(); if (!s) throw new ApiError(401, 'unauthenticated', 'Sign in again.');
  const form = new FormData(); form.append('purpose', purpose); form.append('file', file, name);
  let res: Response;
  try { res = await fetch(`${API_URL ?? ''}/media`, { method: 'POST', headers: { Accept: 'application/json', Authorization: `Bearer ${s.token}` }, body: form }); }
  catch (e) { throw new ApiError(0, 'network', e instanceof Error ? e.message : 'Network error'); }
  const text = await res.text(); const json = text ? JSON.parse(text) as { hash?: string; detail?: string; message?: string; code?: string } : {};
  if (!res.ok) throw new ApiError(res.status, (json.code as ApiError['code']) ?? 'unknown', json.detail ?? json.message ?? res.statusText);
  if (!json.hash) throw new ApiError(res.status, 'unknown', 'The server did not return a file hash.');
  return json.hash;
}

/**
 * A URL an <img> or <a> can open for a stored file. GET /media/{hash} answers with either JSON holding a signed URL (S3/MinIO disk)
 * or the file itself (local disk, streamed): both work, and a redirect to a signed URL is followed by the browser.
 */
export async function mediaUrl(hash: string): Promise<string> {
  const s = loadSession(); if (!s) throw new ApiError(401, 'unauthenticated', 'Sign in again.');
  let res: Response;
  try { res = await fetch(`${API_URL ?? ''}/media/${encodeURIComponent(hash)}`, { headers: { Accept: 'application/json, */*', Authorization: `Bearer ${s.token}` } }); }
  catch (e) { throw new ApiError(0, 'network', e instanceof Error ? e.message : 'Network error'); }
  if (!res.ok) throw new ApiError(res.status, 'unknown', res.statusText || 'The file could not be opened.');
  const type = res.headers.get('Content-Type') ?? '';
  if (type.includes('json')) {
    const j = await res.json() as { url?: string; signed_url?: string; data?: { url?: string } };
    const u = j.url ?? j.signed_url ?? j.data?.url;
    if (!u) throw new ApiError(res.status, 'unknown', 'The server did not return a file link.');
    return u;
  }
  return URL.createObjectURL(await res.blob());
}
