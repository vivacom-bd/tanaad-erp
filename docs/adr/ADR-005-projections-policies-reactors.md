# ADR-005: Projections are synchronous; policies are deterministic; reactors are queued

**Status:** Accepted, 2026-11-09.

## Context

Three different kinds of work follow an event: updating read models, applying business rules that produce further facts (an over-limit order raises an exception), and side effects on the outside world (send a WhatsApp message). Mixing them makes rebuilds dangerous and tests slow.

## Decision

| Kind | Runs | Must be | Example |
|---|---|---|---|
| **Projector** | Synchronously, inside the writing transaction | Deterministic, idempotent per event, no I/O beyond its own tables | `CashDayProjector` updates `cash_days.expected_closing` |
| **Policy** | Synchronously, inside the same transaction, after projectors | Deterministic; returns Commands, never performs I/O | `CreditLimitPolicy` turns `orders.order_created` into `RaiseException` |
| **Reactor** | Asynchronously on Horizon after commit | Idempotent by `(reactor, event_id)`; may call external services | `CeoAlertReactor` sends a critical exception to WhatsApp |

- `ledger:rebuild` runs projectors only. Policies are skipped because their resulting events are already in the ledger; reactors are skipped so nothing is re-sent.
- Policy recursion is bounded (depth 5) and logged; a policy that would loop is a bug, not a feature.
- A projector that needs data from another module reads that module's **events**, not its tables, and keeps its own copy of what it needs.

## Alternatives

- **Async projections.** Allows horizontal scaling of projection work but makes invariant checks read stale state and complicates the REST response. At ~5,000 events/day there is nothing to scale. Rejected.
- **Policies as reactors.** Simpler mentally, but exceptions would appear seconds after the event and would not survive a rebuild consistently. Rejected.

## Consequences

- Reads immediately after a write are consistent, which the REST responses and the ingestion `snapshot_seq` rely on.
- Projectors and policies are unit-testable without queues; reactors are tested with the `log` messenger driver.
