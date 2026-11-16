import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

// A run is identified by its web port. Slots give several agents their own DB + ports so runs can go side by side:
//   E2E_SLOT=1..5  ->  API 810N, web 520N, and the DB below (see README). No slot -> 8099 / 5199 / tanaad.
// E2E_DB, E2E_API_PORT and E2E_WEB_PORT override any of them. The DB must already exist (nothing here creates one).
const SLOT_DB: Record<string, string> = { '1': 'tanaad_test_cash', '2': 'tanaad_test_group', '3': 'tanaad_test_media', '4': 'tanaad_test_people', '5': 'tanaad_test_production' };
const slot = process.env.E2E_SLOT;
if (slot && !SLOT_DB[slot]) throw new Error(`E2E_SLOT must be 1..5, got ${slot}`);

export const SLOT = slot ?? null;
export const DB_NAME = process.env.E2E_DB ?? (slot ? SLOT_DB[slot] : 'tanaad');
if (!/^tanaad(_test_[a-z0-9_]+)?$/.test(DB_NAME)) throw new Error(`E2E_DB "${DB_NAME}" is not a tanaad dev/test database name.`);
export const API_PORT = Number(process.env.E2E_API_PORT ?? (slot ? 8100 + Number(slot) : 8099));
export const WEB_PORT = Number(process.env.E2E_WEB_PORT ?? (slot ? 5200 + Number(slot) : 5199));
export const API_ORIGIN = `http://127.0.0.1:${API_PORT}`;
export const API_URL = `${API_ORIGIN}/api/v1`;
export const WEB_URL = `http://127.0.0.1:${WEB_PORT}`;
export const DB_HOST = process.env.E2E_DB_HOST ?? '127.0.0.1';
export const DB_PORT = Number(process.env.E2E_DB_PORT ?? 3340);
/** Per-run folder (logs, pids, screenshots, report), keyed by web port so concurrent runs never share files. */
export const RUN_DIR = resolve(fileURLToPath(new URL('..', import.meta.url)), '.runs', String(WEB_PORT));
export const PIN = '1234';
export const PASSWORD = 'password';
