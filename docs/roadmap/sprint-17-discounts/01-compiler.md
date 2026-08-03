# DISC-01 — The compiler & signing integration

## Context

The heart: `DiscountCompiler` turns a catalogue row + a commitment into the item-
version sequence, inside the existing signing/convert transaction, shared verbatim
with convert-preview. After this task, a discounted contract is indistinguishable
from a hand-scheduled one — which is the entire design.

## Scope

**In:** the compiler (both kinds), tier resolution from commitment, integration into
contract store / reservation convert / convert-preview, provenance linkage, the
zero-total invoice skip, unit-items-only enforcement. **Out:** removal & rate-change
recompute (02), attachment UX (03).

## Behaviour

**Inputs.** `DiscountCompiler::compile(Discount $d, CompileContext $ctx):
VersionPlan` — ctx: the unit item's list price (+ currency), the contract's
snapshotted cadence (interval × count), anchor date, and `commitment_weeks`
(nullable). Pure; the plan is `[{from, to|null, amount}]` — testable without a
database, golden-tabled hard.

**Percent.** One segment: `[anchor → null, amount = round2(list × (1−p/100))]`.
(The first-period stub, if any, prorates *this* amount through the existing
`BillingMath` path untouched — assert, don't code.)

**Free-time.** Resolve tier: highest `min_commitment_weeks <= commitment_weeks`;
none or null commitment → **no tier, compiled as no-op, surfaced as such** (the
README rule). Convert `free_weeks` against period length in days
(`interval×count`, weeks×7 — bcmath): full free periods emit `amount = 0.00`
segments; a remainder emits one partial segment
`amount = round2(list × (period_days − free_remainder_days) / period_days)` —
multiply-before-divide, single round, the house arithmetic. Then `[… → null, list]`.
Golden table includes the brief's exact three cases on 4-week cadence **and** the
misaligned case (3 free weeks on monthly) proving the math stays exact even when
commercially odd.

**Integration.** In the signing spine (both callers of `ContractSigning`-adjacent
creation — store + convert), when a discount is attached: after items exist, the
compiler's plan replaces the single open-ended unit-item version with the sequence
(contract-scoped prices via the S02 rate-change machinery — **reuse its
version-writer, do not reimplement window closing**), each version linked
`discount_id` (nullable column on the version/price row — use the repo's actual
S02 naming), all inside the existing transaction. `convert-preview` runs the same
plan and renders the schedule (the preview-equals-commit law — the preview showing
"€0, €0, €184.90…" *is* this sprint's demo). First-period charges then generate
off the (possibly €0) first version with zero new code.

**Zero-total invoice skip (D-DISC #2).** `InvoiceIssuer` gains the guard: period
invoice with gross total `0.00` → no invoice row, Tier-1 note, charges stand,
`billed_through` advances (it already does — the charge exists). Config flag
`fiscal.invoice_zero_periods` default false. Deposit periods unaffected
(deposits never invoice anyway).

**Enforcement.** Discount attachment validates: unit item present, one discount per
contract (v1), currency coherence (the plan's amounts inherit the list price's
currency — nothing to convert, assert nothing tries).

## Acceptance criteria

- [ ] Compiler golden tables: percent, all three brief tiers, remainder case,
      no-commitment no-op — pure, DB-free.
- [ ] Store + convert + preview share the plan; preview renders the schedule that
      then bills, to the cent, across the free→partial→list boundary (the
      straddle test's sibling, named `disc_payoff_free_period_boundary`).
- [ ] €0 periods: charge + cursor yes, invoice no; flag flips it.
- [ ] Version rows carry discount provenance; the S02 writer reused (grep: no new
      window-closing code).
- [ ] Rent roll / economic occupancy on a seeded discounted book need **no
      changes** and show the drag (the S16 consistency fixtures re-run — economic
      below unit occupancy where free periods are live).

## Tests required

| Test | Asserts |
|---|---|
| `CompilerGoldenTest::both_kinds_all_tiers_remainder` | The pure tables |
| `DiscPayoffTest::free_period_boundary_bills_exact` | Preview = ledger |
| `ZeroInvoiceTest::skip_flag_cursor` | D-DISC #2 |
| `ProvenanceTest::versions_linked_writer_reused` | + the grep |
| `ReportsUnchangedTest::economic_drag_visible` | The harvest holds |
