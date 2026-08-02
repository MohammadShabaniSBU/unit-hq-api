# S13-01 — Email builder v2

## Context

The "needs some improvements" from the first brief, made concrete: a **block-based
editor** producing a JSON document, rendered server-side to email-safe HTML. Blocks
beat free HTML for exactly this product: operators aren't designers, tokens need
first-class slots, and the same document renders per-locale variant without layout
drift between languages.

## Scope

**In:** block vocabulary + JSON schema, server renderer (the golden-file target),
editor UI with variant tabs, preview (desktop/mobile/test-send), token insertion,
legacy-import block. **Out:** image *hosting* pipeline beyond upload-to-private +
public-serve route for email assets (one small piece in scope — emails need public
image URLs; a `template-assets` disk + hashed public route), drag-drop between
non-adjacent positions (arrow reorder, the house pattern), custom fonts/branding
theming (deferred: one config accent color ships).

## The block vocabulary (v1, closed set)

| Block | Params |
|---|---|
| `heading` | text (tokens ok), level 1-2 |
| `paragraph` | rich-lite text: bold/italic/links + tokens |
| `button` | label, url (tokens ok — the payment-link slot), style primary/outline |
| `image` | asset ref, alt, width % |
| `divider` / `spacer` | — / height |
| `unit_summary` | *smart block*: renders the context contract's unit + rate from tokens; placeholder in preview |
| `raw_html` | legacy-import only — not insertable fresh, editable-in-place with a warning |

Document: `{ version: 1, blocks: [{id, type, params}] }` validated server-side
(unknown types rejected — forward-compat by version bump, not silent tolerance).

## Renderer

`App\Support\Communications\EmailBlockRenderer::render(array $doc, TokenContext $ctx):
{html, text}` — tables + inline styles, 600px centered, bulletproof-button pattern,
alt-texted images, auto-generated text part (blocks → plain text, buttons as
`label: url`). Golden files per block and one kitchen-sink document; the fixture
comment names Outlook as the target. Accent color from org config. The `es`/`en`
variants of one family share nothing at render time — variants are whole documents
(copy-from-variant button in the editor is the convenience, not a linkage).

## Panel surface

Marketing → Templates (email) reworked: family list (name, purpose, variant-locale
chips, usage count from playbook refs); editor page: variant tabs (`es · en · + añadir
idioma`, copy-from prompt on create), block list with insert-between affordances,
per-block param panels, token menu (the S11 vocabulary component reused), autosave
draft vs explicit publish? **No versioning drama v1**: direct save with "sends render
the latest saved content" helper text (the render-at-send behaviour documented, not
changed). Preview pane: desktop/mobile toggle rendering the *real* server output
(iframe the renderer endpoint — never a client-side approximation; the S02-03 preview
lesson, builder edition), sample-context selector (pick a seeded contact/contract for
token realism), **Send test** to an operator-entered address via the real sender with
`class: transactional`, `source: system`.

i18n `templates.builder.*`; es-first: block names, *Enviar prueba*, *Añadir idioma*.

## Acceptance criteria

- [ ] All block types round-trip editor→JSON→render; golden files green; kitchen-sink
      renders in a real mailbox sanely (manual check noted in PR with screenshot).
- [ ] Variant tabs + copy-from + the resolver ladder verified through a playbook send
      to contacts with different locales (the exit-criterion fixture).
- [ ] Preview iframe = sent output byte-comparably (same endpoint); test-send arrives
      with tokens resolved from the sample context.
- [ ] Legacy template opened in the builder shows the raw_html block + warning; saving
      preserves rendering (00's fixture extended).
- [ ] Asset upload → public hashed URL → renders in external mailbox; asset delete
      blocked while referenced.
- [ ] Unknown block version/type rejected at save with a translatable error.

## Tests required

| Test | Asserts |
|---|---|
| `BlockRendererTest::golden_per_block_and_kitchen_sink` | The Outlook target |
| `BlockDocTest::schema_validation_closed_set` | Forward-compat posture |
| `BuilderFlowTest::preview_equals_send` | One renderer, two consumers |
| `AssetTest::upload_serve_reference_guard` | The small pipeline |
| Panel manual script | Tabs, copy-from, test-send, legacy warning |
