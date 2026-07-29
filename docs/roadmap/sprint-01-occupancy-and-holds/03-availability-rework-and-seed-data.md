# S01-03 — Availability rework and seed data

## Context

Tables exist (tasks 01–02) but nothing reads them yet. This task migrates the read path onto
them and updates the seeders to populate them.

**There is no live data.** No backfill, no conflict resolution, no rollback command. The
seeders are the only source of occupancy and hold rows, which makes them the thing that
determines whether the rest of this sprint is verifiable. Treat them as a deliverable, not
as scaffolding: task 04 cannot demonstrate a `damaged` badge if no seeded unit is ever
damaged.

## Scope

**In:**
- Single canonical availability query used everywhere
- Replacement of every ad-hoc availability check
- Auto-assign reads the new path
- Seeders produce occupancies, holds, and history covering every state

**Out:**
- Panel display (task 04)
- Occupancy-based reporting (S17)
- Any migration of existing rows — there are none

## Implementation notes

### The canonical query

`App\Support\Occupancy\Availability`:

```php
public static function isAvailable(int $unitId, CarbonInterface $on): bool
public static function scopeAvailableOn(Builder $q, CarbonInterface $on): Builder
public static function scopeAvailableBetween(Builder $q, CarbonInterface $from, ?CarbonInterface $to): Builder
public static function stateOn(int $unitId, CarbonInterface $on): UnitState
```

`UnitState`: `available | occupied | reserved | maintenance | damaged | staff_use | other`.
Precedence when several apply: occupancy wins over hold; among holds, the earliest-created
unreleased blocking hold wins.

Implement as a single `whereNotExists` pair — no N+1, no per-unit loop:

```sql
SELECT u.* FROM units u
WHERE NOT EXISTS (
    SELECT 1 FROM unit_occupancies o
    WHERE o.unit_id = u.id
      AND o.started_on <= :on
      AND (o.ended_on IS NULL OR o.ended_on > :on)
)
AND NOT EXISTS (
    SELECT 1 FROM unit_holds h
    WHERE h.unit_id = u.id
      AND h.released_at IS NULL
      AND h.hold_type <> 'overlock'
      AND h.starts_on <= :on
      AND (h.ends_on IS NULL OR h.ends_on > :on)
)
```

Note `ended_on > :on` and `ends_on > :on` — exclusive end, consistent with tasks 01–02 and
with the billing boundary convention in `05-billing-ledger.md`. An inclusive end here would
silently create off-by-one gaps against billing.

### Call sites to replace

Audit and replace every one. Grep for `is_available`, `available`, `activeContract`,
`nonExpiredReservation` across the API repo:

- `GET /api/units?available=1` list filter
- Unit map data endpoint
- Reservation create validation
- Contract store validation
- Offer option → reservation acceptance path
- **Auto-assign** — the "leave unit empty to auto-assign a random available unit from this
  site and class" behaviour in the New Reservation drawer
- `FilterableFields` availability field, if present
- Occupancy percentages on the Unit Class matrix page

The Unit Class matrix shows `occupied / total · %` per site × class across roughly 100 cells.
It must compute from `unit_occupancies` as of today, with a **single grouped query** —
per-cell queries will make that page unusable.

## Seeders

The existing seeders create sites, unit classes, ~500 units, contacts, deals, offers,
reservations and contracts. They must now also create the occupancy and hold rows that
correspond to that state, plus enough variety to exercise every code path.

### Rules

1. **Seed through the guards, not around them.** Call `OccupancyGuard::assertVacant` and
   `HoldGuard::assertUnheld` before inserting. If a seeder run ever throws, the generator
   logic is wrong and you want to know immediately — do not catch and skip.
2. **Never produce overlaps.** Assign each unit at most one open occupancy. Build the unit
   pool per site × class, shuffle, and draw without replacement rather than picking randomly
   and retrying.
3. **Deterministic by default.** Seed the RNG from a fixed value so occupancy percentages are
   reproducible between runs. Accept an env override for randomised runs.
4. **Every contract gets an occupancy.** A signed contract with no occupancy row is exactly
   the inconsistency this sprint exists to prevent.

### Target distribution per site

Approximate, and worth making configurable at the top of the seeder:

| State | Share of units | Notes |
|---|---|---|
| `occupied` (open occupancy) | ~55% | Contract-backed |
| `available` | ~30% | No occupancy, no hold |
| `reserved` | ~6% | Reservation hold, `ends_on` in the future |
| `maintenance` | ~3% | Manual hold, reason set |
| `damaged` | ~2% | Manual hold, reason set |
| `staff_use` | ~1% | Manual hold |
| `other` | ~1% | Manual hold, reason set |
| `overlock` | ~2% of *occupied* units | Hold coexisting with an occupancy |

### Required edge cases

These exist so tests and manual verification have something to bite on. Each should appear
at least once, on a known unit number recorded in the seeder's output summary:

- A unit with a **closed occupancy** (`ended_on` in the past) and currently available —
  proves history renders and the unit returned to inventory
- A unit with **two sequential closed occupancies** — proves the history card lists more than
  one and that adjacent ranges are legal
- A unit whose occupancy **ended yesterday** and has a new occupancy **starting today** —
  the exclusive-end boundary case
- A unit with an **expired reservation hold** (`ends_on` in the past, `released_at` null) —
  proves read-time expiry works with no sweeper job
- A unit with a **released hold** (`released_at` set) that is now available
- An **overlocked occupied unit** — proves overlock does not double-count as unavailable
- A unit with an **open-ended maintenance hold** (`ends_on` null)

### Output summary

At the end of the seeder, print a short table: count per state, plus the specific unit
numbers used for each edge case above. You will use this constantly during task 04, and
during S02 when building vacate and transfer.

## API surface

`GET /api/units` gains `?available_on=YYYY-MM-DD` (defaults to today when `?available=1` is
passed). The unit resource gains `state` and `current_occupancy_id | current_hold_id`.

## Invariants

- Invariant 5, as amended in task 01 — availability is computed, never stored.
- Invariant 13 — reservation expiry stays read-time. The seeded expired hold proves it.
- Advanced filters convention — if availability becomes a filterable native field it must go
  through the `FilterableFields` whitelist, never a raw client column name.

## Acceptance criteria

- [ ] `Availability` is the only place availability logic lives; grep confirms no residual
      ad-hoc checks.
- [ ] Auto-assign selects only genuinely available units, verified against a freshly seeded
      database containing all seven states.
- [ ] `GET /api/units?available=1` on the 500-unit seed executes a bounded number of queries
      (assert with a query-count assertion — no N+1).
- [ ] Unit Class occupancy matrix renders from the new tables with one grouped query.
- [ ] `php artisan migrate:fresh --seed` completes with no guard exception.
- [ ] Every seeded contract has a matching occupancy row; a count query proves parity.
- [ ] No seeded unit has overlapping occupancies or overlapping blocking holds — assert this
      in a test, not by inspection.
- [ ] All seven states are present after seeding, and every edge case above exists.
- [ ] Seeder prints the state summary and edge-case unit numbers.
- [ ] Re-running `migrate:fresh --seed` with the fixed RNG seed produces the same
      distribution.

## Tests required

| Test | Asserts |
|---|---|
| `AvailabilityTest::occupied_unit_unavailable` | Occupancy blocks |
| `AvailabilityTest::held_unit_unavailable` | Hold blocks |
| `AvailabilityTest::overlocked_unit_not_double_counted` | Overlock does not itself block |
| `AvailabilityTest::released_hold_does_not_block` | `released_at` respected |
| `AvailabilityTest::expired_hold_does_not_block` | Read-time expiry, no job |
| `AvailabilityTest::ended_occupancy_frees_unit_same_day` | Exclusive end boundary |
| `AvailabilityTest::available_scope_has_no_n_plus_one` | Query count bounded |
| `AutoAssignTest::picks_only_available_units` | Across all states |
| `SeederIntegrityTest::no_overlapping_occupancies` | Whole seeded dataset |
| `SeederIntegrityTest::no_overlapping_blocking_holds` | Whole seeded dataset |
| `SeederIntegrityTest::every_contract_has_occupancy` | Parity count |
| `SeederIntegrityTest::all_unit_states_present` | Task 04 has something to render |
