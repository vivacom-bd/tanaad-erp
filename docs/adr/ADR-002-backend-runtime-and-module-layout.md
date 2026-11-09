# ADR-002: Backend runtime and module layout

**Status:** Accepted, 2026-11-09. Revises the backend row of ADR-001.
**Deciders:** Ziaur Rahman (solution architect)

## Context

ADR-001 chose Laravel 13, API-only, as a modular monolith. It specified PHP 8.4 and left the module mechanism open. Development starts now, on a greenfield codebase, with a Laravel-strong team and AI assistance. Laravel 13 (released 17 March 2026) supports PHP 8.3, 8.4 and 8.5.

## Decision

1. **Laravel 13 on PHP 8.5.** `composer.json` requires `php: ^8.5`. There is no compatibility promise for 8.3 or 8.4: the application runs only in its own containers (`php:8.5-fpm`), so the host version is irrelevant, and 8.5 features (pipe operator, `#[\NoDiscard]`, `array_first`/`array_last`, clone-with) are used freely.
2. **API only.** No Blade, no Inertia, no sessions or CSRF for the API. The React prototype is the only client.
3. **Modules as PSR-4 directories, no module package.** `modules/<Name>` under namespace `Tanaad\<Name>`, each with its own `ServiceProvider` that loads migrations, routes and bindings. No `nwidart/laravel-modules` or similar; a directory, a provider and an architecture test are enough.
4. **Module list.** `Shared`, `Identity`, `Config`, `People`, `Cash`, `Production`, `Stock`, `Dispatch`, `Orders`, `Costing`, `Group`, `Media`, `Messaging`, `Documents`. The dependency rule (only `Shared` and other modules' `Events`) is enforced by `pest-plugin-arch`.
5. **Tooling.** Pint, Larastan level 8, Pest 4, `declare(strict_types=1)` everywhere, `final readonly` for commands and events.

## Alternatives

- **PHP 8.3 with a 8.5 CI job.** Keeps the code runnable on older hosts but forbids 8.5 syntax. Rejected: nothing runs outside our containers.
- **Laravel Modules package.** Adds generators and a registry we do not need; its conventions (per-module `composer.json`, resources, views) are shaped for Blade apps. Rejected.
- **Separate services per module.** Operationally expensive for a three-site system with one team. Rejected; the module boundaries make a later split possible if ever needed.

## Consequences

- One upgrade path: PHP minor versions move with Laravel's supported range.
- Every module looks the same (template in `BACKEND_ARCHITECTURE.md` §18.3), which is what makes AI-assisted generation reliable.
- Shared infrastructure (ledger, calendar, money) lives in `Shared` and is the only thing modules may import from each other besides event classes.
