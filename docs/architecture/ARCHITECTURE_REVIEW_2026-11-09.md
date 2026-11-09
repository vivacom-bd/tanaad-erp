# Backend architecture review — 9 November 2026

## Scope and verdict

Reviewed `docs/architecture/BACKEND_ARCHITECTURE.md`, ADR-001 through ADR-011, `docs/plans/BACKEND_DELIVERY_PLAN.md`, the draft Project Definition Document, and the current Zustand store/domain types. This is a design review; the backend described here is not implemented yet. The PDD itself says scope is not sized until client questions are answered in writing (§20).

**Verdict:** the broad direction is sound: a modular monolith, one API, explicit offline writes, rebuildable read models, shared arithmetic fixtures, and a deliberate frontend adapter all fit the product better than microservices or a rewrite. The design is not ready to implement exactly as written. Resolve the P0 findings below before implementing the sync/ledger foundation; resolve the P1 findings before production data or personal media is used.

## Findings

### P0 — The global sequence is not a safe sync high-water mark

`BACKEND_ARCHITECTURE.md` assigns a global `seq` to appended events (§6.1, lines 93–133), returns `snapshot_seq`, and has clients advance a per-projection cursor using `seq > since` (§8.2, lines 251–260). It does not define how `seq` is allocated or guarantee that it follows commit order. If it uses a normal PostgreSQL sequence, values are allocated atomically but are not transactional or ordered by commit: a transaction can reserve 101 and pause; another can reserve 102 and commit; a client sees 102 and advances its cursor; the first transaction can then commit 101. That event is now permanently behind the client's cursor. The API's per-event ingestion transactions do not prevent concurrent requests from producing this interleaving.

**Required decision:** define a cursor that cannot skip late commits. Options include a transactional commit-ordered cursor (partitioned by independent stream if needed), or a database change stream/CDC position. A simple `nextval()`-style global sequence is not sufficient. Specify how pagination pins a stable upper bound and how a client recovers from cursor expiry/full resnapshot. PostgreSQL documents that sequence changes are visible outside transaction rollback; see [sequence functions](https://www.postgresql.org/docs/18/functions-sequence.html) and [transaction isolation](https://www.postgresql.org/docs/18/transaction-iso.html).

### P0 — Offline event attribution is underspecified and can name the wrong person

ADR-004 says the server takes the actor from the authenticated session, never from the offline envelope. ADR-011's proposed envelope contains type, payload, event id, occurrence time and device id, but no durable actor/session identity. The PDD says each event is created with its user and device (§11). On a shared tablet, user A can create events offline, sign out, and user B can sign in before sync. If B's session flushes the outbox, the server attributes A's work to B; if the server refuses to trust the recorded actor, it cannot reliably preserve the original author.

**Required decision:** bind a locally queued event to the authenticated actor and role/site context at creation, and define how the server verifies that binding after a user signs out, loses access, or has credentials revoked. Avoid accepting an arbitrary `actor_id` as proof. Define shared-device unlock, offline session expiry, event signing/attestation if required, logout/outbox handling, and the user-visible resolution for a now-unauthorised event. This is both an audit-integrity and floor-usability requirement.

### P0 — Database transactions do not by themselves protect business invariants

The write flow says handlers check invariants against projections inside a transaction and treats those reads as safe (§6.3, lines 154–179; ADR-003). Under ordinary PostgreSQL `READ COMMITTED`, two concurrent transactions can both read the same valid state and both write a result that violates a shared invariant. Examples include two orders both consuming the same remaining credit, two scans assigning one label to separate dispatches, or duplicate close/reopen operations. A transaction makes each request atomic; it does not automatically serialize these decisions.

**Required decision:** for each invariant, state the concurrency control: a unique constraint, row/advisory lock, compare-and-swap version, or serializable transaction with retry. Add concurrency tests for the invariants that protect cash, stock/labels, document numbering and credit. Do not rely only on handler-level projection checks.

### P1 — “Exactly once” external side effects are not guaranteed by `reactor_runs`

The architecture says reactors run after commit and that `reactor_runs` ensures a retry never repeats a message (§6.3, lines 173–179). A crash after the provider accepts a message but before `reactor_runs` is recorded can cause a duplicate on retry. A crash after database commit but before the queue dispatch can instead lose the job entirely. Laravel's `after_commit` option prevents a worker from seeing uncommitted data; it does not make the database commit and external queue publish atomic. See [Laravel queue transaction behavior](https://laravel.com/docs/13.x/queues#jobs-and-database-transactions).

**Required decision:** write a durable reactor/outbox row in the same database transaction as the business event, then have a worker claim and publish it. Model provider outcomes as at-least-once/unknown unless a provider supports an idempotency key and reconciliation. Keep delivery status and retries visible; do not promise universal exactly-once messaging.

### P1 — Decimal values sent as JSON numbers are not “lossless” in JavaScript

ADR-009 says amounts and weights are JSON numbers and values below 2^53 are lossless (line 14). The safe-integer guarantee applies to integers; JavaScript `Number` is IEEE-754 binary floating point, so common decimal fractions are not exactly represented. Matching rounded outputs in a few fixtures does not guarantee equivalence across all sums, repeated conversions and boundary comparisons. See [MDN's JavaScript number guide](https://developer.mozilla.org/en-US/docs/Web/JavaScript/Guide/Numbers_and_strings).

**Required decision:** choose an exact wire and calculation representation before generating the API contract. Common options are decimal strings with a decimal arithmetic library, or integer minor units for money plus scaled integer units for mass, with explicit scale and currency. Test client/server results with property and boundary cases, including totals and FX. Keep display formatting separate from arithmetic.

### P1 — Privacy and data retention are not sufficiently decided for ID/face media

The architecture puts ID and face images in an HR-only bucket and leaves retention to later agreement (§15, §21), while the PDD identifies these as sensitive personal data and makes DPIA, retention, and compliant hosting pre-go-live requirements (§14; §20 question 22). The scheduled purge removes only unreferenced uploads; an image referenced by a permanent event has no defined expiry/deletion path. “HR-only” does not state the exact least-privilege roles, access audit, legal basis/notice, retention period, subject request handling, or how backups and event payloads behave on deletion.

**Required decision:** make image capture optional unless the client confirms it is necessary for a task; define purpose, lawful basis/notice, role matrix, access log, retention/deletion and backup expiry, and subject request flow before collecting real images. Prefer storing media references separately from immutable business facts so lawful deletion can remove media without rewriting the audit ledger. The client must confirm hosting and compliance assumptions; the PDD is explicitly still a draft.

### P1 — Current event store and frontend actions do not match the planned event contract yet

`apps/web/src/data/store.ts` currently mutates projections directly, then emits PascalCase event names such as `PersonRegistered` and `AttendanceCorrected`; its `flushOutbox` only marks local events synced. ADR-011 instead proposes namespaced versioned envelopes, a separate Dexie outbox, remote snapshots and rejection handling without screen changes. These are materially different semantics, not just transport plumbing. For example, `recordProduction` replaces an existing projection row for the same shift/machine/stage and emits `ProductionEntryRecorded`; the production API must decide whether that is a correction with a reason, a new fact, or an idempotent update. Similar behavior exists in attendance and settings.

**Required decision:** write an explicit action-to-command/event mapping for the current store, including correction semantics, actor/site capture, optimistic local updates, server-assigned ids/numbers, rejection/undo behavior and merge rules. Implement one vertical slice and prove demo/API adapters produce the same visible result before promising “screens do not change.” See [current store](../../apps/web/src/data/store.ts), [ADR-004](../adr/ADR-004-single-write-path-and-offline-ingestion.md), and [ADR-011](../adr/ADR-011-frontend-integration.md).

### P2 — Monthly partitioning later conflicts with global event-id uniqueness as written

The event table is planned for monthly partitioning after 20M rows, with a claim that the schema does not change (§6.1, line 133). PostgreSQL requires a unique/primary key on a partitioned table to include the partition key. If events are partitioned by month, `PRIMARY KEY (id)` alone cannot provide global uniqueness across partitions, yet idempotent ingestion depends on a globally unique event id.

**Required decision:** either defer partitioning until measured need and design it as a migration, or choose a partition-compatible key plus a small global id registry/deduplication table now. Keep idempotency guarantees explicit. See [PostgreSQL partitioning limitations](https://www.postgresql.org/docs/18/ddl-partitioning.html#DDL-PARTITIONING-LIMITATIONS).

### P2 — The single-developer delivery plan assumes too much parallel certainty

The plan allocates one senior developer about nine working weeks for a custom ledger kernel, 14 modules, API contracts, CI, full frontend integration, event-imported seed data, privacy/media, costing and staging; module tasks include a full test and adapter mapping each time. At the same time the PDD says scope is not sized until client confirmation, and asks about the production stages, attendance, payment, connectivity, data hosting and privacy that shape these commands. AI assistance may speed implementation but does not remove review, integration, data migration, device/browser testing, security work or client sign-off.

**Recommendation:** treat the dates as a conditional target, not a commitment. Make Phase 0 prove the highest-risk vertical slice (auth on a shared offline device → durable actor-bound event → concurrent-safe command → projection sync without missed cursors → recovery/rejection UI). Gate later module dates on client confirmation and evidence from that slice. Keep business policy values configurable, but do not assume configuration alone resolves an unknown workflow or legal requirement. The PDD explicitly says nothing is sized until §20 questions are answered.

## Strong decisions to retain

- Keep a Laravel modular monolith; the proposed scale does not justify microservices.
- Keep business arithmetic in a shared, fixture-tested domain contract and separate device/messaging interfaces from workflows.
- Keep reads in purpose-built projections and commands as the server write boundary. Rebuildable projections are a reasonable fit for reports and offline use.
- Keep client-assigned stable IDs for offline-created facts, with server authority for final document numbers.
- Keep demo mode isolated from production API mode and add visible handling for rejected/conflicted writes.
- Avoid adding WebSockets, an edge server, Android hardware integrations, EFRIS and disbursements before the operational need is proven.

## Decisions to settle before Phase 0 starts

1. Commit-ordered sync cursor and pagination/recovery semantics.
2. Trusted actor attribution for offline events on shared devices.
3. Concurrency protection for each state invariant.
4. Transactional reactor outbox and truthful delivery guarantees.
5. Exact decimal representation across API and TypeScript.
6. Current-store action-to-command mapping and correction/rejection behavior.
7. Privacy/data-lifecycle decisions for any personal media.
8. Which PDD constraints and dates are client-approved vs provisional estimates.

