# S34-04 — Panel: assistant tab & op applier

## Context

The builder page (`app/pages/marketing/templates/email/[id].vue`) has three columns:
palette, canvas, settings. S33 added `applyOps` with inverses and
`useEditorHistory`. The copilot already consumes broadcast turns through
`app/utils/copilotTurnBus.ts`. This task adds an **Assistant** tab to the right
column and applies finished turns to the canvas.

## Scope

**In:** Assistant tab, chat, streaming text, change cards, apply/rebase/conflict,
undo integration, subject changes, status reporting, error states.
**Out:** empty-canvas prompt and starters (05), images, translate (S35).

## Schema changes

None.

## Implementation notes

### Layout

- Right column becomes tabs: **Settings | Assistant**. The last tab is remembered in
  Pinia for the session. Selecting a node while on Assistant does not switch tabs.
- Header gets an **Assistant** button that opens the tab (hidden without
  `template_assist.use`, for non-email channels, and in HTML mode with a hint).
- The canvas keeps its 600px width; the tab panel is the existing settings width.

### Chat

- `useTemplateAssist(familyId, locale)` — loads the session (`GET …/assist/session`),
  sends turns, subscribes to `private-template-assist.{session}` via the existing
  Echo setup, exposes `messages`, `turns`, `streamingText`, `status`.
- Composer: Enter sends, Shift+Enter newline, character counter near the limit,
  **Stop** while running (unsubscribes and marks the turn `discarded` when it
  completes). **New chat** starts a new session.
- Messages render plain text (no markdown tables); the assistant's last sentence is
  its change summary.
- A "Working…" line while tool events arrive (names are not shown).

### Applying a finished turn

On `template-assist.turn.finished` with `completed`:

1. `GET template-assist/turns/{t}`.
2. If `sha256(canonical(currentDoc)) === base_hash`: apply `ops` via `applyOps`,
   push **one** history entry labelled with the assistant icon, report `applied`.
3. Else **rebase**: apply ops in order; an op whose target id no longer exists (or
   whose destination column was removed) is skipped. If none were skipped, push and
   report `applied`. If any were skipped, don't apply; report `conflict` and open a
   side-by-side view: current canvas vs `working_document` rendered through the
   preview endpoint, with **Use assistant version** (replaces the doc as one history
   entry, reports `applied`) and **Keep mine** (reports `discarded`).
4. `subject_change` → updates the subject field as part of the same history entry.
5. Touched nodes get a highlight until the next manual edit.

Canonical JSON helper lives in `app/utils/canonicalJson.ts` and is tested against the
PHP hash for the shared fixtures.

Undo of an assistant entry reports `undone`; redo reports `applied`.

### Change card

Under each assistant message: "{added} added · {edited} edited · {removed} removed",
subject change if any, **Undo** (when it's the latest applied entry) and
**Show changes** (scrolls to the first highlighted node).

### Errors

Map `error_key` to i18n: provider failure ("The assistant couldn't respond. Try
again."), `assist.daily_limit_reached`, `assist.draft_changed` (reload prompt),
`assist.tool_budget_exhausted` ("Partly done — the changes so far were applied").

## API surface

Consumes 03.

## Panel surface

Composables `useTemplateAssist`, types in `app/types/template-assist.ts`
(`Array<T>`), components `email-builder/AssistantPanel.vue`,
`AssistantMessage.vue`, `AssistantChangeCard.vue`, `AssistantConflictView.vue`.
i18n `emailBuilder.assistant.*` in en/es/fr.

## Invariants

- **T6** — the tab never calls save or publish; the user's **Save** button is the only
  draft write.
- Panel never renders final HTML (conflict view uses the preview endpoint).

## Acceptance criteria

- [ ] All six recorded fixtures from 02 apply correctly (unit tests over `applyOps` +
      rebase).
- [ ] Editing a paragraph during a turn that adds a section: both survive.
- [ ] Deleting the section the assistant is editing during a turn opens the conflict
      view; **Keep mine** leaves the canvas untouched.
- [ ] One Ctrl+Z removes a whole assistant turn, including its subject change.
- [ ] After an applied turn the page shows unsaved changes; reload without saving
      discards them.
- [ ] `bun run lint` and `bun run typecheck` pass.

## Tests required

| Test | Asserts |
|---|---|
| `assistApply.spec.ts › fast_path_hash_match` | Direct apply |
| `assistApply.spec.ts › rebase_skips_missing_targets` | Conflict detection |
| `assistApply.spec.ts › one_history_entry_per_turn` | Undo granularity |
| `canonicalJson.spec.ts › matches_php_hash` | Shared fixtures |
| Manual script in PR | Six fixtures + three live prompts |
