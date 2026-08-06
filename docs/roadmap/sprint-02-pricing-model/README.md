# Sprint 02 — Contract Lifecycle

## Goal

A contract can currently only be born. This sprint gives it the rest of its life: **vacate**,
**transfer**, and **scheduled rate change** — the three transitions an operator performs
weekly, each with correct ledger and occupancy effects.

## Why these three

**Vacate** is what you asked for first, and it is the visible gap — there is no way to end a
tenancy, so units never return to inventory and revenue never stops.

**Transfer** (tenant moves from unit A to unit B) is extremely common in self-storage and is
*not* "close one contract, open another". Doing it that way loses billing continuity, orphans
the deposit, breaks the ledger thread, and destroys the tenure history that occupancy
reporting depends on. It is one contract, two occupancies.

**Scheduled rate change (ECRI)** is the single largest revenue lever in this industry.
Operators raise existing tenants' rates on a schedule, with notice. Without it, every tenant
pays their move-in rate forever and the operator has no reason to switch systems. It also
must not violate price immutability — a rate change is a new `Price` row and a new
`ContractItem` version, never an `UPDATE`.

## Depends on

Sprint 01 — all three tasks write to `unit_occupancies`. Do not start before S01 ships.

## Exit criteria

- [x] An operator can vacate a contract from the contract page; the unit returns to
      availability on the move-out date, final charges are correct, and the deposit is
      resolved.
- [x] An operator can transfer a tenant between units; one contract, two occupancy rows,
      continuous ledger, deposit carried.
- [x] An operator can schedule a rate change for a future date; it applies automatically at
      the next billing run without any price row being mutated.
- [ ] Every transition writes a tier-3 `core` activity row inside the same transaction.
- [ ] No transition can be performed twice (idempotent or explicitly rejected).

## Task order

Strictly sequential. Task 00 is the foundation — tasks 03 and 04 cannot be built without it.

| # | Task | Est. |
|---|---|---|
| 00a | Pricing model migration (`../architecture-pricing.md` §8) | 1 day |
| 00 | [Contract item effective dating](./00-contract-item-effective-dating.md) | 1.5 days |
| 01 | [Contract status model and transition guard](./01-contract-status-model.md) | 1 day |
| 02 | [Vacate / move-out](./02-vacate.md) | 1.5 days |
| 03 | [Transfer between units](./03-transfer.md) | 1.5 days |
| 04 | [Scheduled rate changes](./04-scheduled-rate-change.md) | 1.5 days |

**Estimated 8 days — this sprint is larger than a week.** Either accept a 1.5-week sprint or
split after task 02, which is a coherent stopping point (vacate shipped, transfer and rate
change pending). Do not compress by skipping task 00; everything after it depends on it.

### Why task 00 exists

`contract_items` carries a flat `amount` with no validity window. Transfer needs "unit A until
the 14th, unit B from the 14th" on one contract. Rate change needs "€196.72 until 1 April,
€215.00 after" without mutating anything. S05's recurring billing job needs to ask "what was
this contract's price for the period starting D?" — a question the current schema cannot
answer. Effective-dating items, using the same new-row-and-close idiom already used for
`prices` and `tax_rates`, answers all three at once.

## Cross-cutting decisions this sprint must settle

Record in `10-open-decisions.md` as they are made.

**Move-out proration policy.** When a tenant leaves mid-period, do they get money back?
Three options, and it must be configurable per site because practice differs by country:

- `none` — no refund; the period is charged in full (common in UK/ES self-storage)
- `daily` — credit the unused days
- `notice_based` — charge to the end of the notice period regardless of physical move-out

Default to `none`, since it matches the prevailing contract terms, but build all three.

**Notice period.** Does the site require notice (e.g. 14 days) before move-out? If so, the
vacate flow captures a notice date and a move-out date separately, and the final billing date
is driven by the *later* of (notice date + notice period) and the move-out date.

**Deposit resolution.** On vacate: released in full, partially deducted, or forfeited. The
deduction needs a reason and produces an `adjustment` charge (added in S01-00). Refunds
produce a `refund` charge and, later, an actual payment out — which in S07 means a SEPA credit
transfer, not just a ledger row. Ledger-only this sprint; flag the payout as S07 work.

**Contract pricing is unified through `Price` — final model in
`../architecture-pricing.md`, which is a prerequisite read for tasks 00, 03 and 04.**
In one line: every amount lives in `prices` (which owns currency); ownership sits on the
price (`priceable` → class/insurance rate); catalogue timing lives on price windows because
junctions are static; contract timing lives on item versions because contract prices have no
windows; ledger rows snapshot raw amount + currency and reference nothing. A new price row
exists only when a new amount comes into existence.

A new task **00a** implements the migration (`architecture-pricing.md` §8) before task 00.

**Transfer pricing.** Does the tenant keep their old rate on the new unit, or move to the new
unit's current rate? Operator's choice per transfer, defaulting to the new unit's current
rate. Both paths must be available in the UI.

**Bulk rate changes are out of this sprint.** Applying an increase across a whole site in one
action is the workflow operators actually run annually, but it is UI-heavy and depends on
nothing here. Single-contract changes ship now; record the bulk workflow in
`10-open-decisions.md` as deferred.

**Credit notes are out of this sprint.** Mid-period credits from vacate and transfer land as
negative `adjustment` charges on the ledger. Grouping them into fiscal credit note documents
is S03, and S03 must be able to pick these rows up retroactively — so give every adjustment a
clear reference to the charge it adjusts.

## Risks

**Ledger correctness is the whole sprint.** Every one of these transitions moves money. The
existing `BillingMath` / `ContractBilling` path already handles proration correctly for
first-period charges — reuse it rather than writing parallel arithmetic. If a calculation
appears to need new maths, that is a signal the existing helper needs extending, not
duplicating.

**Do not mutate.** The append-only invariants are easy to break here under time pressure. A
vacate does not edit existing charges; it adds closing ones. A transfer does not edit the
contract's unit; it closes one occupancy and opens another. A rate change does not update a
price; it creates one.

**Transfer vs. two contracts.** If transfer proves genuinely hard, the tempting shortcut is
"terminate + create". Resist it — you will re-implement transfer properly within six months,
with production data already fragmented across contract pairs.
