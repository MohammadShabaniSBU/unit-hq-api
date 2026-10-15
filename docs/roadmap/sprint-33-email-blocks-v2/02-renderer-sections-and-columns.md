# S33-02 — Renderer: sections & columns

## Context

`EmailBlockRenderer::render()` iterates `$doc['blocks']` and emits one `<tr>` per
block inside a fixed 600px table. v2 needs sections (full-width bands) containing
1–3 columns that sit side by side on desktop and stack on mobile, including in
Outlook desktop, which ignores media queries and most modern CSS.

## Scope

**In:** v2 rendering, mobile stacking, Outlook ghost tables, section params, plain-text
output for columns, golden fixtures, render matrix.
**Out:** new block partials (03), dark-mode-specific styling (record as follow-up).

## Schema changes

None.

## Implementation notes

- `render()` accepts v1 or v2. v1 → `EmailBlockDocument::upcast()` in memory, then the
  v2 path. One rendering path, so v1 golden files must still match (allowing only
  the wrapper markup to change — update goldens in one reviewed commit and say so in
  the PR).
- Structure per section:
  ```
  <tr><td style="background:{bg};padding:{pad}">         section band
    <!--[if mso]><table role="presentation" width="100%"><tr><td width="{w1}" valign="top"><![endif]-->
    <div class="kv-col kv-col-{preset}" style="display:inline-block;width:100%;max-width:{px};vertical-align:top">
      <table role="presentation" width="100%"> …blocks as today's <tr> rows… </table>
    </div>
    <!--[if mso]></td><td width="{w2}" valign="top"><![endif]-->
    …
    <!--[if mso]></td></tr></table><![endif]-->
  </td></tr>
  ```
  This is the standard hybrid ("fluid-hybrid") pattern: inline-block divs with
  max-width for modern clients, MSO conditional tables for Outlook.
- Column pixel widths come from the preset against the 600px container minus section
  padding. Compute in one helper (`EmailLayout::columnWidths(preset, padding)`) with
  integer pixels that sum exactly to the inner width.
- Mobile stacking: a `<style>` block in `<head>` with
  `@media (max-width: 480px) { .kv-col { max-width:100% !important; display:block !important; } }`.
  When `stack_on_mobile = false` omit the class so columns stay side by side.
- Block partials (`heading`, `paragraph`, …) currently pad `24px` left/right for a
  full-width row. Inside columns, use a smaller horizontal padding (`12px`) passed as
  a render option, so text doesn't get squeezed. Keep full-width unchanged.
- `padding` tokens: `none 0`, `sm 12px`, `md 24px`, `lg 40px` (vertical and horizontal).
- Background colours go through the existing `sanitizeColor()`.
- Plain-text output: sections in order, columns in order, blank line between columns.
- Preview markers (`$previewMarkers`) keep working inside columns.
- Golden fixtures: `tests/Fixtures/email-render/v2/{name}.json` → `{name}.html` and
  `{name}.txt` for every preset, with and without `stack_on_mobile`, and a v1
  document through the upcast path.

### Render matrix

Before closing the task, send the fixtures through Litmus or Email on Acid (or real
inboxes if no account) and record results in this sprint folder as
`render-matrix.md`: Outlook 2016/2019/365 Windows, Outlook web, Gmail web, Gmail
iOS and Android, Apple Mail macOS and iOS. Any blocking issue is fixed in this task;
cosmetic issues are listed.

## API surface

None (preview and test-send endpoints use the renderer unchanged).

## Panel surface

None (the panel preview iframe shows server-rendered HTML).

## Invariants

- The renderer is the only producer of email HTML from blocks; the panel never
  renders final HTML itself (S13 standing rule: preview iframe uses the real renderer).

## Acceptance criteria

- [ ] All golden fixtures match.
- [ ] Column widths sum exactly to the inner width for every preset and padding.
- [ ] v1 documents render through the upcast path with only wrapper changes.
- [ ] Render matrix recorded, with no blocking issue open.
- [ ] Plain text keeps reading order.

## Tests required

| Test | Asserts |
|---|---|
| `EmailBlockRendererV2Test::golden_files` | Every v2 fixture |
| `EmailBlockRendererV2Test::v1_through_upcast` | Single rendering path |
| `EmailLayoutTest::column_widths_sum_exactly` | All presets × paddings |
| `EmailBlockRendererV2Test::stack_on_mobile_false_omits_class` | Stacking switch |
| `EmailBlockRendererV2Test::text_reading_order` | Plain-text order |
