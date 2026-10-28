# S33-06 — Invariants, docs & tests

## New invariants (`docs/09-conventions-and-invariants.md`)

> Numbering assumes `09` ends at **90** (S32). Renumber here, once, if it doesn't.

91. **Email block documents have a fixed hierarchy.** v2 documents are
    `sections → columns → blocks`; a section never sits inside a column and columns
    never nest. Layouts are the presets `100 | 50_50 | 33_67 | 67_33 | 33_33_33`;
    column count matches the preset. `raw_html` appears only in a `100` section.
    Illegal documents are rejected with an error key, never repaired silently. All
    writes store v2; v1 is read-only and is upcast only when a new draft is
    created — published variants are never rewritten (invariant 74).
    *Enforced by* `EmailBlockDocumentV2Test`, `TemplateVariantUpdateTest::rejects_v1_write`,
    `TemplateVersionDraftTest::draft_from_v1_published_is_v2`.
92. **`TokenCatalog` is the single source of template tokens.** The builder token
    menu, inbox composer, publish-time sample context and (S34) the template
    assistant read `App\Support\Templates\TokenCatalog`. No other list of token
    paths or sample values exists. Every catalogue path must resolve through the
    send-time token bags.
    *Enforced by* `TokenCatalogTest::every_path_resolves`,
    `TemplatePublishValidatorTest::uses_catalogue_samples`.

## Docs

- `06-communications.md` — template section: document v2 shape, layouts, new block
  types, brand settings, `TokenCatalog` and the final purpose × token availability
  table from 01.
- `01-stack.md` — email builder line: sections/columns, undo, block types.
- `10-open-decisions.md`:
  - **Decided:** B1–B8 (short form, link to this sprint).
  - **Undecided:** dark-mode email styling; columns in contract documents; panel
    unit-test runner (Vitest for `app/utils/` — adopt in CI or not).
- `AGENTS.md` — routing row "Email blocks / token catalogue → `06-communications.md`".
- `docs/roadmap/sprint-32-paid-advertising-google-ads-meta/README.md` — "Next"
  section now says S36.

## Test matrix

| Area | Test class | Key cases |
|---|---|---|
| Validator | `EmailBlockDocumentV2Test` | Fixtures, nesting, ids, raw_html rule |
| Upcast | `EmailBlockUpcastTest`, `TemplateVersionDraftTest` | Determinism, location, invariant 74 |
| Schema parity | `EmailBlockSchemaParityTest` | JSON Schema ≡ PHP |
| Tokens | `TokenCatalogTest`, `TemplateTokensEndpointTest` | Resolution, availability |
| Renderer | `EmailBlockRendererV2Test`, `EmailLayoutTest` | Goldens, widths, text order |
| Blocks | `EmailBlockParamsTest` | Five new types, URL schemes |
| Brand | `EmailBrandTest` | Defaults = legacy output |
| Seed | `DemoSeedTemplatesTest`, `EmailDocBuilderTest` | v2 everywhere, determinism |

## Acceptance criteria

- [x] Invariants 91–92 in `09` with test names that exist.
- [x] Docs above updated.
- [x] `render-matrix.md` committed in this sprint folder.
- [ ] Sprint README exit criteria met on a freshly seeded database.
- [ ] `php artisan test`, `bun run lint`, `bun run typecheck` green.
