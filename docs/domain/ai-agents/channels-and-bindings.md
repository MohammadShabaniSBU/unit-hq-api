# Channels and bindings

Part of the customer-facing agent docs. Map: [README](README.md).


## Live channels

Inbound email / SMS / WhatsApp reach `AgentRuntime::turn` through the queued
listener `RespondWithAgent` (`ai` queue) on `InboundMessageReceived`. The
listener is the boundary that constructs the principal (D-AI-1, invariant 56)
as `channelAsserted(contact)` — never `verified`. Each skipped inbound is a
Tier-1 `SystemEvent` `ai.inbound.skipped` with `reason`. Order:

| `reason` | When |
|---|---|
| `ignored` | `auto_generated`, `source = ai_agent` (`LoopGuard::shouldIgnoreInbound`), call channel |
| `duplicate` | an `agent_conversation_messages` row already has this `subject_message_id` (partial unique is the real gate; a losing `UniqueConstraintViolationException` is caught, never a failed job) |
| `binding_off` | `AgentChannelBindings::resolve(channel, siteId)` is null or `mode = off`. `siteId` is from `InboundSiteContext` (destination identity, never the customer's From) |
| `audience` | unmatched sender, or `existing_tenants` and the contact has no in-force contract at the binding site. Unmatched + `audience = all` is still `audience` until S26-07b |
| `outside_hours` | `outside_hours = inbox` and now is outside `GeneralSettings::$sendWindowStart` → `$sendWindowEnd` via `SiteClock::withinWindow()`. Site-scoped bindings use that site's timezone; company-scoped use the inbound site's timezone, else `config('app.timezone')`. Site access hours are not office hours. Per-site window override is unset (`10`) |
| `human_owned` | conversation `state ∈ {awaiting_human, handed_off}`, or an outbound `source = manual` message newer than `greatest(last_turn_at, agent_handback_at)` (invariant 70). Currently `RespondWithAgent::isHumanOwned()`; S26-08 relocates it to `ThreadAgentState` |
| `agent_ineligible` | bound agent's `AgentDefinition::eligible(contact, siteId)` declines. The live `concierge` definition always returns `true`; the gate and skip reason stay for historical conversations resumed against a legacy agent. On live traffic the count should be zero. |
| `debounced` | another inbound on the same thread arrived within `config('agents.inbound_debounce_seconds')` (default 20); the job is released with delay so the last one runs |

Find-or-create the `agent_conversation` for `message_thread_id`
(`origin = inbox`, `audience = customer`). Then `AgentRuntime::turn(...)`.

### Bindings

`agent_channel_bindings` is unique on live `(channel, site_id)` — **one agent
per channel per site** (D-AI-19). Columns: `ai_agent_id`, bindable `channel`
(`email\|sms\|whatsapp\|webchat`; `voice` and `internal` share one 422),
nullable `site_id`, `mode` (`off` \| `draft` \| `auto`), `audience`
(`known_contacts` \| `existing_tenants` \| `all`), `outside_hours`
(`inbox` \| `answer`). Archive-only.

Under one agent, `audience` selects *who gets answered*, not *which agent
answers* (invariant 71). `eligible()` is not a routing mechanism:

| Value | Behaviour with `concierge` |
|---|---|
| `known_contacts` | Inbound matched a `contact_channels` row. Prospects and tenants both answered, at whatever verification they hold. Unmatched → `comms_triage` |
| `existing_tenants` | As above, and `AgentEligibility::hasInForceContractAtSite()` must pass |
| `all` | Matched contacts plus unmatched senders under the auto-lead-capture policy (D-AI-20, default off). Without that flag, unmatched still goes to triage |

Seeded company-wide rows (match key `(channel, site_id)`):

| Agent | Channel | mode | audience | outside_hours |
|---|---|---|---|---|
| concierge | webchat | `auto` | `all` | `answer` |
| concierge | sms | `draft` | `known_contacts` | `inbox` |
| concierge | whatsapp | `draft` | `known_contacts` | `inbox` |
| concierge | email | `draft` | `known_contacts` | `inbox` |

**Hazard: absent row = `off`.** Opposite default from `agent_write_policies`
(absent = commit). A missing binding means an agent talks to real customers;
that must be an explicit act (invariant 68). Resolution mirrors
`ProviderResolver`: site-scoped live row → company-scoped live row → `null`.

Kill switches, in order: `config('agents.enabled')` → `ai_agents.is_active &&
!archived` → binding present and `mode != off`. API:
`GET/POST /api/ai/agents/bindings`, `PUT/DELETE …/bindings/{binding}`
(`DELETE` = archive). Permission `AiAgentBindingManage`. Panel is S26-08
(create/edit slideover); S27-05 ships a read-only Channels tab.

### Send path

`App\Support\Ai\AgentSend` is the **only** code that turns a draft into a
send (invariant 69). It builds `SendContext(source: ai_agent, class:
transactional)` and calls `EmailSender` / `SmsSender` / `WhatsAppSender` on
the existing thread (`Threading::forExplicitThread`). Suppression, GSM-7
segment count, and WhatsApp session/template rules apply unchanged. The
sender writes the `messages` row; the agent sets
`agent_conversation_messages.emitted_message_id`. Sender refusal
(suppressed, window closed, `ChannelNotConfigured`) → no message, handoff
`channel_constraint`, Tier-1 `ai.send.refused`.

`channel.send` is a `ProposableTool` in the registry that **no agent claims**
(`RuntimeOnlyTools`). Dispatched by `AgentRuntime` as a terminal step of an
inbox turn, not via `ToolDispatcher::dispatch` (whose allowlist gate would
deny it). Binding mode, not `agent_write_policies`, picks the branch:
`draft` → `propose()` then `PendingActionRecorder`; `auto` → `handle()`.
Payload holds ids and body only — never rendered segments or the window
deadline (invariant 62). Preview holds derived values; `expires_at` is
`min(now() + pending_action_ttl, preview.window_closes_at)` when the
preview carries a window.

Approval of a `channel.send` pending action re-runs `AgentSend` against
current state. Reject accepts `resolution` (`discarded` \| `edited`) in
`detail.resolution`. Edit & send is a reject, then a normal inbox reply
(`source = manual`) with optional `agent_pending_action_id`; the reply path
back-fills `detail` only when the action belongs to this thread, `tool_key
= channel.send`, and status is rejected-as-edited. The approve endpoint
gets **no** `body` parameter.

Hand-back: `POST /api/inbox/threads/{id}/agent/resume` sets `state = active`,
`agent_handback_at = now()`, does **not** trigger a turn. Permission
`InboxSend`. Hand-back is a click (invariant 70).

`GET /api/inbox/badge` includes `agent_drafts` and `agent_handoffs`. Inbox
UI for the draft card, badges, and hand-back is S26-08. Webchat public
routes (`POST /api/chat/sessions`, …) are S26-07c.

