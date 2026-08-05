# S20-03 — Demo world floor plans

## Context

`demo:seed --fresh` builds 5 sites, 20 unit classes and 2 000 units, then never writes a
`site_maps` row. Every demo of the unit map is a demo of an empty page.

This task makes floor plans part of the stage, generated from the units that actually exist, so a
perfect match is the default state rather than something to chase.

## Scope

**In:**
- `Database\Seeders\Demo\FloorPlanStage` (or an equivalent private method on `StageSeeder`)
- 4 floors per site, deterministic floor assignment, `sort_order` set
- Every stored `svg_map` passed through `SvgSanitizer::sanitize()` first
- One deliberately imperfect floor so the validator has non-zero buckets
- A summary line in `demo:seed` output

**Out:**
- Changing anything about how units are created — unit counts, numbering and RNG draws stay
  exactly as they are
- Occupancy or hold seeding (already handled by the crowd/cast executors)
- Activity logging for generated maps (the demo stage is not operator activity)
- Panel work (task 04)

## Schema changes

None. `site_maps` already has `site_id`, `floor_name`, `svg_map`, `sort_order` and a unique index
on `(site_id, floor_name)`.

## Implementation notes

**⚠ The RNG stream is load-bearing — read this before writing code.**

`StageSeeder::run()` calls `mt_srand($rngSeed)` and `fake()->seed($rngSeed)` once, then every
`mt_rand()` and `fake()` call downstream consumes from that single stream. Cast personas require
specific classes at specific sites late in the 14-month window (see the comment above the unit
loop about `MAD-01 SS5` being exhausted). **One stray random draw shifts every subsequent value and
silently rebuilds the demo world.**

Therefore: the floor plan path must contain no `mt_rand()`, no `fake()`, no `shuffle()`, no
`Collection::random()`, no `Str::random()`. Floor assignment is a sort. Verify with a grep over the
new code before you commit, and note it in the PR.

**Where it hooks.** At the end of `StageSeeder::run()`, after the unit creation loop and before or
after `DemoRbacGrants::assign($sites)` — position is irrelevant once the no-RNG rule holds, which
is exactly why the rule matters. It inherits the seeder's existing "already present → skip" guard
at the top of `run()`.

**Floor assignment.** 400 units per site over 4 floors, 100 each:

```php
$units = Unit::query()
    ->where('site_id', $site->id)
    ->get(['id', 'unit_number', 'actual_width', 'actual_depth'])
    ->sortBy(fn (Unit $u) => [
        (int) Str::afterLast($u->unit_number, '-'),   // 01..20 — the suffix
        $u->unit_number,                              // stable tiebreak
    ])
    ->values();
```

Sorting by suffix first and chunking by 100 puts suffixes 01–05 of *every* class on the ground
floor, 06–10 on the first, and so on. Each floor gets a mix of all 20 classes, which is what makes
the map look like a facility instead of a floor of identical lockers — and it is a pure sort, so it
is deterministic without touching the RNG.

Floor names and `sort_order`:

| Chunk | `floor_name` | `sort_order` |
|---|---|---|
| 0 | Ground floor | 0 |
| 1 | First floor | 1 |
| 2 | Second floor | 2 |
| 3 | Third floor | 3 |

**Geometry.** `unit_classes` has `size` (m²) but no width/depth — the per-unit dimensions are on
the unit: `actual_width`, `actual_depth`, both metres, both always populated by `StageSeeder`.
Pass them straight through as `width_m` / `depth_m`. Fall back to `3.0` only if null, so the
generator never divides by zero on a hand-inserted row.

**Sanitize before storing.** Run `SvgSanitizer::sanitize()` over the generated SVG and store the
result. Not because the generator emits anything dangerous, but because it makes the demo data
identical in shape to uploaded data — if the sanitizer ever starts stripping `data-unit-number`,
`demo:seed` breaks loudly on the next run instead of production breaking quietly six months later.

**Writing.** `SiteMap::updateOrCreate(['site_id' => …, 'floor_name' => …], ['svg_map' => …,
'sort_order' => …])` inside one transaction per site. `updateOrCreate` respects the unique index
and makes a partial re-run idempotent. No `RecordsActivity` call — the demo stage is fixture data,
not operator history, and `StageSeeder` already runs `WithoutModelEvents`.

**One deliberately broken floor.** The validator's three buckets have never been seen with real
data. Give `PAR-01`'s top floor two orphan shapes and three uncovered units, behind a named
constant so it reads as intentional:

```php
private const IMPERFECT_MAP = [
    'site_code'       => 'PAR-01',
    'floor_name'      => 'Third floor',
    'uncovered_units' => 3,
    'orphan_shapes'   => ['PAR-01-XX-01', 'PAR-01-XX-02'],
];
```

Every other site must be perfect. Mention it in the sprint PR so nobody "fixes" it.

**Output.** Add a table to `demo:seed`'s existing summary — site, floor, shapes, matched, orphans.
The command already prints phase timings and RBAC grants; this follows the same pattern. It is what
makes exit criteria checkable without opening a database client.

**Performance budget.** 20 documents, ~100 shapes each. Generation is string building; sanitizing
20 documents through DOM is the slower half. Budget under 5 s total against a 5-minute wall clock,
and assert it does not blow up in the test.

## API surface

None new. `GET /api/sites/{site}/maps` starts returning four rows per site instead of zero.

## Panel surface

None in this task.

## Invariants

- Invariant 1 (mono-tenant) — no company scoping anywhere in the new code.
- Invariant 5 — no `data-status` in the generated SVG; state is derived at read time.
- `09` seeder convention — deterministic by default; same `DEMO_SEED` produces the same world.
- **New, add to `09`:** *The demo stage generation path performs no random draws. Anything added to
  `StageSeeder` after unit creation must be deterministic, or every cast persona downstream
  shifts.*

## Acceptance criteria

- [ ] `php artisan demo:seed --fresh` creates exactly 20 `site_maps` rows — 4 per site.
- [ ] `sort_order` is 0..3 per site and floor names are unique per site.
- [ ] For each of the 19 intact maps, `SiteMapIdMatcher::match()` returns `orphan_shapes = []`.
- [ ] Union of `matched` across a site's four floors equals that site's 400 unit numbers, for the
      four intact sites.
- [ ] `PAR-01` Third floor reports exactly 2 orphan shapes and its site reports 3 uncovered units.
- [ ] Every stored `svg_map` parses and equals its own `SvgSanitizer::sanitize()` output
      (sanitizing twice is a no-op).
- [ ] No new `mt_rand` / `fake()` / `shuffle` call in the generation path — grep proves it.
- [ ] Existing demo tests (`DemoSpineTest` and the rest of the suite) pass unchanged, proving the
      RNG stream did not move.
- [ ] `demo:seed --fresh` wall clock within 5 s of the pre-sprint figure; record both in the PR.
- [ ] Two `demo:seed --fresh` runs at the same `DEMO_SEED` produce identical `svg_map` values.
- [ ] `demo:seed` prints the floor plan summary table.

## Tests required

`tests/Feature/Demo/DemoFloorPlanTest.php` — follow the existing `DemoSpineTest` setup so the
pipeline is run once, not per test.

| Test | Asserts |
|---|---|
| `demo_seed_creates_four_floors_per_site` | 20 rows, correct `sort_order`, unique floor names |
| `every_intact_map_has_no_orphan_shapes` | All maps except the designated imperfect one |
| `site_units_are_fully_covered` | Union of matched across floors = site unit numbers |
| `imperfect_map_reports_expected_buckets` | 2 orphans, 3 uncovered, on `PAR-01` only |
| `stored_svg_is_already_sanitized` | Re-sanitizing is a no-op |
| `floor_assignment_is_deterministic` | Same seed → identical `svg_map` across two runs |
| `generation_performs_no_random_draws` | `mt_rand()` immediately before and after generation returns the same next value |
| `generation_stays_within_budget` | 20 maps generated and stored in under 5 s |

The `generation_performs_no_random_draws` test is the important one — it is the only mechanical
guard against the failure mode described at the top, and it is three lines:
capture `mt_rand()` state by seeding, generate, assert the next draw is unchanged.
