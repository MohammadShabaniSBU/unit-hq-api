# S34-05 — Entry points & starters

## Context

Most operators won't open a side tab on their own. The empty canvas and the selected
block are where the assistant earns its place.

## Scope

**In:** empty-canvas prompt, purpose-aware starters, selection chip, keyboard shortcut.
**Out:** template creation flow changes.

## Schema changes

None.

## Implementation notes

- **Empty canvas** (draft with no sections): replace the "Add blocks from the left
  panel" empty state with a prompt box "Describe the email you want" plus starters,
  and keep **Insert block** as a secondary action. Submitting opens the Assistant tab
  and sends the turn.
- **Starters** are i18n prompt strings keyed by `TemplatePurpose`:

  | Purpose | Starters |
  |---|---|
  | `system` | Move-in welcome · Rate increase notice · Move-out confirmation · Access code reminder |
  | `debt` | Friendly payment reminder · Overdue notice · Final notice before lien |
  | `lead` | Follow-up after enquiry · Offer still available |
  | `offer` | Your offer is ready |
  | `marketing` / `newsletter` | Seasonal promotion · Monthly newsletter |
  | `announcement` | Holiday hours · Facility maintenance |
  | `general` | the `system` set |

  Starters are prompts, never pre-built documents.
- **Selection chip.** When a node is selected and the tab is open, the composer shows
  `Editing: Section 2 › Column 1 › Paragraph` (removable). The selection is sent with
  the turn; the prompt (02) scopes edits to it.
- **Shortcut.** Cmd/Ctrl+K focuses the composer (opens the tab if closed).

## API surface

None.

## Panel surface

`email-builder/AssistantEmptyState.vue`; i18n `emailBuilder.assistant.starters.*` in
en/es/fr.

## Invariants

None beyond 04.

## Acceptance criteria

- [ ] A new draft shows the prompt box; a debt template shows debt starters.
- [ ] Choosing a starter sends it as the first turn.
- [ ] With a paragraph selected, "make this shorter" changes only that block
      (manual check over three runs, recorded in the PR).
- [ ] Removing the chip sends the next turn without selection.

## Tests required

| Test | Asserts |
|---|---|
| `starters.spec.ts › purpose_mapping` | Every purpose has starters |
| Manual check in PR | Selection-scoped edits |
