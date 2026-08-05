# S20-02 — Shape matching on `data-unit-number`

## Context

`SiteMapIdMatcher::extractIds()` collects every `//*[@id]` in the document and intersects the result
with `units.unit_number`. Two things follow, both currently invisible because upload still returns
201:

1. **Real floor plans do not match.** The plans we author put the unit number in
   `data-unit-number` and give the group a structural id (`id="unit-1"`). Every shape is reported
   as an orphan and every unit as uncovered. An operator uploading a correct map is told it is
   entirely wrong.
2. **Structural ids are reported as orphan shapes.** `id="layer1"`, `id="row-3"`, `id="rect47"` —
   an Inkscape export is full of them. Even a map that *does* use unit numbers as ids reports
   dozens of phantom orphans, which makes the validator's output untrustworthy and therefore
   ignored.

`02-facility.md` documents the id convention, so the doc is wrong too, not just the code.

## Scope

**In:**
- `data-unit-number` becomes the primary join key; `id` stays a fallback for legacy maps
- Structural ids stop being counted as shapes
- `02-facility.md` and `09-conventions-and-invariants.md` updated to match
- Tests covering both map dialects

**Out:**
- A `site_map_shapes` join table (deferred — record in `10-open-decisions.md`)
- Changing the response shape of `id_match` — the three buckets stay `matched`,
  `orphan_shapes`, `uncovered_units`
- Renaming the class (`SiteMapIdMatcher` keeps its name this sprint; renaming it churns the
  controller, resource and tests for no behavioural gain)

## Schema changes

None.

## Implementation notes

**Resolution rule.** In `SiteMapIdMatcher::extractIds()`:

1. Query `//*[@data-unit-number]`. If the document contains at least one, that set **is** the shape
   set — ids are ignored entirely.
2. Only if the document contains none, fall back to the current `//*[@id]` behaviour, for maps
   drawn against the old convention.

Do not merge the two sets. A document that uses `data-unit-number` and also has `id="layer1"` must
not report `layer1` as an orphan — and a generated plan sets both attributes on the same element,
so merging would double-count unless you dedupe by value, which is accidental correctness.

**XPath and namespaces.** The documents are loaded with `loadXML()` and carry the SVG namespace.
`//*[@data-unit-number]` works because the *attribute* is unprefixed; do not register a prefix for
it. Keep the existing `libxml_use_internal_errors()` handling and the "unparseable returns empty
array" contract — the controller relies on it.

**Trim and dedupe** exactly as the id path already does: trim whitespace, drop empties,
`array_unique`. Two shapes carrying the same unit number is an authoring error that should surface
as one match, not two.

**Signature.** Keep `extractIds(string $svg): array` as-is if you want the smallest diff, or add
`extractShapeRefs()` and make `extractIds()` delegate. Either is fine; `SiteMapController::store()`,
`update()` and `validateSvg()` all call `match()` and are unaffected.

**Docs.** `02-facility.md`, Site maps section, replace the id-matching paragraph:

> **Shape-matching convention:** SVG shapes carry `data-unit-number` matching `units.unit_number`
> for the same `site_id`. Maps with no `data-unit-number` anywhere fall back to matching element
> `id`, for plans drawn against the pre-S20 convention. Structural ids (layers, rows, groups) are
> never treated as shapes. Upload validation reports three buckets — `matched`, `orphan_shapes`,
> `uncovered_units`.

Add to `09-conventions-and-invariants.md`:

> **Floor map shapes join on `data-unit-number`.** `id` is a fallback for legacy maps only.
> A map is never partially matched across both conventions in one document.

## API surface

Unchanged. `POST /api/sites/{site}/maps`, `PATCH /api/site-maps/{siteMap}` and
`POST /api/sites/{site}/maps/validate` keep returning `id_match` with the same three keys — the
values simply become correct.

Worth confirming while you are here: `validate` must not persist anything, and `store` must run the
match against the **sanitized** SVG, not the raw input. It does today; keep it that way.

## Panel surface

None in this task. The upload screen already renders the three buckets; it will start showing
truthful numbers.

## Invariants

- Invariant 5 — matching reads the SVG and the units table; nothing about the match result is
  stored. `id_match` stays a computed attribute on the resource.
- Sanitizer contract — `enshrined/svg-sanitize` 0.22 preserves `data-*` (verified:
  `Sanitizer::isDataAttribute()`). Do not add a custom attribute filter that would break this.

## Acceptance criteria

- [ ] An SVG whose shapes carry `data-unit-number` matching real units reports those units as
      `matched` and returns `orphan_shapes = []`.
- [ ] That same SVG's structural ids (`unit-1`, `row-3`, `layer1`) appear in no bucket.
- [ ] A legacy SVG using `id="{unit_number}"` and no `data-unit-number` still matches, unchanged
      from today's behaviour.
- [ ] A document mixing both on the same element counts each shape once.
- [ ] A unit number present at another site is reported as an orphan shape, not a match —
      matching stays scoped to `$site->units()`.
- [ ] Unparseable SVG still returns three empty arrays rather than throwing.
- [ ] `storage-map2.svg` (the hand-authored plan in `docs/`) matches its site's units instead of
      reporting 100% orphans.
- [ ] `02-facility.md` and `09-conventions-and-invariants.md` updated.

## Tests required

`tests/Feature/Facility/SiteMapMatchingTest.php`

| Test | Asserts |
|---|---|
| `matches_on_data_unit_number` | Shapes with the attribute resolve to units |
| `structural_ids_are_not_shapes` | `layer1` / `row-3` in no bucket |
| `falls_back_to_id_for_legacy_maps` | Old convention still matches |
| `does_not_mix_conventions` | Document with both → one shape per element, no phantom orphans |
| `unit_from_another_site_is_an_orphan` | Site scoping holds |
| `uncovered_units_reported` | Units with no shape land in the third bucket |
| `unparseable_svg_returns_empty_buckets` | No exception, three empty arrays |
| `validate_endpoint_persists_nothing` | `site_maps` row count unchanged after `POST …/maps/validate` |
| `store_matches_against_sanitized_svg` | Attribute stripped by the sanitizer cannot inflate `matched` |
