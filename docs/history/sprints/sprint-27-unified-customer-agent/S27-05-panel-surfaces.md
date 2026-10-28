# S27-05 — Panel: one agent, visible verification

**Depends on:** S27-02, S27-03, S27-04
**Blocks:** nothing
**Touches:** `unit-hq-panel` (sprint docs in `unit-hq-api/docs`)

## Problem

The panel presents two customer agents and a binding page whose whole point
was choosing between them. After S27-02 there is one, and the operator's real
question changes from *which agent answers this channel* to *how far may the
agent go, and who is on the other end*.

Three premises in the original write-up do not hold and are corrected here:

- **The bindings page was never built.** `14-ai-agents.md` assigns it to
  S26-08, which is unimplemented. The panel has the
  `AiAgentBindingManage` permission and the API (`GET/POST/PUT/DELETE
  /api/ai/agents/bindings`); it has no page, composable, or type. This task
  ships a **read-only** table. The create/edit slideover stays with S26-08.
  The original rule — render the agent select only when
  `agents.filter(a => !a.archived_at).length > 1` — is a requirement of that
  slideover, recorded on S26-08, not something a read-only table can satisfy.
- **The merged-policy note is cut.** S27-03's strictest-wins merge narrows
  nothing against seeded data: the support instance holds zero write
  policies, and the sales policies carry forward unchanged onto concierge.
  `ai.write_policy.merged` never fires, so the note can never render. See
  S27-06.
- **The promotion row is emitted by S27-04**, as a `TraceAssembler` kind
  `promotion`, not derived in the panel from `identity.verify_code` returning
  ok. Deriving it would reimplement `PrincipalPromotion`'s guards and would
  display promotions that did not happen.

The conversation view still shows `verification_level` as a raw enum (or not
at all) with no sense that it can now change mid-conversation. That is the
remaining operator question this task answers.

## What to build

### Bindings page (read-only)

Settings → AI agents gains a Channels tab gated on
`ai_agent_binding.manage`. It lists live bindings from
`GET /api/ai/agents/bindings` (`live()` scope, nested `agent`, `site`,
`updated_by`). Agent is a static label. Archived agents and archived
bindings cannot appear: the endpoint never returns them.

Below the table, every bindable channel with no live row is listed with copy
that the channel is off and what that means for a prospect who emails that
address. Full empty state uses the same sentence.

The picker — select when two live agents exist, label when one — is an
S26-08 requirement, not this task's. Do not delete the idea of a picker:
S27-03 keeps the schema multi-agent and D-AI-19 is a per-`(channel, site)`
uniqueness rule, not a one-agent rule.

### Write policies

Keep the page nested under the agent. It is per-instance data and stays so.
No merge note.

### Conversation detail

- `verification_level` renders as a labelled state with an explanation, not
  an enum value: anonymous / identified by channel / verified. The value is
  the live server field, refreshed by `hydrate()` after every turn, so a
  mid-conversation promotion moves it.
- When the level changed mid-conversation, show it on the timeline as the
  `kind: 'promotion'` trace row S27-04 emits (`from`, `to`, `method`:
  `otp` / `contact_created`). An operator reading a transcript needs to see
  the moment the agent was allowed to discuss the account — that is the
  audit question a dispute will ask. Placement is by turn: the row belongs
  in the group whose `identity.verify_code` invocation preceded it.
- The panel's `AgentStreamEvent` union deliberately omits a promotion
  member. The assumption is that S27-04 emits no SSE event for the
  promotion; the row arrives only through `hydrate()`'s refetch after the
  turn. If that assumption is false, `applyEvent`'s trailing `assertNever`
  throws at runtime on `/demo/chat`. The reverse half of this coupling lives
  on S27-04.
- Tool invocations denied with `ToolDeniedReason::Verification` render as a
  distinct chip rather than a generic denial. They are the expected path now,
  not an error.

### Demo persona picker

The verification select in the demo surface stays, and gains a line of copy
saying it is a demo affordance with no production equivalent. It is the only
remaining writer of `verified` outside a real challenge (invariant 59), and
someone will eventually assume it reflects how production works.

### i18n

New keys under `ai.verification.*`, `ai.bindings.*` in `en`, `es`, `fr`. No
string ships hardcoded.

## Acceptance criteria

- [ ] Channels tab lists live bindings with the agent as a static label.
- [ ] Archived agents absent from the bindings table (the endpoint's
      `live()` scope); historical conversation rows still show the archived
      instance name via `agent_key`.
- [ ] Unbound bindable channels render the channel-off sentence.
- [ ] Conversation timeline shows the promotion event with its method, in
      the group for the turn whose `identity.verify_code` preceded it.
- [ ] Verification-denied tool calls render distinctly.
- [ ] Demo verification select carries the demo-affordance copy.
- [ ] `bun run lint` and `bun run typecheck` clean; `Array<T>` throughout;
      all strings through i18n in three locales.

## Out of scope

- Any customer-facing surface. There is no tenant portal; the verification
  exchange happens inside the conversation, not on a page.
- Agent performance reporting. Tables are shaped for it; the harvest
  principle applies and no report is built here.
- Binding create/edit/archive slideover — S26-08.
- Merged-policy note — cut; see S27-06.
