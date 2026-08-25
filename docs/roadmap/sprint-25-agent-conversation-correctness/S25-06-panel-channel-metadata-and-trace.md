# S25-06 — Panel: per-message channel metadata and trace pane fields

**Depends on:** S25-04, S25-05
**Touches:** `unit-hq-panel`
**Evidence:** both transcript screenshots

## Problem

Every bubble in the demo transcript renders `7 segments · ucs2` — including the
customer's own inbound messages (*"hi"*, *"we are in 28001"*). `hi` is not seven
segments and an inbound message has no outbound segment count at all.

The pane is painting the latest channel-guard detail at conversation scope
rather than reading each message's own guard row. Beyond being wrong, it
destroys the one thing the display is for: seeing *where* a thread got
expensive. The real progression was 1 → 2 → 2 → 4 → 5 → 7, which is a story;
"7 everywhere" is noise.

## What to build

### Per-message channel metadata

- Read segment count and encoding from the message's own channel-guard row,
  keyed by `message_id` (available after S25-05).
- **Inbound messages render no channel metadata.** Not zero, not a placeholder —
  nothing.
- Outbound renders `{n} segments · {encoding}`, with a warning affordance at or
  above `agents.channel.sms.warn_segments` and a distinct treatment for a denied
  draft that was re-written.
- When `detail.gsm7_transliterated` is set (S25-04), show an indicator that the
  sent body differs from the drafted body, with the original available on hover
  or expand. An operator reading a thread should not have to wonder why the
  agent wrote `10 m2`.

### Trace pane

Surface the new envelope fields (S25-05):

- Turn number, model, prompt version, per-row timestamp.
- Tool rows: `entities` returned, and on failure `error_code` plus the
  `recovery.tool` — so a reviewer sees not just that a call failed but what the
  agent was told to do about it.
- Guardrail rows: group under the message they judged rather than listing flat.
  A denied verdict is visually distinct from a warn and from a pass; today all
  three would read the same.
- Usage rows: show estimated cost with its currency, or an explicit "no price
  row for this model" state rather than a blank — a silent blank is how the null
  cost went unnoticed.

### Conventions

- All strings through i18n (`en` / `es` / `fr`); no hardcoded text. Keys under
  `agents.trace.*` and `agents.channel.*`.
- `Array<T>` typing, not `T[]`.
- HTTP through `useApi()`.
- CI is `bun run lint` + `bun run typecheck`.

## Acceptance criteria

- [ ] A transcript with mixed inbound and outbound shows channel metadata on
      outbound only.
- [ ] Segment counts differ per message and match the values the guard recorded.
- [ ] A warn-level message is visually distinguishable from a normal one; a
      denied-and-redrafted message is distinguishable from both.
- [ ] A transliterated message shows the indicator and exposes the original
      draft.
- [ ] A denied tool row shows its `error_code` and the tool that would license
      or unblock it.
- [ ] A usage row with no price catalogue entry shows an explicit state, not a
      blank.
- [ ] Lint and typecheck pass; no new hardcoded strings.
