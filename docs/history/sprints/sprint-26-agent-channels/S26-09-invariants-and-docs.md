# S26-09 — Invariants, decisions, docs

**Depends on:** all S26 tasks
**Blocks:** nothing
**Touches:** `unit-hq-api/docs`

S26-07b / 07c / 08 were not built when this landed. This pass documents
S26-00…S26-07 as shipped. Deferred doc edits are in **S26-09b** below;
each of those tasks applies its block in the same PR as the code.
Invariant 40 is **not** amended here.

## What to change

### `09-conventions-and-invariants.md`

- **Amend 40** (wording introduced by S26-07b) → *"Inbound receipt never
  creates contacts **untraceably**. Unknown senders park on
  `comms_triage` unless `communications.auto_lead_capture` is on, in
  which case a non-spam-shaped sender becomes a Contact
  (`origin = inbound_auto`) + lead Deal with a resolved triage row
  (`how = auto`) and a Tier-2 `contact.auto_created`. Spam-shaped inbound
  always parks."*
- **New 67 — Tool results expose the ids they license.** Every
  `EntityRef` on a `ToolResult` is rendered on the model-facing `Refs:`
  line. An id the model can pass but was never shown is a defect in the
  tool, not in the model.
- **New 68 — Agent channel bindings default to off.** An agent answers a
  real channel only under a live `agent_channel_bindings` row with
  `mode != off`. Absent row = off. This default is the opposite of
  `agent_write_policies` and must not be "harmonised".
- **New 69 — Agent sends go through the channel senders.** `AgentSend` is
  the only path from draft to send; it calls `EmailSender` / `SmsSender` /
  `WhatsAppSender` with `SendContext(source: ai_agent)`. No agent code
  inserts a `messages` row (restates 38 for agents).
- **New 70 — A human-owned thread is silent for agents.** Once a handoff
  is written or an operator replies manually, no agent turn runs on that
  thread until an explicit hand-back. Hand-back is a click, never a model
  or inbound event (extends invariant 60).

### `10-open-decisions.md`

- Decided: D-AI-16 refs line (S26-00); D-AI-17 `create_offer` resolves the
  rate server-side, `unit_class_rate_id` is never a model argument;
  D-AI-18 principal promotion is upward-only and stops at
  `channel_asserted`; D-AI-19 one agent per `(channel, site)`, cross-agent
  handoff deferred; D-AI-20 auto-lead capture is a company flag, default
  off. The promotion activity is `agent.conversation.principal_promoted`
  on the `ai` channel (not `ai.conversation.principal_promoted`).
- Undecided additions: `deals.purpose` column; missed-call SMS follow-up;
  prompt caching per provider; cross-agent handoff; OTP verification for
  webchat; per-site override of the company send window (`sendWindowStart` /
  `sendWindowEnd`).
- S26-06 already corrected in place (do not revert): API is
  `/api/ai/agents/bindings` not `/api/settings/ai-agents/bindings`; bindable
  channels are `email|sms|whatsapp|webchat` (`voice` and `internal` share
  one 422); seeder match key is `(channel, site_id)`.
- Remove "Not built: any transport / webchat channel / per-agent per-channel
  autonomy" bullets from `14`, moving the reservation-promotion bar (D-AI-11)
  text unchanged.

### `14-ai-agents.md`

- Turn loop: add `ContextWindow` before the driver call; retry-before-
  escalate rule; principal promotion.
- New section **Live channels**: listener steps, bindings table, audience
  semantics, `AgentSend`, draft approval, webchat routes.
- Tool catalogue: `sales.create_offer` argument change; `pricing.discounts`
  offerable-only; need fields on `crm.create_deal` / `sales.propose_offer`.
- Known gaps: AR-03 redaction now also covers `chat_sessions.visitor_meta`
  and `agent_pending_actions.payload.body` — still blocking before a
  provider channel binding is set to `auto` in production. Say so.

### `06-communications.md`

- `Channel` gains `webchat` (first-party, no provider — S26-07c);
  `SendContext` source gains `ai_agent` (S26-07); `contacts.origin` and
  `auto_lead_capture` / spam-shape rules (S26-07b); badge fields
  `agent_drafts` / `agent_handoffs` (S26-07).

### `04-crm-pipeline.md`

- `contacts.origin` enum; `deals.source = inbound_{channel}`.

### `01-stack.md` / `AGENTS.md`

- Page map: Settings → AI agents → Channels; Inbox agent draft card;
  `/chat/:token`. `AGENTS.md` non-negotiables gain invariants 68 and 70 in
  one line each.

## Acceptance criteria

- [x] Every new invariant number is referenced from the task that
      introduced it and from `14`.
- [x] `AGENTS.md` table row for "AI agents / live channels" points at `14`.
- [x] No doc still says the agent has no transport.

S26-07b, S26-07c, and S26-08 were not built when this task landed. Their
doc edits are listed in S26-09b and each task applies them in its own PR.

## S26-09b — doc edits that unlock later

Each of S26-07b, S26-07c, and S26-08 applies **its** block in the same PR
as the code. D-AI-21 is already resolved in S26-09; it needs no follow-up.

### S26-07b

- **`09` invariant 40** — amend to: *"Inbound receipt never creates
  contacts **untraceably**. Unknown senders park on `comms_triage` unless
  `communications.auto_lead_capture` is on, in which case a non-spam-shaped
  sender becomes a Contact (`source = inbound_auto`) + lead Deal with a
  resolved triage row (`how = auto`) and a Tier-2 `contact.auto_created`.
  Spam-shaped inbound always parks."* Drop the S26-07b pointer line that
  S26-09 left on 40.
- **`04-crm-pipeline.md`** — `ContactSource::InboundAuto`;
  `deals.source = inbound_{channel}` on auto-captured leads. No
  `contacts.origin`.
- **`06-communications.md`** — `auto_lead_capture` / spam-shape rules;
  unmatched + `audience = all` + flag on is capture, not `skip audience`.
- **`10-open-decisions.md`** — flip D-AI-20 from "not built yet" to shipped.
- **`14-ai-agents.md`** — remove "Auto-lead capture — S26-07b" from not-built;
  document the capture branch in Live channels.

### S26-07c

- **`06-communications.md`** — add `webchat` to comms `Channel` (first-party,
  no provider). Drop the "S26-07c, not yet a case" note.
- **`14-ai-agents.md`** — webchat public routes in Live channels;
  `chat_sessions.visitor_meta` under AR-03 (no longer "arrives with"); drop
  the webchat-as-comms-`Channel` not-built bullet.
- **`10-open-decisions.md`** — drop the webchat out-of-scope bullet.

### S26-08

- **`01-stack.md` page map** — Settings → AI agents → Channels
  (`/settings/ai-agents/channels`); Inbox agent draft card / hand-back;
  public `/chat/:token`.
- **`isHumanOwned()` → `ThreadAgentState`.** S26-08 needs the same
  human-ownership predicate to decide whether to render the hand-back
  button, so the rule moves out of the private
  `RespondWithAgent::isHumanOwned()` into a shared
  `App\Support\Ai\ThreadAgentState`. Re-anchor invariant 70 in `09` and
  the Live channels section in `14` from `RespondWithAgent::isHumanOwned()`
  to `ThreadAgentState`. The rule is unchanged; only its home moves.

