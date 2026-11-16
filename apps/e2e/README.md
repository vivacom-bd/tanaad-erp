# apps/e2e: browser tests against the real stack

Playwright drives the system Google Chrome (`channel: 'chrome'`; no browser download, never run `playwright install`).

## Run

    pnpm --filter e2e test              # everything
    pnpm --filter e2e test:smoke        # one smoke per role
    pnpm --filter e2e exec playwright test -g supmats --headed

Prerequisite: the `tanaad-mysql` container on 127.0.0.1:3340 (credentials come from `apps/api/.env`).

## What the run does (support/global-setup.ts)

1. Checks MySQL is reachable and ports 8099 / 5199 are free.
2. `php artisan migrate:fresh --seed` in `apps/api` (the dev DB `tanaad`: **it is wiped every run**).
3. `php artisan serve --port=8099` (API; `APP_URL`, `CACHE_STORE=database`, `QUEUE_CONNECTION=sync` set by env because the dev box has no Redis; `MEDIA_DISK=local` so uploads such as POD signatures and photos work without MinIO (files land in `apps/api/storage/app/{media,hr}`, content-addressed, so slots can share them; export `MEDIA_DISK=s3` to test against MinIO); CORS already allows `api/*`; the SPA uses bearer tokens, so Sanctum stateful domains stay unset).
4. `vite --port 5199` with `VITE_API_URL=http://127.0.0.1:8099/api/v1` and `VITE_DEMO=0` (env beats `apps/web/.env*`).
5. Tears both down afterwards. Logs: `.runs/<webPort>/{api,web,reseed}.log`.

`E2E_REUSE=1` skips setup/teardown and reuses an already running stack on 8099/5199 (fast loop; no reseed).

## Running several agents side by side

Give each run its own slot; it gets its own database, ports and run folder (`apps/e2e/.runs/<webPort>/`: logs, pids, screenshots, html report).

    E2E_SLOT=2 pnpm --filter e2e test

| slot | DB                       | API port | web port |
|------|--------------------------|----------|----------|
| none | `tanaad` (the dev DB)    | 8099     | 5199     |
| 1    | `tanaad_test_cash`       | 8101     | 5201     |
| 2    | `tanaad_test_group`      | 8102     | 5202     |
| 3    | `tanaad_test_media`      | 8103     | 5203     |
| 4    | `tanaad_test_people`     | 8104     | 5204     |
| 5    | `tanaad_test_production` | 8105     | 5205     |

Overrides: `E2E_DB` (an existing `tanaad` / `tanaad_test_*` database; nothing creates one), `E2E_API_PORT`, `E2E_WEB_PORT`, `E2E_DB_HOST`, `E2E_DB_PORT`.
Setup kills only the process groups recorded in its own run folder (stale ones from a crashed run of the same slot) and refuses to start if its ports are held by anything else. The cache/throttle store is the database, so slots never share rate-limit counters.

## Strictness

A spec fails on any console error or API 4xx/5xx (no allow-list). The web app skips reads the signed-in role cannot make (`apps/web/src/data/api/readAccess.ts`), so a 403 is a real finding. Use `w.allow(/regex/)` inside a spec only for an expected failure and say why.

## Writing specs (support/helpers.ts)

- `test` from helpers gives a `w` fixture: collects console errors and API 4xx/5xx; call `w.assertClean()` (use `w.allow(/regex/)` for expected ones) and a screenshot is attached on failure.
- `loginAs(page, 'supmats')` signs in through the sign-in form (demo password `password`; PIN is `1234` for the API).
- `openFirstPortal(page)`, `visitPrimaryNav(page)`.
- `goOffline(page)` / `goOnline(page)` toggle the browser context.
- `api(request, 'ceo1')` logs in at `/auth/pin-login` (PIN 1234) and returns `get/post/put/json` for server-side assertions.

Specs run serially (one shared DB). Specs that change data must not rely on another spec's leftovers; the DB is reseeded only once per run.
