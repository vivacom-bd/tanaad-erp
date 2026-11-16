# ADR-004: One write path; offline events are proposed commands

**Status:** Accepted, 2026-11-09.

## Context

Clients write in two ways: online, through REST endpoints, and offline, by queueing events in a local outbox and posting them later to `POST /events`. The PDD requires the two paths to converge on the same ledger and for rejections to be explicit, never silent.

## Decision

1. **REST write endpoints build a Command** and run it through the `CommandBus`. Controllers never write to tables.
2. **`POST /api/v1/events` maps each envelope to the same Command** the REST endpoint would build. Validation, authorisation and invariants are therefore identical. The envelope's `id` becomes the event id; `occurred_at` is kept from the client; the actor comes from the session.
3. **Per-event transactions, in client order.** One rejection never fails the batch. Results are `accepted` (with `seq` and any `assigned` numbers), `duplicate` (id already stored) or `rejected` (closed code list + human reason).
4. **Number series are assigned at ingestion.** Offline clients show a provisional reference; the response returns the final number under `assigned`.
5. **Idempotency everywhere.** REST writes accept an `Idempotency-Key` header that becomes the event id, so a retried request is a `duplicate`, not a second fact.
6. **Rejected events are not stored.** The client keeps the record locally with the reason for the user to redo or discard.

Rejection codes: `unknown_type`, `invalid_payload`, `forbidden`, `day_locked`, `not_found`, `state_conflict`, `duplicate_fact`, `stale_config`.

## Alternatives

- **Store every offline event, flag conflicts afterwards.** Keeps a complete record of what devices sent but puts invalid facts in the ledger and pushes every conflict to a human. Rejected; the ledger holds accepted facts only, and the device keeps its own record of rejections.
- **Last-writer-wins merge.** Rejected by the PDD's conflict policy.

## Consequences

- One set of handlers to test; the ingestion tests only need to cover envelope mapping, ordering, duplicates and assigned numbers.
- The frontend outbox must be able to show and resolve rejections, which the prototype does not yet do (see ADR-011).
