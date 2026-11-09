// Hand-written for now; these will be generated from contracts/openapi.yaml later (ADR-007).

export type ProblemCode =
  | 'unknown_type' | 'invalid_payload' | 'forbidden' | 'day_locked' | 'not_found'
  | 'state_conflict' | 'duplicate_fact' | 'stale_config' | 'unauthenticated';

export interface Problem {
  type: string; title: string; status: number; code: ProblemCode; detail: string;
  details?: Record<string, unknown>;
}

export interface RoleGrant { role: string; site_id: string | null; site_code: string | null }

export interface Profile {
  id: string; code: string; name: string; avatar_seed: string; person_id: string | null;
  roles: RoleGrant[];
}

export interface Device { id: string; name: string; site_id: string; site_code: string }

export interface Session {
  token: string;
  expires_at?: string;
  user: Profile;
  sites: string[] | null; // null = every site
  device: Device | null;
}

export interface MeResponse { user: Profile; sites: string[] | null; device: Device | null }

export interface EnvelopeOut {
  id: string; type: string; version: number; occurred_at: string;
  payload: Record<string, unknown>;
  device_id?: string; corrects_event_id?: string; reason?: string; provisional_ref?: string;
}

export type IngestResult =
  | { id: string; status: 'accepted'; seq: number; assigned?: Record<string, string> }
  | { id: string; status: 'duplicate'; seq: number }
  | { id: string; status: 'rejected'; code: ProblemCode; reason: string; details?: Record<string, unknown> };

export interface IngestResponse { results: IngestResult[]; snapshot_seq: number }

export type SnapshotRow<T> = T & { seq: number; deletedAt?: string | null };

export interface SnapshotPage<T> {
  projection: string; data: SnapshotRow<T>[]; snapshot_seq: number; has_more: boolean;
}

export interface SiteConfigDoc {
  site: Record<string, unknown>;
  settings: Record<string, unknown>;
  refs: Record<string, unknown>;
  products: unknown[];
  machines: unknown[];
  benchmarks: unknown[];
  zones: unknown[];
  companyProfile: Record<string, unknown> | null;
  fx: Record<string, unknown>;
  numberSeries: Record<string, unknown>;
  snapshot_seq: number;
}
