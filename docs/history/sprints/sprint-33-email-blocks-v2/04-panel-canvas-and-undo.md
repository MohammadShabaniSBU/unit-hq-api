# S33-04 — Panel: canvas v2 & undo

## Context

`app/components/email-builder/EditorCanvas.vue` renders a flat `blocks` array with
`vue-draggable-plus` and up/down arrows; `BlockPalette.vue` adds blocks;
`BlockSettings.vue` edits the selected block. `[id].vue` owns `blocks` and
`selectedBlockId`. There is no undo.

v2 needs sections and columns in the canvas, a three-level selection, and an undo
stack. S34's assistant will push its turns onto the same stack, so undo lands here.

## Scope

**In:** generated types, canvas sections/columns, layout picker, selection model,
settings panel for section/column, components and settings forms for the five new
blocks, undo/redo, v1 read-only display via in-memory upcast.
**Out:** assistant UI (S34), HTML mode.

## Schema changes

None.

## Implementation notes

### Types

- `bun run gen:email-block-types` reads
  `unit-hq-api/resources/schemas/email-block-document.v2.json` (path configurable by
  env, default sibling repo) and writes `app/types/email-block-document.ts`. Generated
  file is committed. Arrays as `Array<T>` (post-process the generator output if it
  emits `T[]`).
- `app/types/email-builder.ts` re-exports and keeps UI-only types.
- A pure `upcastV1(doc)` in `app/utils/emailBlocks.ts`, mirroring the PHP upcast,
  tested against the same fixtures.

### Document operations

All mutations go through one module, `app/utils/emailBlockOps.ts`:

```ts
type BlockOp =
  | { op: 'add_section', index: number, section: EmailSection }
  | { op: 'remove_section', section_id: string }
  | { op: 'move_section', section_id: string, to_index: number }
  | { op: 'set_section_layout', section_id: string, layout: EmailLayout }
  | { op: 'update_section', section_id: string, params: Partial<EmailSectionParams> }
  | { op: 'insert_blocks', column_id: string, index: number, blocks: Array<EmailBlock> }
  | { op: 'update_block', block_id: string, params: Record<string, unknown> }
  | { op: 'move_block', block_id: string, to_column_id: string, to_index: number }
  | { op: 'remove_block', block_id: string }

applyOps(doc, ops): { doc, inverse: Array<BlockOp> }
```

- Pure functions, no Vue. Every op returns its inverse so undo is "apply the
  inverse". This op vocabulary is the one S34's API emits — keep names stable.
- `set_section_layout` shrinking columns moves blocks from removed columns to the end
  of the last kept column (document this; S34 uses the same rule server-side).
- Canvas components never mutate `doc` directly; they emit ops.

### Undo

- `useEditorHistory(docRef)`: stack of `{ ops, inverse, label }` entries, max 100.
  Typing in a text field coalesces into one entry per field per 1 s of inactivity.
- Ctrl/Cmd+Z, Ctrl/Cmd+Shift+Z, and toolbar buttons.
- Stack resets on locale switch and on draft reload. Save does not reset it.

### Canvas

- Section = full-width band with a hover toolbar: layout, move up/down, duplicate,
  delete. Columns = drop zones (`VueDraggable` with a shared `group` so blocks drag
  between columns and sections). Empty column shows **Add block**.
- **Add section** between sections opens a layout picker (five preset thumbnails).
- Selection: `{ kind: 'section' | 'column' | 'block', id }`. Settings panel shows a
  breadcrumb `Section › Column 2 › Paragraph`, each segment clickable.
- Section settings: layout, background, padding, stack on mobile. Column has no
  settings in v1 (breadcrumb only).
- Keyboard: Alt+↑/↓ moves the selected block or section.
- Palette: add the five new blocks with icons and one-line descriptions; clicking
  adds to the selected column (or a new `100` section at the end when nothing is
  selected — same behaviour as today's append).
- Preview modal: add a desktop / mobile width toggle (iframe width 600 / 375).
- A v1 published version opened read-only renders via `upcastV1` in memory.

### Composables

Follow existing naming: extend `useEmailTemplates.ts`; new `useEditorHistory.ts`.

## API surface

None new (uses 00, 01, 03).

## Panel surface

Covered above. i18n keys under `emailBuilder.*` for every new string, en/es/fr.

## Invariants

- Panel never builds final email HTML; preview stays the server renderer.
- i18n for all strings; `Array<T>`; `useApi()`.

## Acceptance criteria

- [ ] A `50_50` rate comparison can be built by hand in under a minute.
- [ ] Blocks drag between columns and between sections; arrows and Alt+↑/↓ still work.
- [ ] Changing `50_50` → `100` keeps every block (moved into the remaining column).
- [ ] Every canvas action is undoable and redoable; typing coalesces.
- [ ] `applyOps` followed by applying its inverse returns a deep-equal document for
      every op (property test over the fixtures).
- [ ] Mobile preview shows stacked columns.
- [ ] `bun run lint` and `bun run typecheck` pass.

## Tests required

The panel has no unit test runner in CI (CI is lint + typecheck). Add Vitest for
`app/utils/` only, run locally and in the PR description, or record that it waits
for a test-runner decision in `10`:

| Test | Asserts |
|---|---|
| `emailBlockOps.spec.ts › inverse_round_trip` | Every op × fixture |
| `emailBlockOps.spec.ts › shrink_layout_moves_blocks` | Layout rule |
| `emailBlocks.spec.ts › upcast_matches_php` | Same ids as the PHP fixture output |
| `useEditorHistory.spec.ts › coalesces_typing` | One entry per field burst |
