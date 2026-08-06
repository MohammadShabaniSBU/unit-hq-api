# Sprint 20 — Demo floor plans

## Goal

`php artisan demo:seed --fresh` leaves every demo site with a complete set of floor plans whose
shapes resolve 1:1 against that site's units. The unit map becomes a demonstrable surface instead
of an empty page, and the map-upload validator gets exercised against real data for the first time.

## Why now

Three things are true in `dev` today:

1. **`site_maps` is never populated by the demo world.** `StageSeeder` creates 5 sites, 20 unit
   classes and 2 000 units, and zero maps. Anything in the panel that renders a floor plan has
   nothing to render, and no demo can show it.
2. **`SiteMapIdMatcher` cannot match the SVGs we actually author.** It collects `//*[@id]` and
   intersects with `units.unit_number`. Hand-authored plans put the unit number in
   `data-unit-number` and use `id="unit-1"`, so the matcher reports **every shape as an orphan and
   every unit as uncovered** — while still returning 201. It also counts structural ids
   (`row-3`, `layer1`, `entrance`) as orphan shapes.
3. **Nothing has ever round-tripped a real map through `SvgSanitizer`.** The demo world is where
   that gets proven cheaply, on 20 documents, every time someone reseeds.

Fixing 2 without doing 3 leaves the matcher untested against anything but fixtures. Doing 1 without
2 produces 20 maps that the validator reports as 100% broken.

## Verified facts this sprint depends on

Checked against `dev` — do not re-derive:

| Fact | Where |
|---|---|
| `site_maps` = `site_id`, `floor_name`, `svg_map` (text), `sort_order`; unique `(site_id, floor_name)` | `2026_08_05_000590_create_site_maps_table.php` |
| 5 demo sites: `MAD-01`, `BCN-01`, `VLC-01`, `LON-01`, `PAR-01` | `StageSeeder` |
| 20 unit classes (`SS1..SS10`, `AL1..AL10`) × 20 units × 5 sites = **400 units per site, 2 000 total** | `StageSeeder` |
| `unit_number` format is `{SITE_CODE}-{CLASS_CODE}-{NN}`, e.g. `MAD-01-SS3-07` | `StageSeeder` |
| Unit geometry lives on `units.actual_width` / `actual_depth` (metres). `unit_classes` carries `size` (m²) only — **there are no class W×D columns** | `StageSeeder`, `UnitClass` |
| `enshrined/svg-sanitize` 0.22 explicitly preserves `data-*` and `aria-*` attributes, plus `class`, `id`, `viewBox`, `transform`, `<style>`, `<title>`, `<text>` | `Sanitizer::isDataAttribute()`, `data/AllowedAttributes.php` |
| `Availability::stateOn()` and the `UnitState` enum (`available`, `occupied`, `reserved`, `maintenance`, `damaged`, `staff_use`, `other`) already exist | `App\Support\Occupancy\Availability` |

## Exit criteria

- [x] After `php artisan demo:seed --fresh`, `site_maps` holds 20 rows — 4 floors for each of the
      5 sites — with `sort_order` 0..3.
- [x] For every seeded map, `SiteMapIdMatcher::match()` returns `orphan_shapes = []` and, across a
      site's four floors, `uncovered_units = []`.
- [ ] `POST /api/sites/{site}/maps/validate` with a hand-authored SVG using `data-unit-number`
      reports real matches instead of a total miss.
- [ ] Structural ids (`row-3`, `entrance`, `lift-core`) are never reported as orphan shapes.
- [x] Every stored `svg_map` is the output of `SvgSanitizer::sanitize()`, and a test proves the
      unit join survives that round trip.
- [ ] `demo:seed --fresh` wall clock has not regressed by more than 5 s.
- [ ] Re-running `demo:seed --fresh` with the same `DEMO_SEED` produces byte-identical maps.

## Task order

Strictly sequential — 03 cannot be verified before 02 is correct.

| # | Task | Est. |
|---|---|---|
| 01 | [Floor plan generator](./01-floor-plan-generator.md) | 1 day |
| 02 | [Shape matching on `data-unit-number`](./02-shape-matching.md) | 0.5 day |
| 03 | [Demo world floor plans](./03-demo-world-floor-plans.md) | 0.5 day |
| 04 | [Panel: floor view](./04-panel-floor-view.md) | 1 day |

`reference/FloorPlanGenerator.php` is a working implementation of task 01, already producing
well-formed SVG in the existing visual language. Adapt it; do not start from scratch.

## Risks

**The demo RNG stream is load-bearing.** `StageSeeder` seeds `mt_srand($rngSeed)` and `fake()->seed()`
once, then every subsequent `mt_rand()` / `fake()` call consumes from one stream. Cast personas
hard-require specific units at specific sites late in the 14-month window. **If floor plan
generation consumes a single random number, every downstream draw shifts and the demo world
changes.** Task 03 therefore forbids RNG in the generation path entirely — floor assignment is a
deterministic sort, not a shuffle. This is the one thing in this sprint that can break unrelated
tests.

**2 000 shapes across 20 documents.** Roughly 1.2 MB of SVG text into Postgres. That is fine to
store and fine to generate, but `GET /api/sites/{site}/maps?with_svg=1` returning four floors at
once is ~250 KB. Task 04 must fetch one floor at a time.

**Scope creep into a shape join table.** Resolving shapes into a `site_map_shapes` table at upload
(so the map endpoint stops re-parsing XML, and renames surface instead of vanishing) is the right
long-term shape. It is **not** in this sprint — record it in `10-open-decisions.md` under Undecided
and move on.
