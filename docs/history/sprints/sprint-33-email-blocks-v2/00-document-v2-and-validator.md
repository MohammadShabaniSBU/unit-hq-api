# S33-00 — Document v2 schema & validator

## Context

`EmailBlockDocument::validate()` accepts only `version: 1` and a flat `blocks` list.
v2 adds sections and columns (README B1–B5). The validator is the gate for draft
saves, publish, and — in S34 — every assistant tool call, so it must return
precise, machine-readable errors.

## Scope

**In:** v2 shape, layouts, id rules, channel rules for `raw_html`, v1 → v2 upcast on
draft creation, JSON Schema file for type generation, error keys.
**Out:** new block types (03), rendering (02), panel (04).

## Schema changes

None. `template_variants.blocks` is already JSON.

## Implementation notes

- Keep the class `App\Support\Communications\EmailBlockDocument`. Add
  `VERSION_2 = 2`, keep `VERSION = 1` as `VERSION_1` (rename the constant and update
  call sites).
- `validate(mixed $doc): array` dispatches on `version`:
  - `1` → current validation, unchanged.
  - `2` → `validateV2()`.
  - anything else → `blocks_unknown_version` (unchanged behaviour).
- `validateV2()` checks, in order, and stops at the first failure with a
  `ValidationException` keyed by dotted path
  (`blocks.sections.1.columns.0.blocks.2.params.url`):
  1. `sections` is a list (may be empty).
  2. Each section: id pattern `^sec_[0-9a-f]{8}$`, `layout` in the preset list,
     `params` valid (`background_color` `#RRGGBB` or null, `padding` in
     `none|sm|md|lg`, `stack_on_mobile` bool, default true).
  3. `count(columns)` equals the layout's column count.
  4. Each column: id pattern `^col_[0-9a-f]{8}$`, `blocks` is a list.
  5. Each block: id pattern `^blk_[0-9a-f]{8}$`, `type` in `TYPES`, params via the
     existing `validateParams()` (extended in 03).
  6. `raw_html` only in a section with layout `100`.
  7. All ids unique across the document.
- New error keys in `lang/*/errors.php` under `templates.`:
  `blocks_illegal_nesting`, `blocks_layout_mismatch`, `blocks_duplicate_id`,
  `blocks_invalid_id`, `blocks_raw_html_in_columns`, `blocks_unknown_layout`.
- `EmailBlockDocument::upcast(array $v1): array` — pure function. One section,
  layout `100`, default params, one column holding the v1 blocks in order. Section
  and column ids are derived deterministically from the variant (e.g. first 8 hex
  of `sha1("sec:{first_block_id}")`) so repeated upcasts of the same content give
  the same ids. Block ids that don't match the v2 pattern are re-derived the same
  way; keep a map so nothing else references the old id (nothing does today —
  verify with grep).
- Upcast is applied in **one place**: where `POST template-families/{f}/versions`
  copies variants from the based-on version into the new draft. Published rows are
  never touched (invariant 74).
- `updateVariant` accepts only v2 for writes. A v1 payload returns 422
  `blocks_version_write_v1`.
- JSON Schema: `resources/schemas/email-block-document.v2.json` (2020-12) describing
  the shape above. It is the source for panel type generation (04). PHP validation
  stays in `EmailBlockDocument` (Laravel validator), and a test asserts the schema and
  the PHP validator agree on the shared fixtures.
- Fixtures: `tests/Fixtures/email-blocks/v2/valid/*.json` and `invalid/*.json`, one
  invalid fixture per error key. The panel reads the same folder (copied by the
  type-gen script).

## API surface

No new endpoints. Changed behaviour:

| Endpoint | Change |
|---|---|
| `POST template-families/{f}/versions` | Draft variants are stored as v2 (upcast when the source is v1) |
| `PUT template-families/{f}/variants/{v}` | Rejects v1 writes; validates v2 |
| `GET template-families/{f}/versions/{v}` | Returns what is stored (v1 for old published versions) — the panel renders v1 read-only via upcast in memory |

## Panel surface

None in this task (04).

## Invariants

- **74** — published content is immutable. The upcast happens only on the new draft.
- **5** — derived state is never stored: section/column ids are content, not derived
  values, so storing them is fine; do not store "column count" or similar.

## Acceptance criteria

- [ ] Every fixture in `v2/valid` passes; every fixture in `v2/invalid` fails with
      its expected error key and path.
- [ ] A v1 document still validates and renders (existing tests unchanged).
- [ ] Creating a draft from a v1 published version stores v2 in the draft; the
      published variant row is byte-identical before and after.
- [ ] Upcasting the same v1 document twice gives identical ids.
- [ ] `PUT …/variants/{v}` with a v1 body returns 422 `blocks_version_write_v1`.
- [ ] The JSON Schema accepts all valid fixtures and rejects all invalid ones.

## Tests required

| Test | Asserts |
|---|---|
| `EmailBlockDocumentV2Test::valid_fixtures_pass` | Every valid fixture |
| `EmailBlockDocumentV2Test::invalid_fixtures_fail_with_key` | One per error key, with path |
| `EmailBlockDocumentV2Test::raw_html_only_in_full_width` | B5 |
| `EmailBlockDocumentV2Test::ids_unique_across_document` | Duplicate block id in two columns fails |
| `EmailBlockUpcastTest::v1_to_single_section_deterministic` | Same input, same ids |
| `TemplateVersionDraftTest::draft_from_v1_published_is_v2` | Upcast location; published row untouched |
| `TemplateVariantUpdateTest::rejects_v1_write` | B3 |
| `EmailBlockSchemaParityTest::json_schema_matches_php_validator` | Shared fixtures |
