# S20-01 — `FloorPlanGenerator`

## Context

Floor plans are currently hand-drawn and uploaded. That works for a real operator surveying a real
building; it cannot work for the demo world, where `unit_number` is derived from site code and
class code and every `demo:seed --fresh` produces a fresh set. A static SVG committed to the repo
would match on the day it was drawn and drift silently afterwards.

This task adds a pure renderer: units in, SVG out. The demo seeder (task 03) is its first consumer.
Later it is also the fallback for operators who have no CAD drawing — "generate a starter plan,
then drag it around" is a far better empty state than "upload an SVG".

## Scope

**In:**
- `App\Support\Facility\FloorPlanGenerator` — static, stateless
- Deterministic layout: double-loaded rows, aisles, perimeter runs, entrance / lift core
- Output in the existing visual language (`.storage-unit` groups, `.unit` rects, `.unit-label`)
- Unit tests over geometry and markup contract

**Out:**
- Persisting anything (task 03)
- Reading the database — the generator takes an array, not models
- Panel rendering (task 04)
- Editing / drag-and-drop of generated plans
- Multi-building sites (the hierarchy is flat — `02-facility.md`)

## Schema changes

None.

## Implementation notes

`reference/FloorPlanGenerator.php` in this directory is a working implementation, verified to
produce well-formed XML that renders correctly. Adapt it rather than rewriting; the notes below are
the parts that carry decisions.

**Where it goes:** `app/Support/Facility/FloorPlanGenerator.php`, alongside the existing
`SiteMapIdMatcher` and `SvgSanitizer`. Static helpers, no state, no `app/Services/`.

**Signature:**

```php
public static function render(string $floorName, array $units, array $options = []): string
```

`$units` is a plain array of `['unit_number' => string, 'width_m' => float, 'depth_m' => float]`.
No models, no Eloquent, no container. That is what makes it unit-testable without a database and
reusable outside the seeder.

**Layout algorithm** (deterministic — same input, same bytes):

1. Sort units by depth desc, then width desc, then `unit_number` asc. The final key matters: it is
   what makes the output stable when two units have identical geometry.
2. Pack left to right into rows of `CONTENT_WIDTH` px at `SCALE` px per metre.
3. Justify each row flush to both walls by distributing slack; leave the final row ragged when it
   is less than ~72% full, so a short run looks like a short run rather than twelve absurdly wide
   units.
4. First row hugs the top wall, last row the bottom wall, interior rows are paired back-to-back
   with an aisle between blocks.
5. The first aisle carries an entrance vestibule (ground floor) or a lift core (upper floors),
   hung off the left wall inside the canvas gutter.

**Markup contract** — this is the part other code depends on:

```xml
<g class="storage-unit" id="MAD-01-SS3-07" data-unit-number="MAD-01-SS3-07" data-size="3.2×3.5">
  <rect class="unit" x="…" y="…" width="…" height="…" />
  <text class="unit-label" x="…" y="…">MAD-01-SS3-07</text>
  <text class="unit-size"  x="…" y="…">3.2×3.5 m</text>
</g>
```

- `id` **and** `data-unit-number` both carry the unit number, so both the legacy matcher and the
  one from task 02 resolve.
- Emit `viewBox` with **no** `width`/`height` attributes — the panel scales to its container.
- Rotate the label 90° when a unit is narrower than 46 px; drop the size line when the row is
  shallower than 52 px. Unreadable text is worse than no text.

**Do not emit `data-status`.** Unit state is derived (invariant 5) and would be stale the moment a
contract is signed. The panel stamps `data-status` at render time from `Availability::stateOn()`.
The stylesheet ships the selectors; the seeder ships no values.

**State vs overlay.** The stylesheet must key `data-status` to the real `UnitState` cases —
`available`, not `vacant`. Overdue and overlock are **not** unit states: an overlocked unit is
still occupied, and `HoldGuard` deliberately exempts `overlock` from blocking availability. They
get their own attributes so they compose over a status fill:

```css
.storage-unit[data-status="occupied"] .unit { fill: #dfe7f3; }
.storage-unit[data-overdue="1"]  .unit { fill: #ff8a8a; }
.storage-unit[data-overlock="1"] .unit { stroke: #cc0000; stroke-width: 3; }
```

**Structural ids.** Rows, the entrance and the lift core carry ids for debuggability. Task 02 scopes
matching to `[data-unit-number]`, so they are ignored — but if you implement 02 differently, these
become orphan shapes. Keep the two tasks consistent.

## API surface

None. No route, no controller, no request. A later sprint may expose
`POST /api/sites/{site}/maps/generate`; not now.

## Panel surface

None.

## Invariants

From `09-conventions-and-invariants.md`:

> **5. Derived state only** — never store `is_available`, balance owed, overdue flags […] as columns.

A stored SVG is a column. Baking `data-status` into it is the same violation in a different
container — the generator emits geometry and identity only.

> **No `app/Services/` layer** — shared helpers live under `App\Support\`.

`App\Support\Facility\` already holds `SiteMapIdMatcher` and `SvgSanitizer`; this joins them.

Mono-tenant (invariant 1): no company/tenant argument anywhere in the signature.

## Acceptance criteria

- [ ] `FloorPlanGenerator::render()` returns a string that `DOMDocument::loadXML()` parses without
      error, for 1, 2, 47 and 400 units.
- [ ] Every input unit appears exactly once as a `<g class="storage-unit">` carrying both `id` and
      `data-unit-number` equal to its `unit_number`.
- [ ] No `data-status` attribute appears anywhere in the output.
- [ ] Two calls with the same input produce byte-identical output.
- [ ] Reordering the input array does not change the output.
- [ ] No unit rect overlaps another (assert on parsed rect geometry, not by eye).
- [ ] All shapes fall inside the declared `viewBox`.
- [ ] The output survives `SvgSanitizer::sanitize()` with `data-unit-number` intact on every shape.
- [ ] Unit numbers containing XML-significant characters are escaped, not emitted raw.
- [ ] `render()` performs no database access and no `mt_rand()` / `fake()` call — grep the file.

## Tests required

`tests/Unit/Support/Facility/FloorPlanGeneratorTest.php`

| Test | Asserts |
|---|---|
| `renders_well_formed_svg` | `loadXML` succeeds; root is `<svg>` with a `viewBox` and no `width` |
| `every_unit_appears_exactly_once` | Shape count equals input count; `data-unit-number` set matches input set |
| `id_and_data_attribute_agree` | For each shape, `id === data-unit-number` |
| `emits_no_status_attribute` | `data-status` absent from the document |
| `output_is_deterministic` | Two renders are identical strings |
| `input_order_does_not_matter` | Shuffled input renders identically |
| `no_overlapping_rects` | Pairwise rect intersection is empty |
| `all_shapes_inside_viewbox` | Every rect within the declared bounds |
| `survives_sanitizer_round_trip` | After `SvgSanitizer::sanitize()`, shape count and join attribute unchanged |
| `escapes_unit_numbers` | Input `A&B<1>` emits escaped entities and still parses |
| `handles_single_unit` | One unit renders without division by zero or an empty band |
| `handles_four_hundred_units` | 400 units render in under 250 ms and produce 400 shapes |
