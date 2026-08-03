# Sprint — Discounts

## Goal

The oldest open decision closes: a **discount catalogue** (fixed percent menu +
tiered free-time promos) that **compiles at signing into contract-scoped item
versions** — after which no discount logic exists anywhere in billing, reporting, or
settlement, because the rate schedule *is* the discount. Both real client schemes ship:
the 5/10/20/30/50% menu, and "stay 2 months → first 4 weeks free."

## Decisions (ratified 2026-08-02, record in `10-open-decisions.md` as D-DISC)

1. **No clawback in v1.** Early leavers keep their free weeks; the commitment is
   honored on trust. Clawback-as-vacate-settlement-charge is the recorded follow-up.
2. **Zero-total periods: charge yes, invoice no.** The €0 charge writes (ledger
   continuity, cursor advances honestly); invoice issuance skips zero-total periods
   (fiscal noise, worse once Verifactu unparks). Gestor-flippable flag.
3. **Percent tracks rate changes** — a scheduled increase on a discounted contract
   recomputes `new list × (1−p)`. **Plus a remove action**: operator ends a discount
   → version back to list at the next period boundary, Tier-3 with reason.
4. **Unit items only** in v1 (insurance untouched — IPT territory). The scope column
   exists for later widening.

## Why the compiler shape (one paragraph for the record)

Item versioning (S02) + the recurring job reading `itemsOn` (S05) means any priced
timeline is already billable, previewable, settleable, and reportable. A discount
that *compiles to versions at signing* therefore costs zero changes in `BillingMath`,
`RecurringBilling`, vacate, rent roll, or economic occupancy — the
`s02_payoff_rate_change_straddle` test already proves the exact mechanism a free
period ending exercises. Discounts exist at compile time and in provenance; billing
never hears the word.

## New invariant (append to `09`)

> **Discounts compile at signing; billing never interprets them.** A discount
> materializes as contract-scoped price versions (+ linked provenance) inside the
> signing/convert transaction. No code in billing runs, settlements, or reports may
> branch on discount presence. Removal and rate-change recompute emit versions
> through the same rate-change path.

## Exit criteria

- [ ] Both client schemes end-to-end: a 20%-menu contract bills 80% of list forever
      and survives a rate change at 80% of the *new* list; a 2-month free-time
      signing bills €0, €0(? per cadence), then list — previews, ledger, invoices
      (skipping €0), rent roll, and economic occupancy all consistent by
      construction.
- [ ] The public offer page says "First 4 weeks free" where earned; the operator saw
      the resolved tier before sending.
- [ ] Remove button versions back to list next boundary, audited with reason.
- [ ] Grep: zero discount references outside catalogue/compiler/surfaces.

## Task order

| # | Task | Est. |
|---|---|---|
| 00 | [Catalogue & governance](./00-catalogue.md) | 1 day |
| 01 | [The compiler & signing integration](./01-compiler.md) | 1.5 days |
| 02 | [Lifecycle: rate changes, removal, edges](./02-lifecycle.md) | 1 day |
| 03 | [Surfaces](./03-surfaces.md) | 1 day |

## Risks

**Tier resolution needs a commitment source.** Free-time resolves from the deal's
declared stay length (`stay_length` × `period` — mockup-old fields, finally
load-bearing). Walk-ins without a deal enter it at create. A missing/blank
commitment resolves tier 0 = no free time, *shown* as such — never a silent default
to the best tier.

**Granularity honesty.** Free weeks that don't divide the org cadence compile to
odd multipliers (mathematically fine — bcmath, multiply-before-divide; commercially
weird). Catalogue validation warns on misaligned tiers at save; the compiler never
guesses.
