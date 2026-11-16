# ADR-012: MySQL 8.4 replaces PostgreSQL 18

Status: accepted, 9 Nov 2026. Supersedes the database row of ADR-001.

## Decision
The system runs on MySQL 8.4 (InnoDB, utf8mb4, session time zone UTC). The owner chose MySQL over PostgreSQL.

## What changed in the schema
- `events.seq` is an auto-increment primary key. `events.id` stays the client UUID and is unique.
- The append-only guard is two `BEFORE UPDATE` / `BEFORE DELETE` triggers that `SIGNAL SQLSTATE '45000'`. The container runs with `log_bin_trust_function_creators=1` so a non-root user may create them.
- `jsonb` became `json`; `timestamptz` became `datetime(6)` stored in UTC. UUIDs are `char(36)`.
- Long text columns with a default became `varchar`, because MySQL forbids defaults on `text`.
- Monthly partitioning of `events` stays deferred (see the architecture review); MySQL cannot partition a table that has foreign keys, so it would need the self-reference on `corrects_event_id` removed first.

## Consequences
- DDL commits implicitly. Test code that creates tables must do it outside the per-test transaction.
- JSON key order and number formatting are normalised by MySQL; tests compare decoded values, not text.
- The local database is `tanaad-mysql` on host port 3340. Redis stays on 6390.
