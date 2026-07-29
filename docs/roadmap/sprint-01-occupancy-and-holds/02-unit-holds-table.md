# S01-02 — `unit_holds` table

## Context

A unit can be unavailable for reasons that are not a contract: a reservation hold, damage,
scheduled maintenance, staff use, or (from S07) an overlock. Today only reservations exist,
and they are inferred from the `reservations` table with an expiry check scattered across
queries. Maintenance has no representation at all — an operator cannot take a flooded unit
off the market without inventing a fake contract.

This task generalises "unit is blocked, for a reason, over a period" into one table alongside
`unit_occupancies`. Availability then becomes a two-table question.

## Scope

**In:**
- `unit_holds` table with typed reasons
- Non-overlap constraint scoped to blocking hold types
- `UnitHold` model; reservation creation writes a hold
- Manual maintenance/blocked holds via API

**Out:**
- The overlock hold type being *set automatically* (S07 — the enum value exists now, nothing
  writes it yet)
- Panel UI (task 04)
- Removing the `reservations` table (it stays; the hold is its inventory projection)

## Schema changes

```sql
CREATE TABLE unit_holds (
    id             BIGSERIAL PRIMARY KEY,
    unit_id        BIGINT NOT NULL REFERENCES units(id),
    hold_type      VARCHAR(24) NOT NULL,   -- reservation | maintenance | damaged
                                           -- | staff_use | overlock | other
    reservation_id BIGINT NULL REFERENCES reservations(id),
    starts_on      DATE NOT NULL,
    ends_on        DATE NULL,              -- NULL = open-ended
    released_at    TIMESTAMP NULL,         -- early release; NULL = still active
    reason         TEXT NULL,              -- required for manual types
    created_by     BIGINT NULL REFERENCES employees(id),
    created_at     TIMESTAMP,
    updated_at     TIMESTAMP
);

CREATE INDEX unit_holds_unit_idx ON unit_holds (unit_id, starts_on);
CREATE INDEX unit_holds_active_idx ON unit_holds (unit_id)
    WHERE released_at IS NULL;
CREATE UNIQUE INDEX unit_holds_reservation_idx ON unit_holds (reservation_id)
    WHERE reservation_id IS NOT NULL;
```

**Postgres constraint** — holds must not overlap each other, but a hold *may* overlap an
occupancy in one legitimate case: an overlock on a currently-rented unit. So the constraint
lives within `unit_holds` only, and excludes released rows:

```sql
ALTER TABLE unit_holds
  ADD CONSTRAINT unit_holds_no_overlap
  EXCLUDE USING gist (
    unit_id WITH =,
    daterange(starts_on, ends_on, '[)') WITH &&
  ) WHERE (released_at IS NULL AND hold_type <> 'overlock');
```

Overlock is exempt because it coexists with an active occupancy by definition — it is an
access restriction on an occupied unit, not an inventory block.

Same driver guard as task 01: Postgres-only, skipped on SQLite.

## Implementation notes

**Hold types and their semantics:**

| Type | Blocks availability | Coexists with occupancy | Set by |
|---|---|---|---|
| `reservation` | Yes | No | Reservation create |
| `maintenance` | Yes | No | Operator |
| `damaged` | Yes | No | Operator |
| `staff_use` | Yes | No | Operator |
| `overlock` | No (already occupied) | Yes | S07 delinquency engine |
| `other` | Yes | No | Operator, reason required |

**Reservation integration.** Reservation create, inside its existing transaction:

1. `OccupancyGuard::assertVacant($unitId, $startsOn, $endsOn)` — a reservation cannot be
   placed on an occupied unit
2. `HoldGuard::assertUnheld($unitId, $startsOn, $endsOn)`
3. Insert `unit_holds` with `hold_type = 'reservation'`, `reservation_id` set,
   `ends_on = reservations.expires_at::date`

**Reservation expiry stays read-time** (invariant 13). The hold row's `ends_on` carries the
expiry date, so `ends_on < today` means the hold no longer blocks — no background job
required. Do not add a sweeper.

**Reservation → contract conversion.** When a reservation converts, set
`unit_holds.released_at = now()` in the same transaction that creates the occupancy. The
adjacent-range convention means the occupancy can start on the same day.

**Where it goes:** `App\Support\Occupancy\HoldGuard`, alongside `OccupancyGuard`.

## API surface

```
POST   /api/units/{unit}/holds        { hold_type, starts_on, ends_on?, reason? }
DELETE /api/units/{unit}/holds/{hold} → sets released_at (never deletes the row)
GET    /api/units/{unit}/holds        ?active=1
```

`DELETE` releasing rather than deleting keeps the history queryable and matches the
append-only spirit of the codebase. `hold_type = 'reservation'` holds cannot be created or
released through this endpoint — they are managed by the reservation lifecycle. Reject with
422.

## Panel surface

Nothing in this task. Task 04 handles display and the maintenance form.

## Invariants

- Invariant 13 — offer/reservation expiry is read-time. `ends_on` encodes it; no job.
- Invariant 5 — as amended in task 01, holds are facts, not cached availability.
- Append-only spirit — holds are released, never deleted.

New invariant to add to `09`:

> **A unit is available on date D when it has no `unit_occupancies` row covering D and no
> unreleased blocking `unit_holds` row covering D.** Never store availability as a column.

## Acceptance criteria

- [ ] `unit_holds` migration runs on SQLite and Postgres.
- [ ] Creating a reservation writes exactly one `reservation` hold linked by
      `reservation_id`.
- [ ] Reserving an occupied unit returns 422.
- [ ] Reserving an already-held unit returns 422.
- [ ] Converting a reservation to a contract releases the hold and opens an occupancy in one
      transaction.
- [ ] An expired reservation's hold stops blocking availability with no job run.
- [ ] An operator can place and release a `maintenance` hold via the API.
- [ ] `DELETE` sets `released_at`; the row survives.
- [ ] Attempting to create or release a `reservation` hold via the holds endpoint is
      rejected.
- [ ] `overlock` exists in the enum and is exempt from the overlap constraint.

## Tests required

| Test | Asserts |
|---|---|
| `UnitHoldTest::reservation_creates_hold` | Linked row, correct `ends_on` |
| `UnitHoldTest::cannot_reserve_occupied_unit` | 422 |
| `UnitHoldTest::cannot_reserve_held_unit` | 422 |
| `UnitHoldTest::conversion_releases_hold_and_opens_occupancy` | Both in one transaction |
| `UnitHoldTest::expired_hold_does_not_block` | Read-time expiry, no job |
| `UnitHoldTest::maintenance_hold_blocks_availability` | No contract involved |
| `UnitHoldTest::release_sets_timestamp_not_delete` | Row count unchanged |
| `UnitHoldTest::reservation_hold_not_manageable_via_holds_api` | 422 on both verbs |
| `Pgsql/HoldConstraintTest::overlock_may_overlap_other_holds` | Constraint exemption |
