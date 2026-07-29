# S02-03 — Transfer between units

## Context

A tenant outgrows a 5 m² unit and moves to 15 m². This is the most common upsell in
self-storage and the operator's best revenue event. It is **one tenancy that changed
address**, not two tenancies.

Modelling it as "terminate contract A, create contract B" destroys the things that make the
tenancy legible: continuous ledger, carried deposit, unbroken tenure for reporting, and a
single thread of notes and documents. It also produces a spurious move-out in churn figures
and a spurious move-in in acquisition figures, which quietly corrupts every retention metric
you will build in S17.

One contract. Two occupancies. Two item versions.

## Scope

**In:**
- Transfer endpoint and transaction
- Occupancy close on the origin unit, open on the destination
- Contract item version supersession with `change_reason = 'transfer'`
- Pricing choice: keep current rate or adopt the destination unit's rate
- Mid-period billing adjustment per policy
- Deposit differential
- Panel transfer drawer

**Out:**
- Cross-site transfers where the sites belong to different legal entities — permitted for now,
  guarded in S03 (see sprint README risks)
- Bulk/batch transfers
- Physical move logistics, keys, access codes (S16)

## Behaviour

### The transaction

All of this in one DB transaction, or none of it:

1. `ContractTransition::assert` — transfer permitted only from `active` or `notice_given`
2. `OccupancyGuard::assertVacant(destination, transferDate, null)`
3. `HoldGuard::assertUnheld(destination, transferDate, null)`
4. Close origin occupancy: `ended_on = transferDate`, `ended_reason = 'transferred_out'`
5. Open destination occupancy from `transferDate`
6. Close origin unit item version: `effective_to = transferDate`
7. Insert destination unit item version: `effective_from = transferDate`,
   `change_reason = 'transfer'`, `supersedes_id` → origin version
8. Billing adjustment per policy (below)
9. Deposit differential if any (below)
10. `RecordsActivity::core('contract.transferred', …)` with money as strings

Insurance and other non-unit items are **not** superseded — they carry across untouched
unless the operator explicitly changes them. Only the unit item moves.

### Pricing

Operator choice at transfer time, per the sprint README decision:

| Option | New item pricing |
|---|---|
| `destination_rate` (default) | Reference the destination pairing's **current catalogue price** directly — no new row |
| `retain_rate` | Reuse the **origin item's `price_id`** — no new row |

Neither mode inserts a price: a price row is created only when a new amount comes into
existence, and both modes re-apply existing amounts (`architecture-pricing.md` §6). The
shared reference is itself the record of where the amount came from. Only a *negotiated*
transfer amount — neither the origin rate nor the destination catalogue — would insert a
`scope='contract'` price; that is out of scope for the drawer's two modes.

`retain_rate` is a retention tool — used to move a tenant up a size without a price shock.
Record which was chosen in the activity properties; operators will be asked to justify it.

Tax is re-resolved for the new item using the standard order in `03-pricing.md`, because the
destination class may carry a different `tax_rate_code`. Snapshot the resolved rate onto the
new item as usual.

### Mid-period billing

Site setting `transfer_billing`, snapshotted onto the contract at signing:

| Value | Behaviour |
|---|---|
| `prorate_immediately` (default) | Credit unused days on origin, charge prorated days on destination, both within the current period |
| `next_period` | No adjustment; the destination rate takes effect at the next billing anchor |

For `prorate_immediately`, reuse `BillingMath` — do not write parallel arithmetic:

- Credit: an `adjustment` charge with **negative** `net_amount`, covering
  `transferDate → currentPeriodEnd`, referencing the origin item version, carrying the
  **origin charge's snapshotted tax rate** — not the current catalogue rate.
- Debit: a `rent` charge for the same window at the destination amount, referencing the new
  item version, with its own tax snapshot.

Both carry `period_start` / `period_end`. Neither edits the original charge — invariant 3.

`billed_through` is unchanged by a transfer. The period is still billed; only its composition
changed.

### Deposit

Compare the contract's snapshotted `deposit_amount` against the destination class's current
deposit requirement:

- Destination requires more → insert a `deposit` charge for the difference
- Destination requires less → **no automatic refund.** Record the surplus on the contract; it
  resolves at move-out through the normal deposit settlement in task 02. Refunding mid-tenancy
  needs a payment rail that does not exist until S07.

Update `contracts.deposit_amount` to the new total. This is the one snapshotted billing field
a transfer may touch, and it must be logged.

## Schema changes

```sql
ALTER TABLE contracts ADD COLUMN transfer_billing VARCHAR(24) NOT NULL DEFAULT 'prorate_immediately';

CREATE TABLE contract_transfers (
    id                     BIGSERIAL PRIMARY KEY,
    contract_id            BIGINT NOT NULL REFERENCES contracts(id),
    from_unit_id           BIGINT NOT NULL REFERENCES units(id),
    to_unit_id             BIGINT NOT NULL REFERENCES units(id),
    from_contract_item_id  BIGINT NOT NULL REFERENCES contract_items(id),
    to_contract_item_id    BIGINT NOT NULL REFERENCES contract_items(id),
    transfer_date          DATE NOT NULL,
    pricing_mode           VARCHAR(24) NOT NULL,   -- destination_rate | retain_rate
    reason                 TEXT NULL,
    created_by             BIGINT NULL REFERENCES employees(id),
    created_at             TIMESTAMP,
    updated_at             TIMESTAMP
);

CREATE INDEX contract_transfers_contract_idx ON contract_transfers (contract_id);
```

`contract_transfers` is the audit record. It is redundant with the occupancy and item rows by
design — it states the operator's intent in one row, which is what you want when someone asks
six months later why a tenant's rate changed.

## API surface

```
POST /api/contracts/{id}/transfer
{
  to_unit_id, transfer_date, pricing_mode?, reason?
}

POST /api/contracts/{id}/transfer-preview   -- same body, no writes
```

**Preview is mandatory, not optional.** It returns the exact charges and credits that would be
written, computed through the same `BillingMath` path — the same discipline invariant 20
already imposes on `convert-preview`. An operator must see the money before committing, and
the preview must not be able to disagree with the result.

Response includes: new item amount, credit amount, debit amount, deposit differential, and
the resulting balance.

## Panel surface

Transfer drawer, opened from the contract detail actions menu:

- Destination site + unit class + unit, using the same availability-aware picker as the New
  Reservation drawer (S01-03 auto-assign path)
- Transfer date, defaulting to today
- Pricing mode radio, with both rates shown side by side so the choice is informed
- Reason (optional free text)
- **Live preview panel** showing credit, new charge, deposit differential and net effect,
  refreshing on every field change

After transfer, contract detail shows the unit history: origin unit with its window,
destination unit as current. Reuse the History disclosure pattern from S02-00.

i18n under `contracts.transfer.*`. Spanish: *Traslado de unidad*.

## Invariants

- Invariant 3 — ledger is append-only. Adjustments are new rows; the original charge is never
  edited.
- Invariant 2b (added in S02-00) — item amounts are never updated; transfer supersedes.
- Invariant 2 (as restated in `architecture-pricing.md`) — neither transfer mode inserts or
  touches any price or junction row; both reference existing immutable prices.
- Invariant 18 — the contract's cadence, anchor, proration and tax snapshots are **not**
  re-read from org settings. `deposit_amount` is the sole permitted update, and it is logged.
- Invariant 10 — money as strings in activity properties.
- Invariant 20 — preview and commit share one path.
- S01 occupancy rules — destination must pass both guards; origin closes rather than deletes.

## Acceptance criteria

- [ ] Transfer writes exactly one closed and one open occupancy, one closed and one open item
      version, and one `contract_transfers` row.
- [ ] The contract id is unchanged; ledger history before the transfer is untouched.
- [ ] Transferring to an occupied or held unit returns 422 with no partial write.
- [ ] `prorate_immediately` produces a negative `adjustment` and a positive `rent` charge that
      net correctly, with tax computed from the **origin** snapshot on the credit.
- [ ] `next_period` writes no adjustment charges.
- [ ] `retain_rate` points the new item at the origin `price_id`; `destination_rate` points
      it at the destination pairing's current catalogue price. Neither inserts a price row.
      Both snapshot tax freshly.
- [ ] No `unit_class_rates` row and no `prices` row is written by either mode.
- [ ] Deposit shortfall creates a `deposit` charge; surplus creates nothing and is recorded.
- [ ] Insurance items are untouched by a transfer.
- [ ] Preview output equals committed output, asserted field by field in a test.
- [ ] `contract.transferred` core activity written in-transaction with money as strings.
- [ ] Seeder produces at least one transferred contract with both occupancy rows.

## Tests required

| Test | Asserts |
|---|---|
| `TransferTest::single_contract_two_occupancies` | Contract id stable, both rows correct |
| `TransferTest::item_version_superseded_not_updated` | Origin `amount` byte-identical after |
| `TransferTest::destination_must_be_available` | 422, full rollback |
| `TransferTest::prorated_credit_uses_origin_tax_snapshot` | No tax drift |
| `TransferTest::prorated_amounts_net_correctly` | Credit + debit vs. expected, to the cent |
| `TransferTest::next_period_mode_writes_no_adjustment` | Policy respected |
| `TransferTest::retain_rate_reuses_origin_price_id` | No new price row |
| `TransferTest::destination_rate_references_catalogue_price` | No new price row |
| `TransferTest::transfer_writes_no_price_or_junction_row` | Catalogue untouched |
| `TransferTest::deposit_shortfall_charged` | Differential logic |
| `TransferTest::insurance_items_untouched` | Only the unit item moves |
| `TransferTest::preview_matches_commit` | Field-by-field equality |
| `TransferTest::billed_through_unchanged` | Cursor not disturbed |
| `TransferTest::forbidden_from_ended_contract` | Transition guard applies |
