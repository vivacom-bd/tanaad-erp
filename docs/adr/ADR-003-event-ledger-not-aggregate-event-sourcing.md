# ADR-003: An event ledger with projections, not aggregate-root event sourcing

**Status:** Accepted, 2026-11-09. Supersedes the `spatie/laravel-event-sourcing` choice in ADR-001.

## Context

The product thesis requires an append-only record of every business fact, offline-first clients that produce events locally, rebuildable read models and an audit feed. ADR-001 proposed full event sourcing with `spatie/laravel-event-sourcing` (aggregate roots reconstituted from events, projectors, reactors).

Full aggregate event sourcing adds: aggregate design per entity, snapshotting, event upcasting, aggregate-level concurrency, and a package whose stored-event schema differs from ours (client-generated ids, `site_id`, `device_id`, `corrects_event_id`, `occurred_at` vs `received_at`). The team is new to it and the target is two months.

## Decision

Build a thin **event ledger kernel** in `Shared\Ledger` (about ten classes) and no aggregate roots:

- Every write is a **Command**. A **Handler** checks invariants against the current **projections** (which are transactionally consistent, so this is safe) and returns **Events**.
- The `CommandBus` runs, in one database transaction: authorise → validate → handle → append events → apply **Projectors** synchronously → run **Policies** (deterministic follow-up commands) → commit → dispatch **Reactors** (queued side effects).
- Projections are rebuildable with `ledger:rebuild`; a nightly job verifies a rebuild matches live rows.
- Corrections, reopenings and overrides are events that reference the original with a reason.

`spatie/laravel-event-sourcing` is not used.

## Alternatives

- **Full event sourcing with the Spatie package.** Strongest theoretical model; highest cost and the one most likely to slow a two-month build. The benefits that matter here (append-only history, rebuild, audit, offline ingestion) are all delivered by the ledger kernel.
- **Plain CRUD with an audit log.** Fastest to start; fails the thesis. Offline events become "requests to update rows", conflicts become overwrites, and the audit log drifts from the data. Rejected.
- **Spatie package in projector-only mode.** Possible but means bending its stored-event model and carrying a dependency for ~20% of its features. Rejected in favour of owning the ten classes.

## Consequences

- Invariants are checked against projections, so projectors must be applied synchronously in the writing transaction (they are).
- There is no aggregate-level optimistic concurrency; conflicts are business rules (`duplicate_fact`, `state_conflict`, `day_locked`) decided by handlers, which matches the PDD's conflict policy.
- If some entity ever needs a reconstituted aggregate (unlikely at this scale), it can be added for that entity without changing the ledger.
