# ADR-007: Generated OpenAPI contract, generated TypeScript client, shared arithmetic fixtures

**Status:** Accepted, 2026-11-09. Confirms ADR-001's contract approach with concrete tooling.

## Context

Two implementations of the domain exist: TypeScript in the prototype (types, selectors, arithmetic) and PHP on the server. Drift between them is the main long-term risk.

## Decision

1. **OpenAPI 3.1 is generated from the Laravel code** with `dedoc/scramble` on every CI run and committed to `contracts/openapi.yaml`. `oasdiff` compares against the previous commit; a breaking change fails CI unless the PR carries the `api-breaking` label.
2. **The TypeScript API types are generated** from the contract with `openapi-typescript` into `packages/domain/src/api.gen.ts`. A CI check fails if the committed file is stale. Hand-edits are forbidden.
3. **Event payloads have JSON Schemas** in `contracts/events/<type>.v<version>.json`. The PHP event classes validate against them in tests; the frontend outbox validates envelopes before queueing them.
4. **Arithmetic fixtures** live in `contracts/fixtures/<function>.json` (inputs and expected outputs, including rounding edge cases). Vitest and Pest both load every fixture file. Adding a fixture without both suites passing fails CI.
5. The existing hand-written `packages/domain/src/types.ts` stays until the generated types cover it, then screens migrate type by type.

## Alternatives

- **Hand-written OpenAPI first (design-first).** Cleaner spec, but two sources of truth for a small team. Rejected; the Laravel request and resource classes are the design.
- **Shared TypeScript domain via Node on the server.** Rejected in ADR-001.

## Consequences

- API changes are made in Laravel and flow to the client through the contract; a frontend change that needs new data starts with a backend PR.
- Fixtures become the specification of the arithmetic; the client's `arithmetic.test.ts` is rewritten to load them.
