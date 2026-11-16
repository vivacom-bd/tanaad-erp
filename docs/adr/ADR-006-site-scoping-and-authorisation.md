# ADR-006: Site scoping and authorisation in the application layer

**Status:** Accepted, 2026-11-09.

## Context

Ten roles across three sites plus a group layer. Group roles (`ceo`, `group_admin`) see every site; site roles see one. Separation of duties is a stated requirement (loader ≠ variance approver, cashier ≠ day reopener, mechanic ≠ spares receiver). ADR-001 mentioned PostgreSQL row-level security (RLS).

## Decision

1. **Roles per site** in `user_site_roles (user_id, site_id nullable, role)`. A group role has `site_id = null`.
2. **One authorisation policy class per module** implementing `allows(User $user, Command $command): bool`, run by the `CommandBus` before validation. It checks role × site, and separation of duties by comparing the actor with ids on the command or on the referenced projection row.
3. **Read scoping** through a `SiteScope` applied to every projection query from the token's allowed sites. Route middleware checks the role matrix for read endpoints.
4. **Device sessions:** devices are registered by Group Admin and hold a device token; users unlock with a PIN and receive a short-lived token bound to the device. Token expiry per role is config.
5. **PostgreSQL RLS is deferred.** Every table carries `site_id`, so RLS can be switched on later without schema changes. It becomes necessary only when something other than the API (a BI tool, a reporting user) connects to the database.

## Alternatives

- **RLS from day one.** Strong defence in depth but adds a per-request `SET` of the session's sites and complicates tests and rebuilds. Rejected for the first build.
- **A permissions package (spatie/laravel-permission) with a team dimension.** Workable, but the role set is fixed and small; a table and a policy class per module is less machinery.

## Consequences

- The role × command matrix is a Pest dataset test; adding a command without a policy row fails the build.
- A later RLS rollout is a migration plus middleware, not a redesign.
