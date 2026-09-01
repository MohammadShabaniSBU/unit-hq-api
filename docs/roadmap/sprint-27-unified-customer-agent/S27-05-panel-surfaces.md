# S27-05 — Panel: one agent, visible verification

**Depends on:** S27-02, S27-03, S27-04
**Blocks:** nothing
**Touches:** `unit-hq-panel`

## Problem

The panel presents two customer agents and a binding page whose whole point
was choosing between them. After S27-02 there is one, and the operator's real
question changes from *which agent answers this channel* to *how far may the
agent go, and who is on the other end*. Three surfaces read the wrong thing:

- The bindings page shows an agent picker that now has one option and two
  archived ones.
- The write-policy page is nested per agent and will show a merged policy set
  with no indication that a value was narrowed by the S27-03 merge.
- The conversation view shows `verification_level` as a raw enum with no
  sense that it can now change mid-conversation.

## What to build

### Bindings page

- Agent column becomes a static label, not a select, while exactly one live
  agent exists. Do not delete the picker component — S27-03 keeps the schema
  multi-agent and D-AI-19 is a per-`(channel, site)` uniqueness rule, not a
  one-agent rule. Render the select only when
  `agents.filter(a => !a.archived_at).length > 1`.
- Archived agents never appear as a binding target.
- Empty state copy changes: a channel with no binding is off, and the sentence
  should say what that means for a prospect emailing that address.

### Write policies

- Surface the merge. Any policy row whose `updated_at` matches the S27-03
  migration and whose activity log carries `ai.write_policy.merged` shows an
  inline note: *narrowed during agent merge — previous values {from}*. One
  sprint of visibility; drop it when the note stops being true.
- Keep the page nested under the agent. It is per-instance data and stays so.

### Conversation detail

- `verification_level` renders as a labelled state with an explanation, not
  an enum value: anonymous / identified by channel / verified.
- When the level changed mid-conversation, show it on the timeline at the
  turn where `agent.conversation.principal_promoted` was logged, with the
  method (`otp` / `contact_created`). An operator reading a transcript needs
  to see the moment the agent was allowed to discuss the account — that is
  the audit question a dispute will ask.
- Tool invocations denied with `ToolDeniedReason::Verification` render as a
  distinct chip rather than a generic denial. They are the expected path now,
  not an error.

### Demo persona picker

The verification select in the demo surface stays, and gains a line of copy
saying it is a demo affordance with no production equivalent. It is the only
remaining writer of `verified` outside a real challenge (invariant 59), and
someone will eventually assume it reflects how production works.

### i18n

New keys under `ai.agents.*`, `ai.verification.*`, `ai.bindings.*` in `en`,
`es`, `fr`. No string ships hardcoded.

## Acceptance criteria

- [ ] Bindings page renders a label with one live agent, a select with two.
- [ ] Archived agents absent from every picker, present in historical
      conversation rows.
- [ ] Merged-policy note renders where the activity row exists.
- [ ] Conversation timeline shows the promotion event with its method.
- [ ] Verification-denied tool calls render distinctly.
- [ ] `bun run lint` and `bun run typecheck` clean; `Array<T>` throughout;
      all strings through i18n in three locales.

## Out of scope

- Any customer-facing surface. There is no tenant portal; the verification
  exchange happens inside the conversation, not on a page.
- Agent performance reporting. Tables are shaped for it; the harvest
  principle applies and no report is built here.
