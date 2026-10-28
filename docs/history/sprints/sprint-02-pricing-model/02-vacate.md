# S02-02 — Vacate / move-out

## Context

There is no way to end a tenancy. Units never return to inventory, billing never stops, and
the deposit is never resolved. Vacate is the single most requested missing operation and the
visible half of this sprint.

Vacate is **two transitions, not one**, because that is how it happens physically: the tenant
*gives notice* (tenancy continues, end date known), then *moves out* (tenancy over, final
settlement). A same-day vacate is simply both steps in one call.

This task depends on: S02-00a (pricing model), S02-00 (item versions), S02-01 (status model
and `ContractTransition`). Read `../architecture-pricing.md` before starting.

## Scope

**In:**
- Give notice (`active → notice_given`) and withdraw notice (`notice_given → active`)
- Move out (`active|notice_given → ended`) with final billing per policy
- Deposit settlement **recorded as ledger rows** — released, deducted, or forfeited
- Occupancy close; unit returns to inventory; optional turnover hold
- Item version closure
- Mandatory settlement preview
- Panel drawers for notice and move-out

**Out:**
- Moving money. Refunding a released deposit requires a payment rail — S07 executes the
  payout against the rows this task writes. The panel must say this plainly.
- Termination for non-payment — same mechanics, but it belongs to the delinquency ladder
  (S08), which adds the required notice sequence first. `ended_reason = 'non_payment'`
  exists now; nothing sets it yet.
- Credit note documents — final credits land as negative `adjustment` charges; S03 groups
  them into fiscal documents retroactively (sprint README).
- Reopening an ended contract. `ended` is terminal (S02-01). The undo point is notice
  withdrawal; after move-out, corrections are ledger reversals plus, if needed, a new
  contract.

## Behaviour

### The two dates

| Field | Meaning | Set at |
|---|---|---|
| `notice_given_on` | When the tenant told us | Notice |
| `scheduled_move_out_on` | When they said they'd leave | Notice (editable until move-out) |
| `move_out_on` | When they actually left | Move-out |

**Final billing date** = the *later* of `notice_given_on + notice_period_days` and
`move_out_on`. A tenant who gives 3 days' notice under a 14-day term pays through day 14
even if they leave on day 3; a tenant who lingers past their notice pays through the day
they actually leave. `notice_period_days` was snapshotted at signing (S02-01) — never
re-read from site settings.

### Step 1 — notice

One transaction:

1. `ContractTransition::assert($contract, ContractStatus::NoticeGiven)`
2. Write `notice_given_on`, `scheduled_move_out_on`
3. End-date the open occupancy: `ended_on = scheduled_move_out_on` (S02-01 behaviour)
4. `RecordsActivity::core('contract.notice_given', …)`

Billing continues unchanged — a contract in `notice_given` still bills (S02-01 state table).
The end-dated occupancy makes the unit **visible as upcoming availability**: S01's
`scopeAvailableBetween` already answers "available from date D", so the unit can be offered
for dates after `scheduled_move_out_on` without any new mechanism.

**Withdrawal** (`notice_given → active`): clear both notice fields, reopen the occupancy
(`ended_on` back to `NULL` — the S02-01 carve-out), log `contract.notice_withdrawn`.
Rejected if a reservation now holds the unit for a date before the occupancy would re-extend
— the tenant lost their claim when someone else booked the space; surface this as a 422
explaining the conflict, and let the operator resolve it by transfer.

### Step 2 — move-out

One transaction:

1. `ContractTransition::assert($contract, ContractStatus::Ended)`
2. Write `move_out_on`, `ended_reason = 'vacated'`
3. Adjust occupancy `ended_on` to the actual `move_out_on`, `ended_reason = 'vacated'`
4. Close every open item version: `effective_to = move_out_on` (exclusive — the move-out day
   itself is occupied and billable, consistent with the S01 boundary convention)
5. Final billing per policy (below)
6. Deposit settlement (below)
7. Optional turnover hold (below)
8. `RecordsActivity::core('contract.ended', …)`, money as strings

Direct vacate (no prior notice) runs step 1's writes and step 2 in the same transaction,
passing through `notice_given` semantically without lingering there.

### Final billing, by policy

Site setting `move_out_settlement`, **snapshotted onto the contract at signing** as with
every billing behaviour (invariant 18):

| Value | Behaviour when `billed_through` > final billing date |
|---|---|
| `none` (default) | Nothing credited — the billed period is kept in full. Matches prevailing UK/ES storage terms. |
| `daily` | Credit unused days: one negative `adjustment` charge per affected item charge, window `finalBillingDate → billed_through`, prorated via `BillingMath` |
| `notice_based` | As `daily`, but computed from the notice-derived date — identical arithmetic, different anchor |

And in the other direction, for **every** policy: if `billed_through` < final billing date
(mid-period move-out before the recurring job exists, or a lingering tenant), charge the gap
— one `rent` / `insurance` charge per item for `billed_through → finalBillingDate`, prorated
via `BillingMath`, due immediately.

Credit mechanics, identical to transfer (S02-03):

- Negative `net_amount` on an `adjustment` charge
- Tax computed from the **original charge's snapshot**, never the current catalogue — the
  credit must undo exactly what was charged
- References the item version and, via description/properties, the charge it adjusts —
  S03 picks these up for credit notes
- The original charge is untouched (invariant 3)

Amounts are read through each item version's `price_id` (`architecture-pricing.md`) —
there is no `amount` column anymore.

`billed_through` is left where it is. It is a cursor of what was billed, which remains true;
`ended` status stops the S05 job from ever advancing it again.

### Deposit settlement

`contracts.deposit_amount` (the signing snapshot, possibly updated by transfer) is resolved
into ledger rows. Operator chooses per vacate:

| Outcome | Ledger rows written |
|---|---|
| Release in full | One `refund` charge, negative net, amount = deposit |
| Deduct partially | One `adjustment` charge per deduction line (positive net, reason required, e.g. *door damage*), then one `refund` charge for the remainder if > 0 |
| Forfeit | One `adjustment` charge consuming the full deposit, reason required |

Rules:

- Deduction lines each carry a reason; the sum may not exceed the deposit. Deductions are
  chargeable events and **count as revenue**; the original `deposit` charge and the `refund`
  remain excluded (invariant 19 extended in S01-00).
- Tax on deductions: default 0% (compensation, not a supply) — confirm with the client's
  gestor and make it overridable per line; snapshot whatever is applied.
- The `refund` row is a **liability record, not a payout**. The panel labels it
  "pending payout — processed manually until automated payouts ship" and S07 consumes
  exactly these rows.

### Unit turnover

On move-out, site setting `turnover_hold_days` (default `0`):

- `0` — the unit is available from `move_out_on` (exclusive end; same-day re-let is legal,
  matching the S01 adjacency tests)
- `> 0` — auto-create a `maintenance` hold from `move_out_on` for that many days, reason
  `post_move_out_turnover`, released early from the unit page like any hold (S01-02)

### Preview — mandatory

`POST /api/contracts/{id}/vacate-preview` — same body as vacate, zero writes, computed
through the same `BillingMath` path (the invariant-20 discipline, as with transfer and
convert). Returns: final billing date, per-item credits/charges, deposit resolution lines,
resulting balance, and the payout amount if any. The panel drawer renders this live; an
operator must see the money before committing, and the preview cannot disagree with the
result because it *is* the result, uncommitted.

## Schema changes

```sql
-- site settings (org BillingSettings or site row — match where cadence settings live)
--   move_out_settlement   VARCHAR(24) NOT NULL DEFAULT 'none'
--   turnover_hold_days    SMALLINT   NOT NULL DEFAULT 0

ALTER TABLE contracts ADD COLUMN scheduled_move_out_on DATE NULL;
ALTER TABLE contracts ADD COLUMN move_out_settlement   VARCHAR(24) NULL; -- signing snapshot

CREATE TABLE deposit_settlements (
    id           BIGSERIAL PRIMARY KEY,
    contract_id  BIGINT NOT NULL REFERENCES contracts(id),
    outcome      VARCHAR(16) NOT NULL,          -- released | deducted | forfeited
    deposit_amount NUMERIC(10,2) NOT NULL,      -- snapshot at settlement
    refunded_amount NUMERIC(10,2) NOT NULL,
    currency     CHAR(3) NOT NULL,
    payout_status VARCHAR(16) NOT NULL DEFAULT 'pending', -- pending | paid | not_applicable
    paid_at      TIMESTAMP NULL,                -- S07 sets this
    created_by   BIGINT NULL REFERENCES employees(id),
    created_at   TIMESTAMP,
    updated_at   TIMESTAMP
);

CREATE TABLE deposit_settlement_lines (
    id                    BIGSERIAL PRIMARY KEY,
    deposit_settlement_id BIGINT NOT NULL REFERENCES deposit_settlements(id),
    charge_id             BIGINT NOT NULL REFERENCES charges(id),
    amount                NUMERIC(10,2) NOT NULL,
    currency              CHAR(3) NOT NULL,
    reason                TEXT NOT NULL,
    created_at            TIMESTAMP
);

CREATE UNIQUE INDEX deposit_settlements_contract_idx ON deposit_settlements (contract_id);
```

`deposit_settlements` states the operator's intent in one row — the vacate counterpart of
`contract_transfers`. Amount/currency columns here are **event snapshots**, consistent with
the ledger rule in `architecture-pricing.md` §1: no `price_id`, self-describing forever.
`notice_given_on`, `move_out_on`, `ended_reason`, `notice_period_days` already exist
(S02-01).

## API surface

```
POST /api/contracts/{id}/notice            { scheduled_move_out_on }
POST /api/contracts/{id}/notice-withdraw
POST /api/contracts/{id}/vacate            { move_out_on,
                                             deposit: { outcome,
                                                        deductions?: [{ amount, reason, tax_rate_id? }] } }
POST /api/contracts/{id}/vacate-preview    -- same body, no writes
```

Verb endpoints per transition, per S02-01 — no generic status setter. All validate through
`ContractTransition::assert`; repeating a transition returns 422, never a duplicate write.

## Panel surface

**Contract detail actions** (rendered from `allowed_transitions`, S02-01): *Give notice* on
`active`; *Withdraw notice* and *Move out* on `notice_given`; *Move out* on `active`
(direct vacate).

**Notice drawer:** scheduled date defaulting to today + `notice_period_days`, with the
earliest chargeable date explained inline. After notice, the header shows a
countdown-style line: "Moving out YYYY-MM-DD · billed through YYYY-MM-DD".

**Move-out drawer:**
- Actual move-out date (defaults to scheduled, or today)
- Deposit section: outcome radio; deduction lines (amount + reason, add/remove) with a live
  remainder; forfeit requires a reason
- **Live settlement preview**: final billing date, credits/charges per line, deposit
  resolution, net balance, and — highlighted — "Payout due to tenant: €X (manual until
  automated payouts ship)"
- Confirm restates the unit returning to inventory (or entering turnover hold)

After move-out: status badge `Finalizado`, the settlement summary as a card, and the unit's
history (S01-04) shows the closed occupancy.

i18n under `contracts.notice.*`, `contracts.vacate.*`, `contracts.deposit.*`. Spanish:
give notice → *Comunicar preaviso*, move out → *Finalizar contrato*, deposit → *Fianza*,
deduction → *Deducción*, forfeit → *Retención total*. Confirm *Fianza* vs *Depósito* with
the client — regional.

## Invariants

- Invariant 3 — final credits and deposit rows are new rows; nothing is edited or deleted.
- Invariant 2b — item versions are closed, never deleted; their `price_id` references remain.
- Invariant 18 — `move_out_settlement` and `notice_period_days` are signing snapshots;
  later site-setting changes never alter existing contracts.
- Invariant 19 (extended) — `deposit` and `refund` are not revenue; deposit *deductions* are.
- Invariant 5 — the settlement stores no balance; "amount owed at move-out" stays computed.
- Invariant 10 / 14 / 16 — money as strings; machine-key descriptions
  (`contract.notice_given`, `contract.notice_withdrawn`, `contract.ended`);
  `RecordsActivity::core` in-transaction.
- Ledger rule (`architecture-pricing.md` §1) — settlement rows snapshot amount + currency,
  no `price_id`.
- S01 — occupancy closed with reason, never deleted; turnover hold via `unit_holds`.

## Acceptance criteria

- [ ] Notice sets both dates, end-dates the occupancy, and the unit appears in
      `scopeAvailableBetween` for dates after the scheduled move-out.
- [ ] Withdrawal restores `active`, reopens the occupancy, and is rejected with 422 when a
      conflicting reservation exists.
- [ ] Move-out closes occupancy and all open item versions at `move_out_on` (exclusive);
      the unit is re-lettable the same day when `turnover_hold_days = 0`, or held when > 0.
- [ ] `none` credits nothing; `daily` credits unused days to the cent with the original tax
      snapshot; the under-billed gap is charged under every policy.
- [ ] Final billing date honours the later-of rule for early and late leavers.
- [ ] Each deposit outcome writes exactly the specified ledger rows; deductions cannot
      exceed the deposit; reasons are required and stored.
- [ ] `deposit_settlements` row written with `payout_status = 'pending'` (or
      `not_applicable`); nothing pretends money moved.
- [ ] Preview equals commit, asserted field by field.
- [ ] Double-vacate returns 422 with no write.
- [ ] Seeder produces: an ended contract with each settlement policy exercised, one with
      deductions, one in `notice_given`, one in turnover hold.
- [ ] `en.json` / `es.json` / `fr.json` complete.

## Tests required

| Test | Asserts |
|---|---|
| `VacateTest::notice_end_dates_occupancy` | Unit shows upcoming availability |
| `VacateTest::withdrawal_reopens_occupancy` | And clears notice fields |
| `VacateTest::withdrawal_blocked_by_reservation` | 422, conflict named |
| `VacateTest::move_out_closes_items_and_occupancy` | All open versions, exclusive boundary |
| `VacateTest::same_day_relet_after_move_out` | Adjacency legal |
| `VacateTest::turnover_hold_created_when_configured` | `unit_holds` row, reason set |
| `VacateTest::policy_none_credits_nothing` | Ledger unchanged beyond deposit |
| `VacateTest::policy_daily_credits_to_the_cent` | `BillingMath`, original tax snapshot |
| `VacateTest::under_billed_gap_charged_under_every_policy` | Late leaver pays |
| `VacateTest::later_of_rule_for_early_leaver` | Notice period floor |
| `VacateTest::deposit_release_writes_refund_row` | Negative net, pending payout |
| `VacateTest::deductions_capped_at_deposit` | 422 over cap |
| `VacateTest::forfeit_requires_reason` | Validation |
| `VacateTest::deductions_count_as_revenue_refund_does_not` | Invariant 19 extension |
| `VacateTest::preview_matches_commit` | Field-by-field |
| `VacateTest::double_vacate_rejected` | Transition guard |
| `VacateTest::billed_through_untouched` | Cursor left as billed |
