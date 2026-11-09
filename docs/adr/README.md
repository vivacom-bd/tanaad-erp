# Architecture decision records

| ADR | Title | Status |
|---|---|---|
| [001](ADR-001-technology-stack.md) | Technology stack | Accepted 2026-10-10; backend rows revised by 002 and 003 |
| [002](ADR-002-backend-runtime-and-module-layout.md) | Backend runtime (Laravel 13, PHP 8.5) and module layout | Accepted 2026-11-09 |
| [003](ADR-003-event-ledger-not-aggregate-event-sourcing.md) | Event ledger with projections, not aggregate event sourcing | Accepted 2026-11-09 |
| [004](ADR-004-single-write-path-and-offline-ingestion.md) | One write path; offline events are proposed commands | Accepted 2026-11-09 |
| [005](ADR-005-projections-policies-reactors.md) | Projections synchronous, policies deterministic, reactors queued | Accepted 2026-11-09 |
| [006](ADR-006-site-scoping-and-authorisation.md) | Site scoping and authorisation in the application layer | Accepted 2026-11-09 |
| [007](ADR-007-api-contract-and-shared-fixtures.md) | Generated OpenAPI, generated TS client, shared fixtures | Accepted 2026-11-09 |
| [008](ADR-008-identifiers-time-and-numbering.md) | Identifiers, time, business day, numbering | Accepted 2026-11-09 |
| [009](ADR-009-money-and-quantities.md) | Money, weights, rounding | Accepted 2026-11-09 |
| [010](ADR-010-repository-structure.md) | Repository structure | Accepted 2026-11-09, executed in Phase 0 |
| [011](ADR-011-frontend-integration.md) | Prototype becomes the client through an adapter | Accepted 2026-11-09 |

Format: context, decision, alternatives, consequences. One decision per file. A superseded ADR keeps its file with its status updated.
