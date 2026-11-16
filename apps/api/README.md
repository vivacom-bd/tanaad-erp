<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Operations (Phase 4 hardening)
- Rate limits (`config/tanaad.php` `rate_limits`): named limiters `login`, `pin-login`, `api` (per user, default 1200/min: a CEO first load is ~200 requests; prefer the batch snapshot endpoint), `ingest` (per device). 429 `rate_limited`.
- Datetimes: every JSON datetime leaves the API as ISO-8601 UTC with `Z` (`2026-11-10T07:00:00Z`, `.mmm` only when non-zero), normalised once in `Tanaad\Shared\Http\UtcTimestamps` (api middleware). The DB stores UTC: `CommandContext`/`Event` normalise `occurred_at` to UTC (seeders used to pass Kampala-offset instants, which the DB layer stored as Kampala wall-clock). Data seeded before this fix needs a reseed.
- Batch snapshots: `GET /api/v1/sites/{site}/snapshots?tables=a,b,c&since=<seq>&cursors=a:12,b:40&limit=<n>` -> `{snapshot_seq, results: {a: {status: 200, data, snapshot_seq, has_more}, b: {status: 403|404, code, detail}}}`; each table role-scoped exactly like `GET .../snapshots/{projection}` (kept). Max 80 tables.
- `GET /api/v1/directory`: `{data: [{id, name, code, active, roles: [{role, siteId, siteCode}]}]}` for users sharing a site with the caller plus group-role users (group callers: everyone); roles limited to the caller's sites + group roles; `Cache-Control: private, max-age=300`, `ETag` / `If-None-Match` -> 304.
- `GET /api/v1/alerts?limit=50` (max 100): the bell. `{data: [{id, siteId, siteCode, kind, title, detail, status, active, subjectRef, raisedAt, decidedAt}], activeCount}`: active (open|held) critical exceptions plus gate holds (`gate_variance`) of the last 24 h in any status, newest first; gate holds use the policy's `facts.alert` title/detail. CEO/group admin: all sites; site manager: own sites; others 403.
- Media disk: `MEDIA_DISK=s3` (MinIO/S3, default) or `local` (`storage/app/{media,hr}`; default under testing). Local has no signed URLs, so `GET /media/{hash}` streams the file. Storage unreachable -> 503 `storage_unavailable` "File storage is unavailable".
- Token lifetime by role (`auth.token_ttl_minutes`), enforced at issue and on every request; expired -> 401 `token_expired`.
- `php artisan ledger:verify [--replay] [--strict-gaps]`: duplicate ids, received_at order, per-event HMAC checksum, optional replay-equals-projection (rolled back). Scheduled nightly.
- Device health: `POST /api/v1/devices/heartbeat`, `GET /api/v1/devices/heartbeats?stale=1`.
- Sentry seam: `config/sentry.php`, inert until the package is installed and `SENTRY_LARAVEL_DSN` is set.
