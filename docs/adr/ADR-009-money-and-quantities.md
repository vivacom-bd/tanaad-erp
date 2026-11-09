# ADR-009: Money, weights, percentages and rounding

**Status:** Accepted, 2026-11-09.

## Context

UGX and USD side by side, a daily reference rate, kilograms to three decimals, bundle arithmetic and tolerances. The prototype's arithmetic uses JavaScript numbers with `round2`/`round3`; the server must produce identical results.

## Decision

1. **Database:** amounts `numeric(16,2)` with a `currency` column; weights `numeric(12,3)`; percentages `numeric(7,3)`. No floating-point columns.
2. **PHP:** `brick/math` `BigDecimal` for all money and weight arithmetic; a `Money` value object (`amount`, `currency`) and a `Kg` value object in `Shared\Money`.
3. **Rounding:** half-up to 2 decimals for money and 3 for kilograms, applied exactly where the TypeScript applies `round2`/`round3`. The fixtures in `contracts/fixtures` pin the edge cases.
4. **JSON:** amounts and weights are emitted as JSON numbers, not strings, to match the TypeScript types. Values never exceed 2^53, so this is lossless.
5. **FX:** `fx_rates (day, base, quote, rate)`. Every event that converts currency carries the rate it used in its payload; projections and costing reuse that rate, never the current one.
6. **No currency arithmetic across currencies** without an explicit rate in the command.

## Consequences

- Costing and P&L are reproducible for any past period.
- Any new arithmetic function is added with a fixture first, then both implementations.
