import { spawn, spawnSync, type ChildProcess } from 'node:child_process';
import { createConnection } from 'node:net';
import { existsSync, mkdirSync, openSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from '@playwright/test';
import { API_ORIGIN, API_PORT, DB_HOST, DB_NAME, DB_PORT, RUN_DIR, WEB_PORT, WEB_URL } from './env';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../../..');
const apiDir = resolve(root, 'apps/api');
const webDir = resolve(root, 'apps/web');
export const PIDS_FILE = resolve(RUN_DIR, 'pids.json');

const tcp = (host: string, port: number) => new Promise<boolean>((ok) => {
  const s = createConnection({ host, port }); s.once('connect', () => { s.destroy(); ok(true); }); s.once('error', () => ok(false)); s.setTimeout(2000, () => { s.destroy(); ok(false); });
});
const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

/** Kill the process groups a previous run of THIS slot (same web port) recorded. Never touches anything else. */
export async function killOwn() {
  if (!existsSync(PIDS_FILE)) return;
  const pids = JSON.parse(readFileSync(PIDS_FILE, 'utf8')) as Record<string, number>;
  for (const pid of Object.values(pids)) { try { process.kill(-pid, 'SIGTERM'); } catch { /* gone */ } }
  await sleep(1000);
  for (const pid of Object.values(pids)) { try { process.kill(-pid, 'SIGKILL'); } catch { /* gone */ } }
  rmSync(PIDS_FILE, { force: true });
}

async function waitFor(url: string, what: string, ms = 90_000) {
  const end = Date.now() + ms;
  while (Date.now() < end) {
    try { const r = await fetch(url); if (r.status < 500) return; } catch { /* not up yet */ }
    await sleep(500);
  }
  throw new Error(`${what} did not come up at ${url} within ${ms / 1000}s (see ${RUN_DIR}/*.log)`);
}

export default async function globalSetup() {
  if (process.env.E2E_REUSE === '1') return; // stack already running on these ports (fast loop while writing specs)
  mkdirSync(RUN_DIR, { recursive: true });
  await killOwn(); // stale processes left by a crashed earlier run of this slot
  if (!(await tcp(DB_HOST, DB_PORT))) throw new Error(`MySQL is not reachable at ${DB_HOST}:${DB_PORT}. Start the tanaad-mysql container first.`);
  for (const [port, name] of [[API_PORT, 'API'], [WEB_PORT, 'web']] as const) {
    if (await tcp('127.0.0.1', port)) throw new Error(`${name} port ${port} is in use by a process that is not this slot's; pick another slot or free it (set E2E_SLOT / E2E_API_PORT / E2E_WEB_PORT).`);
  }

  // DB credentials come from apps/api/.env; the env below only picks the database and the origin. No Redis on the dev box:
  // The file store with a per-DB prefix keeps throttle counters apart per slot. (The database store deadlocked on its
  // "insert ignore into cache" under the app's parallel polling and turned random calls, e.g. GET /alerts, into 500s.)
  const apiEnv = {
    ...process.env, DB_DATABASE: DB_NAME, APP_URL: API_ORIGIN, APP_ENV: 'local', APP_DEBUG: 'false',
    CACHE_STORE: 'file', CACHE_PREFIX: `tanaad-e2e-${DB_NAME}-`, QUEUE_CONNECTION: 'sync',
    // No MinIO needed: uploads (POD signatures, photos) go to apps/api/storage/app/{media,hr}; GET /media/{hash} streams them.
    MEDIA_DISK: process.env.MEDIA_DISK ?? 'local',
    // Bearer-token API: the web origin must NOT be in SANCTUM_STATEFUL_DOMAINS (that switches on cookie/CSRF mode and 419s every login).
    PHP_CLI_SERVER_WORKERS: process.env.PHP_CLI_SERVER_WORKERS ?? '4',
  };
  const fresh = spawnSync('php', ['artisan', 'migrate:fresh', '--seed', '--force'], { cwd: apiDir, env: apiEnv, encoding: 'utf8' });
  writeFileSync(resolve(RUN_DIR, 'reseed.log'), `${fresh.stdout}\n${fresh.stderr}`);
  if (fresh.status !== 0) throw new Error(`php artisan migrate:fresh --seed failed on ${DB_NAME}:\n${(fresh.stdout + fresh.stderr).slice(-2000)}`);
  spawnSync('php', ['artisan', 'cache:clear'], { cwd: apiDir, env: apiEnv });

  const spawnLogged = (name: string, cmd: string, args: string[], cwd: string, env: NodeJS.ProcessEnv): ChildProcess => {
    const fd = openSync(resolve(RUN_DIR, `${name}.log`), 'w');
    return spawn(cmd, args, { cwd, env, stdio: ['ignore', fd, fd], detached: true });
  };
  const api = spawnLogged('api', 'php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${API_PORT}`, '--no-reload'], apiDir, apiEnv);
  const web = spawnLogged('web', 'pnpm', ['exec', 'vite', '--host', '127.0.0.1', '--port', String(WEB_PORT), '--strictPort'], webDir,
    { ...process.env, VITE_API_URL: `http://127.0.0.1:${API_PORT}/api/v1`, VITE_DEMO: '0' });
  writeFileSync(PIDS_FILE, JSON.stringify({ api: api.pid, web: web.pid }));
  api.unref(); web.unref();

  await waitFor(`${API_ORIGIN}/up`, 'API');
  await waitFor(WEB_URL, 'Web app');
  // Warm vite: its first page load optimises dependencies and reloads, which would eat the first spec's time.
  const browser = await chromium.launch({ channel: 'chrome' });
  try { const page = await browser.newPage(); await page.goto(WEB_URL); await page.waitForLoadState('networkidle'); await page.waitForTimeout(1500); await page.goto(WEB_URL); await page.waitForLoadState('networkidle'); } finally { await browser.close(); }
}
