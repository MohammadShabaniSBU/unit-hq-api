# S33-03 — New blocks & brand settings

## Context

The v1 set (`heading`, `paragraph`, `button`, `image`, `divider`, `spacer`,
`unit_summary`, `raw_html`) can't express the things self-storage emails say most:
a list of figures (balance, due date, unit, gate hours), a warning (overdue, lien,
auction), a branded header, a footer with social links. Without them every email —
hand-built or assistant-built — looks like a plain letter.

The only brand setting today is `GeneralSettings::email_accent_color`.

## Scope

**In:** five new block types (validator params + renderer partials), four brand
settings, settings UI card.
**Out:** canvas components and settings forms for the new blocks (04 builds them
against this task's params), legal footer (marketing sends append it — never a block).

## Schema changes

None for blocks (JSON). Brand settings extend the existing settings payload; follow
how `email_accent_color` is stored.

## Implementation notes

### Blocks

Add to `EmailBlockDocument::TYPES` and `validateParams()`; add a partial to
`EmailBlockRenderer`. All text params accept tokens (resolved by the renderer as
today).

| Type | Params (Laravel rules) | Rendering |
|---|---|---|
| `info_rows` | `rows`: array 1–12 of `{label: string max 120, value: string max 255}`; `striped`: bool; `value_align`: `left\|right` | Two-cell table rows; money tokens formatted by the renderer's existing money formatting; plain text `Label: value` per line |
| `list` | `style`: `bullet\|numbered`; `items`: array 1–20 of string (restricted inline HTML, same sanitiser as `paragraph.html`) | Table-based list (not `<ul>`, Outlook spacing); text `- item` / `1. item` |
| `callout` | `tone`: `info\|warning\|danger`; `title`: nullable string max 120; `html`: string (paragraph sanitiser) | Bordered box, tone colours from a fixed palette (not brand); text prefixed with the title |
| `logo_header` | `align`: `left\|center`; `max_width`: int 60–300; `link_url`: nullable string max 2000 | Uses the brand logo asset; renders nothing (and a preview marker) when no logo is set |
| `footer_social` | `links`: array 0–6 of `{network: facebook\|instagram\|linkedin\|x\|youtube\|tiktok\|website, url}`; `address`: nullable string max 500 (tokens allowed) | Small grey text row; icons as hosted PNGs from `template-assets` public route (no remote images) |

- `button.url`, `logo_header.link_url`, `footer_social.links.*.url`: `https:`,
  `mailto:`, `tel:`, or a token of type `url` from `TokenCatalog`. New error key
  `templates.link_invalid_scheme`.
- Social icon PNGs: ship once as seeded `template_assets` rows (system-owned) so
  emails reference our public asset route, never a third-party CDN.
- All five types are **email-only**; `DocumentBlockDocument` unchanged.

### Brand settings

Extend `App\Settings\GeneralSettings` (and `SettingController` validation) with:

| Key | Rule | Default |
|---|---|---|
| `email_logo_asset_id` | nullable, exists in `template_assets` | null |
| `email_text_color` | `#RRGGBB` | `#111827` (current hard-coded heading colour) |
| `email_background_color` | `#RRGGBB` | `#F3F4F6` (current body background) |
| `email_font_stack` | `in:` a fixed list (`system`, `arial`, `georgia`, `verdana`, `trebuchet`) | `arial` |

The renderer reads them through one `EmailBrand::current()` value object (defaults
equal today's hard-coded values, so existing goldens don't change when unset).
Changing brand settings logs the existing settings activity (no new event).

## API surface

| Endpoint | Change |
|---|---|
| `PUT settings` (existing general settings route) | Accepts the four new keys |
| `GET settings` | Returns them, plus `email_logo_url` resolved from the asset |

## Panel surface

- Settings → General → new **Email brand** card: logo upload (existing template-asset
  upload), accent (moved here from wherever it is now), text colour, background
  colour, font. Live mini-preview using the preview endpoint.
- i18n en/es/fr.

## Invariants

- No remote images in rendered email except our own asset route.

## Acceptance criteria

- [ ] Each new block validates its params and renders into golden fixtures, full width
      and inside a `50_50` column.
- [ ] A `javascript:` URL in any URL param is rejected with `templates.link_invalid_scheme`.
- [ ] With no brand settings saved, all pre-existing goldens are unchanged.
- [ ] `logo_header` without a logo renders nothing in send output and a marker in preview.
- [ ] Contract document validation rejects the new types.

## Tests required

| Test | Asserts |
|---|---|
| `EmailBlockParamsTest::{info_rows,list,callout,logo_header,footer_social}` | Rules per type |
| `EmailBlockRendererV2Test::new_blocks_golden` | Full width and in columns |
| `EmailBlockParamsTest::url_scheme_allowlist` | `https`, `mailto`, `tel`, url tokens only |
| `EmailBrandTest::defaults_match_legacy_output` | No golden drift when unset |
| `DocumentBlockDocumentTest::rejects_email_only_types` | B6 |
