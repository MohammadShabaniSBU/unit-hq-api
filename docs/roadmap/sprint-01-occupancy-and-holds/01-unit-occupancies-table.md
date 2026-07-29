# S01-01 — `unit_occupancies` table and non-overlap constraint

## Context

Today, "unit X is occupied" is inferred by walking `contract_items` looking for a polymorphic
row pointing at a unit whose contract is active. That has three consequences:

- No database constraint can express "one active contract per unit". Two concurrent signings
  both pass the check and both commit.
- Occupancy history is not queryable without reconstructing it from contract dates.
- A transfer (unit A → unit B, extremely common in self-storage) has nowhere to record that
  the same contract occupied two units at different times.

This task introduces the fact table and lets the database enforce the rule.

## Scope

**In:**
- `unit_occupancies` table
- Postgres exclusion constraint on overlapping ranges per unit
- SQLite-compatible fallback guard
- `UnitOccupancy` model, relations from `Unit` and `Contract`
- Contract signing writes an occupancy row inside the existing transaction

**Out:**
- Closing occupancies on move-out (S02)
- Transfers (S02)
- Backfill of existing contracts (task 03 of this sprint)
- Reservations and maintenance (task 02 of this sprint)

## Schema changes

```sql
CREATE TABLE unit_occupancies (
    id              BIGSERIAL PRIMARY KEY,
    unit_id         BIGINT NOT NULL REFERENCES units(id),
    contract_id     BIGINT NOT NULL REFERENCES contracts(id),
    contract_item_id BIGINT NULL REFERENCES contract_items(id),
    started_on      DATE NOT NULL,
    ended_on        DATE NULL,          -- NULL = open-ended, still occupied
    ended_reason    VARCHAR(32) NULL,   -- vacated | transferred_out | terminated (S02)
    created_by      BIGINT NULL REFERENCES employees(id),
    created_at      TIMESTAMP,
    updated_at      TIMESTAMP
);

CREATE INDEX unit_occupancies_unit_idx ON unit_occupancies (unit_id, started_on);
CREATE INDEX unit_occupancies_contract_idx ON unit_occupancies (contract_id);
CREATE INDEX unit_occupancies_open_idx ON unit_occupancies (unit_id) WHERE ended_on IS NULL;
```

**Postgres only — the constraint that matters:**

```sql
CREATE EXTENSION IF NOT EXISTS btree_gist;

ALTER TABLE unit_occupancies
  ADD CONSTRAINT unit_occupancies_no_overlap
  EXCLUDE USING gist (
    unit_id WITH =,
    daterange(started_on, ended_on, '[)') WITH &&
  );
```

`daterange(started_on, ended_on, '[)')` treats `ended_on` as exclusive: a move-out on the 1st
and a move-in on the 1st do **not** overlap. This matches the boundary convention already
documented in `05-billing-ledger.md` ("move-in day is billed; anchor is the first day of the
next period"). Keep them consistent — an inclusive end here would silently create off-by-one
gaps against billing.

The migration must guard the constraint behind a driver check so SQLite migrations still run:

```php
if (DB::getDriverName() === 'pgsql') {
    DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
    DB::statement('ALTER TABLE unit_occupancies ADD CONSTRAINT ...');
}
```

## Implementation notes

**Application-level guard (required on both drivers).** The constraint is a safety net, not
the primary path — a raw constraint violation surfaces as a 500. Add to
`App\Support\Occupancy\OccupancyGuard`:

```php
public static function assertVacant(int $unitId, CarbonInterface $from, ?CarbonInterface $to): void
```

It performs `SELECT ... FOR UPDATE` on open and overlapping occupancy rows for that unit
inside the caller's transaction, and throws a domain exception the controller maps to 422
with a clear message. Row-level locking is what actually serialises concurrent signings;
without `FOR UPDATE` two transactions read "vacant" simultaneously and only the constraint
saves you.

On SQLite `FOR UPDATE` is a no-op, but SQLite serialises writes anyway, so the test suite
still exercises the logic.

**Where it goes:** new namespace `App\Support\Occupancy\` — same tier as
`App\Support\Billing\`. Not a service class; static helpers, no state, per invariant "no
`app/Services/` layer".

**Contract signing.** In the existing contract-store transaction (and reservation convert),
after `ContractItem` rows are created, for each item whose subject is a unit:

1. `OccupancyGuard::assertVacant($unitId, $moveInDate, null)`
2. Insert `unit_occupancies` with `started_on = contracts.move_in_date`, `ended_on = NULL`

This must be inside the same transaction as the contract, items, and first-period charges —
invariant 20.

**Contract with an end date.** If `contracts.end_date` is set at signing, `ended_on` is set
to it immediately. Otherwise `NULL`.

## API surface

No new endpoints. Existing responses gain:

- `GET /api/units/{id}` → `current_occupancy: { contract_id, started_on } | null`
- `GET /api/contracts/{id}` → `occupancies: Array<{ unit_id, started_on, ended_on }>`

## Panel surface

Nothing in this task. Task 04 handles display.

## Invariants

Quoting `09-conventions-and-invariants.md`:

> **5. Derived state only** — never store `is_available`, balance owed, overdue flags, or
> unallocated credit as columns.

`unit_occupancies` does **not** breach this. It records the underlying fact (a contract
occupies a unit over a date range); availability remains computed from it. Add this
clarification to invariant 5 as part of this task so the distinction is explicit for future
readers.

> **20. Contract create writes first-period charges in the same DB transaction.**

The occupancy row joins that transaction.

## Acceptance criteria

- [ ] `unit_occupancies` migration runs on both SQLite and Postgres.
- [ ] On Postgres, inserting two overlapping rows for the same unit raises a constraint
      violation.
- [ ] Signing a contract writes exactly one occupancy row per unit line item.
- [ ] Signing a contract for an already-occupied unit returns 422 with a translatable error
      key, not a 500.
- [ ] A contract signed with an `end_date` writes `ended_on` immediately.
- [ ] `Unit::currentOccupancy()` and `Contract::occupancies()` relations exist.
- [ ] Invariant 5 in `09-conventions-and-invariants.md` amended to distinguish fact tables
      from cached derived state.
- [ ] No existing test breaks.

## Tests required

| Test | Asserts |
|---|---|
| `UnitOccupancyTest::contract_signing_creates_occupancy` | Row written, dates correct |
| `UnitOccupancyTest::signing_occupied_unit_is_rejected` | 422, no partial write |
| `UnitOccupancyTest::adjacent_ranges_do_not_conflict` | Move-out 1st + move-in 1st both succeed |
| `UnitOccupancyTest::occupancy_rolls_back_with_contract` | Failed charge generation leaves no orphan occupancy |
| `UnitOccupancyTest::end_dated_contract_sets_ended_on` | `ended_on` populated at signing |
| `Pgsql/OccupancyConstraintTest::exclusion_constraint_blocks_overlap` | Postgres-only; skipped on SQLite |
