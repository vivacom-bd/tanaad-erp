import { API_URL } from './env';
import type { ProblemCode } from './types';

export class ApiError extends Error {
  status: number; code: ProblemCode | 'network' | 'unknown'; detail: string; details?: Record<string, unknown>;
  constructor(status: number, code: ApiError['code'], detail: string, details?: Record<string, unknown>) {
    super(detail || code);
    this.name = 'ApiError'; this.status = status; this.code = code; this.detail = detail; this.details = details;
  }
}

export interface RequestOptions { method?: string; body?: unknown; token?: string | null }

export async function api<T>(path: string, { method = 'GET', body, token }: RequestOptions = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json, application/problem+json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (token) headers.Authorization = `Bearer ${token}`;
  let res: Response;
  try {
    res = await fetch(`${API_URL ?? ''}${path}`, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) });
  } catch (e) {
    throw new ApiError(0, 'network', e instanceof Error ? e.message : 'Network error');
  }
  if (res.status === 204) return undefined as T;
  const text = await res.text();
  const json: unknown = text ? JSON.parse(text) : undefined;
  if (!res.ok) {
    const p = (json ?? {}) as { code?: ProblemCode; detail?: string; title?: string; details?: Record<string, unknown> };
    throw new ApiError(res.status, p.code ?? 'unknown', p.detail ?? p.title ?? res.statusText, p.details);
  }
  return json as T;
}
