# Staging runbook

Single-host Docker Compose stack: Caddy (automatic TLS) -> nginx -> php-fpm, plus a Horizon queue worker,
the scheduler, MySQL 8.4, Redis and MinIO. Only Caddy publishes ports. Nothing here has been run yet:
treat the first bring-up as a rehearsal and fix what it finds.

## One-time setup
1. DNS: point `TANAAD_DOMAIN` at the host. Open ports 80 and 443 (80 is needed for the ACME challenge).
2. `cp infra/staging/staging.env.example infra/staging/staging.env` and `cp infra/staging/api.env.example infra/staging/api.env` and `cp infra/staging/caddy.env.example infra/staging/caddy.env`; replace every `CHANGE_ME`.
   Generate `APP_KEY` with `php artisan key:generate --show`. Set `TANAAD_LEDGER_KEY` once and store it with the backups: changing it makes older events fail `ledger:verify`.
3. Build the web app with the API URL and no demo data: `VITE_API_URL=https://<domain> pnpm --filter @tanaad/web build` (output `apps/web/dist`, mounted into Caddy).
4. First certificates: uncomment `acme_ca` in `infra/caddy/Caddyfile` to use Let's Encrypt staging while testing, then comment it out again.

## Deploy
```
docker compose -f infra/docker-compose.staging.yml --env-file infra/staging/staging.env up -d --build
docker compose -f infra/docker-compose.staging.yml exec api php artisan migrate --force
docker compose -f infra/docker-compose.staging.yml exec api php artisan config:cache route:cache
infra/scripts/smoke.sh https://<domain> <username> <pin>
```
Never run `migrate:fresh` against staging. Demo seeders (`DemoUsersSeeder`) are for local use; create real users through the admin flow.
Create the MinIO buckets once (`tanaad-media`, `tanaad-hr`, `tanaad-backups`) with `mc`.

## Proxy, headers and smoke test
- Request path: Caddy (TLS, security headers) -> nginx (internal only) -> php-fpm. `TRUSTED_PROXIES=*` in `api.env` makes Laravel
  believe the `X-Forwarded-For/Proto/Host` Caddy sets, so login/PIN throttling keys on the real client IP and URLs are https.
  Safe only because nginx publishes no port; never publish nginx or set `TRUSTED_PROXIES` on a host reachable directly.
- Caddy reads only `infra/staging/caddy.env` (`TANAAD_DOMAIN`, `ACME_EMAIL`; copy `caddy.env.example`), never the secrets in `staging.env`.
- Caddy sends HSTS, `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy` and a CSP for the SPA (API same-origin;
  images also from the MinIO signed-URL origin: set `MEDIA_ORIGIN` in `caddy.env`).
- Smoke: `SMOKE_PIN=<pin> infra/scripts/smoke.sh ...` (the PIN is read from the environment, never argv, so it stays out of `ps` and history).

## Health and monitoring
- `/up` (Laravel) through Caddy; every container has a compose healthcheck (`docker compose ps`).
- `GET /api/v1/devices/heartbeats?stale=1` (group admin) lists tablets that went quiet; tablets POST `/api/v1/devices/heartbeat`.
- `ledger:verify` runs nightly at 03:30 Africa/Kampala from the scheduler; a failure exits non-zero and logs `ledger:verify FAILED` at critical level. Make that line page someone.
  It cannot detect a deleted row or tail (there is no hash chain), only seq gaps, which also appear after rolled-back transactions.
- Sentry: not installed. To enable: `composer require sentry/sentry-laravel`, set `SENTRY_LARAVEL_DSN` in `api.env`. `bootstrap/app.php` wires it only when the package exists and the DSN is set; `config/sentry.php` sets `send_default_pii=false`.

## Backups and restore drill
- Nightly (cron on the host, after 03:30): `ENV_FILE=infra/staging/api.env MINIO_ALIAS=<mc alias> infra/scripts/backup.sh` (dated `.sql.gz` + sha256, 14-day local retention, copy to MinIO). Keep a copy on a different machine.
  From the host, `DB_HOST` must reach MySQL (publish 127.0.0.1:3306 temporarily or run the script in a container on the compose network).
- Monthly drill: `infra/scripts/restore-drill.sh tanaad_restore_drill` restores the newest dump into that scratch database, runs `ledger:verify --replay` on it and compares row counts. It refuses `tanaad` and the live database, and `ALLOW_DROP=1` is needed to recreate an existing scratch DB.

## Rollback
Keep the previous image/commit. Code rollback: check out the previous release, `up -d --build`. A migration rollback needs a reviewed `down()` and a fresh backup first; the ledger is append-only, so data fixes are corrective events, not edits.

## Known gaps
No CI, no baked production image (the compose bind-mounts `apps/api`), no log shipping, single host, MinIO on the same disk as MySQL.
