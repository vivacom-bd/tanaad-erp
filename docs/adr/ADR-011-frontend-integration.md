# ADR-011: The prototype becomes the client through an adapter, not a rewrite

**Status:** Accepted, 2026-11-09.

## Context

The React prototype has 50+ screens reading from a Zustand store that is seeded locally and written through 55 actions. Its `ARCHITECTURE.md` already describes the production swap: actions enqueue events, projections come from the API. The screens must not change.

## Decision

Add `apps/web/src/data/api/` behind `VITE_API_URL`, leaving demo mode (`VITE_DEMO=1`) intact:

1. **Auth.** `POST /auth/login` and `POST /auth/pin` replace the Switcher's local sign-in. The session stores the token, allowed sites and roles; `portalsFor` reads from it.
2. **Bootstrap.** `GET /me`, `GET /sites/{site}/config`, then every `snapshots/{projection}?since=0` hydrate the store in place of `buildSeed()`. Site config files become seeders for the API and are deleted from the client once the API serves them.
3. **Outbox.** Each store action builds an `EventEnvelope` (type, payload, UUIDv7 id, `occurred_at`, device id) and writes it to a Dexie `outbox` table before updating the local projection. `flushOutbox` posts batches to `/events` in order, applies `assigned` numbers, marks `accepted`/`duplicate`, and moves `rejected` items to a visible "needs attention" list with the server's reason.
4. **Delta.** After each flush and on a timer for monitor screens, pull `snapshots/*?since=` and merge rows into the store by id; tombstones (`deleted_at`) remove rows.
5. **Generated types** from `contracts/openapi.yaml` replace hand-written types progressively (ADR-007).
6. **Devices** (scale, printer, camera, messaging) keep their interfaces; simulators remain until Capacitor plugins exist.

## Alternatives

- **Rewrite screens on TanStack Query against REST.** Loses the offline-first store the floor needs and the two months of screen work. Rejected.
- **PowerSync for the read path now.** Evaluated later per ADR-001; the custom delta is a few hundred lines.

## Consequences

- The adapter is built module by module in the same phase as the backend module, so each phase ends with the real screens working against the real API.
- Rejection handling is new UI (small): a list, a reason, redo or discard.
