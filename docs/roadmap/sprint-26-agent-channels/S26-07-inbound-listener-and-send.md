# S26-07 — Inbound listener, routing, send, draft approval

**Depends on:** S26-06, S26-01, S26-02
**Blocks:** S26-07b, S26-07c, S26-08
**Touches:** `unit-hq-api`

## Problem

`14-ai-agents.md` "What is deliberately not built": *any transport — no
`SendContext`, no sender call, no `messages` row.* `origin = inbox` is
scaffolded on `agent_conversations`, `emitted_message_id` /
`message_thread_id` exist and are always null. Nothing listens to
`InboundMessageReceived` (fired by `InboundReceiptApplier`). This task makes
the runtime answer real messages, under the S26-06 binding, without
violating invariant 38.

Auto-lead capture and webchat are split out: S26-07b and S26-07c.

## What to build

### Listener `RespondWithAgent` (queued, `ai` queue)

Subscribes to `InboundMessageReceived`. Steps, each short-circuit logged as
a Tier-1 `SystemEvent` `ai.inbound.skipped` with `reason`:

1. Skip `auto_generated` messages, messages whose thread is a `call`
   channel, messages whose `source = ai_agent`, and messages already
   answered: at most one `agent_conversation_messages` row with
   `subject_message_id` = this inbound (partial unique). The listener
   pre-checks, but the unique index is the real gate. A losing
   `UniqueConstraintViolationException` is caught, recorded as
   `ai.inbound.skipped(duplicate)`, and the job completes — never a
   failed job, never a retry.
2. `AgentChannelBindings::resolve(channel, siteId)` where `siteId` is from
   `InboundSiteContext` (destination identity, never the customer's From).
   `null` or `mode = off` → skip `binding_off`.
3. Audience gate per S26-06. Unmatched sender (including `audience = all`)
   → skip `audience`. S26-07b replaces the `audience = all` branch with
   auto-lead capture.
4. Business hours: `outside_hours = inbox` and now is outside the company
   send window → skip `outside_hours`. The window is
   `GeneralSettings::$sendWindowStart` → new nullable `$sendWindowEnd`
   (default `null` = no end, i.e. never outside once started). Add
   `sendWindowEnd` to `GeneralSettings` and the `GET/PATCH /api/settings/general`
   payload and validation in this task (`send_window_start` is read-only
   via the API today — make both writable). Evaluate the window via
   `SiteClock` in the binding's **site** timezone for site-scoped
   bindings. There is no company timezone: a company-scoped binding
   evaluates in the timezone of the site resolved by `InboundSiteContext`
   when there is one, else `config('app.timezone')`. `SiteClock` today has
   only date-level helpers — add a time-of-day comparison. **Site access
   hours are not office hours** (`facility.site_info` must not be used). A
   per-site override is undecided (`10-open-decisions.md`).
5. **Human ownership**: thread has an existing `agent_conversation` with
   `state ∈ {awaiting_human, handed_off}` → skip `human_owned`. A thread
   the operator has replied to manually since
   `greatest(last_turn_at, agent_handback_at)` (outbound `messages` with
   `source = manual`) is also human-owned until the operator hands it
   back (`POST …/agent/resume`).
6. **Eligibility**: one agent per `(channel, site)` (S26-06), so there is
   no routing between agents here. The bound agent's
   `AgentDefinition::eligible(contact, siteId)` (new interface method) may
   decline: `sales` declines a contact with an active contract at the
   binding site, `support` declines a contact with none. Decline → skip
   `agent_ineligible`, thread stays in the Inbox unread. Cross-agent
   handoff is a later sprint.
7. Debounce: if another inbound on the same thread arrived within
   `config('agents.inbound_debounce_seconds')` (default 20), release the
   job back with delay and let the last one run (SMS multipart, rapid
   WhatsApp). Implement with the cache lock keyed by thread id.
8. Find-or-create the `agent_conversation` for `message_thread_id`
   (`origin = inbox`, `audience = customer`, `channel` from the thread,
   `site_id` from context, principal `channelAsserted(contact)` — never
   `verified`). The listener is the boundary that constructs the principal
   (D-AI-1, invariant 56).
9. `AgentRuntime::turn($conversation, $principal, $input, subjectMessageId: $message->id)`.
   WhatsApp window is **enforced** (no longer advisory): window closed →
   the turn's outbound is refused, handoff `channel_constraint`, thread
   flagged for an operator template send. `last_inbound_at` is read from
   `$conversation->messageThread`.
10. Outcome:
    - handoff → `state = awaiting_human`, thread `assign` left null,
      `unread` bumped, Inbox badge `agent_handoffs` count.
    - draft + `mode = draft` → `agent_pending_actions` row with
      `tool_key = channel.send`, payload `{site_id, message_thread_id, body, subject?, agent_conversation_message_id}`.
      Preview holds derived values (`segments`, `encoding`,
      `window_closes_at`, `from_identity`). `expires_at` is
      `min(now() + pending_action_ttl, preview.window_closes_at)` when
      the preview carries a window, else the default TTL.
    - draft + `mode = auto` → send via `AgentSend`.

### Send path

`AgentSend` (`App\Support\Ai\AgentSend`) — the **only** code that turns a
draft into a send:

- Builds `SendContext(source: 'ai_agent', class: 'transactional')`. Add
  `ai_agent` to the `MessageSource` vocabulary.
- Calls `EmailSender` / `SmsSender` / `WhatsAppSender` on the existing
  thread (`Threading::forExplicitThread`). Suppression gate, GSM-7 segment
  count, WhatsApp session/template rules all apply unchanged.
- Sets `agent_conversation_messages.emitted_message_id` and
  `agent_conversations.message_thread_id`. The `messages` row is the one the
  sender wrote; the agent never inserts one (invariant 38).
- `Interaction` row is written by the sender path as today.
- On sender refusal (suppressed, window closed, `ChannelNotConfigured`)
  → no message, handoff `channel_constraint`, Tier-1 `ai.send.refused`.
- Approval of a `channel.send` pending action re-runs `AgentSend` against
  current state (window may have closed). Payload holds ids and body only;
  never the rendered segments or the window deadline (invariant 62).

### `channel.send` tool

A `ProposableTool` in the registry that **no agent claims**
(`RuntimeOnlyTools`). Dispatched by `AgentRuntime` as a terminal step of
an inbox turn, not via `ToolDispatcher::dispatch` (whose allowlist gate
would deny it). Binding mode, not `agent_write_policies`, picks the
branch: `draft` → `propose()` then `PendingActionRecorder`; `auto` →
`handle()`.

### Operator endpoints

- `POST /api/inbox/threads/{id}/agent/resume` — sets `state = active`,
  `agent_handback_at = now()`, does **not** trigger a turn. Permission
  `InboxSend`.
- Reject of a `channel.send` pending action accepts `resolution`
  (`discarded|edited`) in `detail.resolution`. Edit & send is a reject,
  then a normal inbox reply (`source = manual`) with optional
  `agent_pending_action_id`. After a successful send the reply path
  back-fills `detail = {edited_body_hash, sent_message_id}` **only** when
  the action belongs to this thread, `tool_key = channel.send`, and
  status is rejected-as-edited; otherwise 422 and `detail` unwritten.
  The approve endpoint gets **no** `body` parameter.
- `GET /api/inbox/badge` gains `agent_drafts` and `agent_handoffs`.

## Acceptance criteria

- [ ] Feature test: inbound SMS from a known contact, sales binding `draft`
      → one `agent_pending_actions(channel.send)`, zero `messages`
      outbound rows, thread unread; approve → exactly one outbound
      `messages` row with `source = ai_agent`, `emitted_message_id` set.
- [ ] Same with binding `auto` → outbound written in the turn.
- [ ] Binding absent → `ai.inbound.skipped(binding_off)`, no conversation
      row.
- [ ] `existing_tenants` binding + prospect → skipped `audience`.
- [ ] Sales agent + active tenant → skipped `agent_ineligible`.
- [ ] Thread with a manual operator reply after the last agent turn →
      skipped `human_owned` until hand-back (`POST …/agent/resume`).
- [ ] WhatsApp inbound older than 24h (fixture) → turn produces handoff
      `channel_constraint`, no send attempted.
- [ ] Duplicate delivery of the same inbound → second run records
      `ai.inbound.skipped(duplicate)`, job completes, nothing in
      `failed_jobs`.
- [ ] Outside the send window with `outside_hours = inbox` → skipped
      `outside_hours`; with `answer` → answers.
- [ ] Two inbounds on one thread within the debounce window → one turn,
      driven by the last.
- [ ] Reject with `resolution = edited` then reply with
      `agent_pending_action_id` → `detail` links draft to sent message,
      thread human-owned. Cross-thread or not-rejected-as-edited
      back-fill → 422, `detail` unwritten.
- [ ] WhatsApp draft whose window closes before the default TTL gets
      `expires_at = window_closes_at`; an SMS draft with no window
      keeps the default TTL.
- [ ] `AgentToolCoverageTest` passes with `channel.send` registered and
      unclaimed; a definition claiming it fails the inverse assertion.
- [ ] `PermissionCoverageTest` / `RouteAuthCoverageTest` green (no new
      public routes in this task).

## Out of scope

- Auto-lead capture — S26-07b.
- Webchat as a comms `Channel` — S26-07c.
- Sending offers (D-AI-10). The agent replies; the offer link is sent by an
  operator or a later playbook action.
- Cross-agent handoff (sales ↔ support) inside one thread.
- Missed-call SMS follow-up.
- Panel surfaces — S26-08.
