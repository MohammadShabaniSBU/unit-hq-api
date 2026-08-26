# S26-07 — Inbound listener, routing, send, draft approval

**Depends on:** S26-06, S26-01, S26-02
**Blocks:** S26-08
**Touches:** `unit-hq-api`

## Problem

`14-ai-agents.md` "What is deliberately not built": *any transport — no
`SendContext`, no sender call, no `messages` row.* `origin = inbox` is
scaffolded on `agent_conversations`, `emitted_message_id` /
`message_thread_id` exist and are always null. Nothing listens to
`InboundMessageReceived` (fired by `InboundReceiptApplier`). This task makes
the runtime answer real messages, under the S26-06 binding, without
violating invariant 38.

## What to build

### Listener `RespondWithAgent` (queued, `ai` queue)

Subscribes to `InboundMessageReceived`. Steps, each short-circuit logged as
a Tier-1 `SystemEvent` `ai.inbound.skipped` with `reason`:

1. Skip `auto_generated` messages, messages whose thread is a `call`
   channel, and messages already answered: at most one
   `agent_conversation_messages` row with `subject_message_id` = this
   inbound (partial unique). A second delivery of the same inbound is a
   no-op.
2. `AgentChannelBindings::resolve(channel, siteId)` where `siteId` is from
   `InboundSiteContext` (destination identity, never the customer's From).
   `null` or `mode = off` → skip `binding_off`.
3. Audience gate per S26-06. Unmatched sender with `audience = all` →
   **auto-lead capture** (below); otherwise skip `audience`.
4. Business hours: `outside_hours = inbox` and now is outside the company
   send window → skip `outside_hours`. The window is
   `GeneralSettings::$sendWindowStart` → new nullable `$sendWindowEnd`
   (default `null` = no end, i.e. never outside once started). Add
   `sendWindowEnd` to `GeneralSettings` and the `GET/PATCH /api/settings/general`
   payload and validation in this task. Evaluate the window via `SiteClock`
   in the binding's **site** timezone for site-scoped bindings, and in the
   company timezone for company-scoped ones. `SiteClock` today has only
   date-level helpers — add a time-of-day comparison. **Site access hours
   are not office hours** (`facility.site_info` must not be used). A
   per-site override is undecided (`10-open-decisions.md`).
5. **Human ownership**: thread has an existing `agent_conversation` with
   `state ∈ {awaiting_human, handed_off}` → skip `human_owned`. A thread
   the operator has replied to manually since the last agent turn is also
   human-owned until the operator hands it back (S26-08 button).
6. **Eligibility**: one agent per `(channel, site)` (S26-06), so there is
   no routing between agents here. The bound agent's
   `AgentDefinition::eligible(contact)` (new interface method) may decline:
   `sales` declines a contact with an active contract at the binding site,
   `support` declines a contact with none. Decline → skip
   `agent_ineligible`, thread stays in the Inbox unread. Cross-agent
   handoff is a later sprint.
7. Debounce: if another inbound on the same thread arrived within
   `config('agents.inbound_debounce_seconds')` (default 20), release the
   job back with delay and let the last one run (SMS multipart, rapid
   WhatsApp). Implement with the cache lock keyed by thread id.
8. Find-or-create the `agent_conversation` for `message_thread_id`
   (`origin = inbox`, `audience = customer`, `channel` from the thread,
   `site_id` from context, principal `channelAsserted(contact)` — never
   `verified`).
9. `AgentRuntime::turn()` with `last_inbound_at` from the thread so
   `ChannelProfile` WhatsApp window is **enforced** (no longer advisory):
   window closed → the turn's outbound is refused, handoff
   `channel_constraint`, thread flagged for an operator template send.
10. Outcome:
    - handoff → `state = awaiting_human`, thread `assign` left null,
      `unread` bumped, Inbox badge `agent_handoffs` count (S26-08).
    - draft + `mode = draft` → `agent_pending_actions` row with
      `tool_key = channel.send`, payload `{thread_id, body, subject?}`,
      preview = rendered body. Expires with the WhatsApp window when
      applicable, else 24h.
    - draft + `mode = auto` → send.

### Auto-lead capture (`audience = all`)

Only when `config('communications.auto_lead_capture')` is true (new,
default `false`). Replaces the triage park for **non-spam-shaped** unknown
senders: create `Contact` with the channel as primary
(`contacts.origin = inbound_auto`, migration adds `origin` enum column
`manual|inbound_auto|walk_in|import|ai_agent`), create a lead `Deal`
(`source = inbound_{channel}`), write the `comms_triage` row **resolved**
with `how = auto`, Tier-2 `contact.auto_created`. Spam-shaped stays in
triage: auto-generated headers, bounces, STOP keyword, `noreply@` /
`mailer-daemon` / role addresses, active suppression, and
`communications.spam_patterns`. This amends invariant 40 (S26-09 wording:
*never untraceably*).

### Send path

`AgentSend` (`App\Support\Ai\AgentSend`) — the **only** code that turns a
draft into a send:

- Builds `SendContext(source: 'ai_agent', class: 'transactional')`. Add
  `ai_agent` to the `SendContext` source vocabulary.
- Calls `EmailSender` / `SmsSender` / `WhatsAppSender` on the existing
  thread (`Threading::forOutbound`). Suppression gate, GSM-7 segment count,
  WhatsApp session/template rules all apply unchanged.
- Sets `agent_conversation_messages.emitted_message_id` and
  `agent_conversations.message_thread_id`. The `messages` row is the one the
  sender wrote; the agent never inserts one (invariant 38).
- `Interaction` row is written by the sender path as today.
- On sender refusal (suppressed, window closed, `ChannelNotConfigured`)
  → no message, handoff `channel_constraint`, Tier-1 `ai.send.refused`.
- Approval of a `channel.send` pending action re-runs `AgentSend` against
  current state (window may have closed). Payload holds ids and body only;
  never the rendered segments or the window deadline (invariant 62).

### Webchat

Add `webchat` to the comms `Channel` enum with a first-party adapter (no
provider account): `chat_sessions` table (`token` crypto-random, `site_id`
nullable, `contact_id` nullable, `message_thread_id`, `visitor_meta`,
`last_seen_at`, `closed_at`), public routes `POST /api/chat/sessions`,
`POST /api/chat/sessions/{token}/messages`, `GET …/messages?after=`
(allowlisted in `routes/api.php` with the token named as authenticator,
invariant 42; rate-limited per token and IP). Inbound writes a `messages`
row (`direction = inbound`) and fires `InboundMessageReceived` like any
channel; outbound is a `messages` row the widget polls. Session open with
no message triggers the sales greeting turn (S26-04 disclosure) — the
session-open is the inbound event; the agent is still never proactive on
provider channels. The demo `/demo/chat` page is unchanged; a minimal
`/chat/{token}` public page is S26-08.

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
      skipped `human_owned` until hand-back.
- [ ] WhatsApp inbound older than 24h (fixture) → turn produces handoff
      `channel_constraint`, no send attempted.
- [ ] Duplicate delivery of the same inbound → second run is a no-op.
- [ ] `auto_lead_capture` on + unknown SMS sender → contact with
      `origin = inbound_auto`, lead deal, resolved triage row, activity;
      `noreply@` sender still parks in triage.
- [ ] Webchat: open session → greeting with disclosure; message → reply
      polled; no provider account involved.
- [ ] `RouteAuthCoverageTest` allowlist entries for the three chat routes.

## Out of scope

- Sending offers (D-AI-10). The agent replies; the offer link is sent by an
  operator or a later playbook action.
- Cross-agent handoff (sales ↔ support) inside one thread.
- Missed-call SMS follow-up.
- Panel surfaces — S26-08.
