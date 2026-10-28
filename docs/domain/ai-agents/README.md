# Customer-facing AI agents

The **agent runtime** — principal, tool contract, guardrails, telemetry — plus
a demo surface at panel `/demo/chat` and live inbound on email / SMS /
WhatsApp under an explicit `agent_channel_bindings` row (default **off**,
invariant 68). Copilot (internal, employee principal, screen output) stays
on its existing Laravel AI SDK path and is **not** migrated onto this
runtime. The customer-facing agent is `concierge`: contact principal.
Output is a draft turn; a send goes through `AgentSend` onto the channel
senders (invariant 69). Agent code never inserts a `messages` row
(invariant 38).

Webchat as a comms `Channel` is S26-07c and is not built. Inbox bindings
settings, the Inbox draft card, and the public `/chat/:token` page are
S26-08.

## What an agent is

Three surfaces share the word "agent" and must not be confused:

| Surface | Principal | Output | Runtime |
|---|---|---|---|
| Copilot | Employee (`AgentAudience::Internal`) | Screen (sidebar) | Existing Laravel AI SDK path — left alone |
| Concierge | Contact (anonymous, channel-asserted, or verified) | Draft turn (channel-shaped) | `App\Support\Ai\AgentRuntime` |

`SalesAgentDefinition` and `SupportAgentDefinition` remain registered
classes so historical `ai_agents` rows still resolve. They are not live
personas (D-AI-22, invariant 58).

Inbound sender matching in `../06-communications.md` was designed as a
**routing** decision: match `contact_channels` → attach to a thread; no match
→ `comms_triage`. An agent turns that match into an **authorization**
decision — "does this row belong to the contact I am talking to, and at what
verification level?" That promotion is why `VerificationLevel` exists. A
matched inbound address is `channel_asserted`, not `verified`. Asking "what's
my balance?" at `channel_asserted` is refused; the same question at `verified`
returns a ledger figure. See D-AI-2 and D-AI-23 in `../../reference/10-open-decisions.md`.


## Agent registry

`ai_agents` rows are **instances**, not definitions (D-AI-6, invariant 58). An
`ai_agents.key` with no matching `AgentDefinition` is a defect — enforced by
`AgentDefinitionCoverageTest`. The row binds a model (`ai_agents.model`),
display name, archive/active flags, and tuning knobs in `settings`. It never
holds the prompt or the tool list.

One live persona ships as a definition (`ConciergeAgentDefinition`); the
seeded live row uses key `concierge`, name `Customer Agent`.
`SalesAgentDefinition` and `SupportAgentDefinition` stay registered forever
so historical conversations still resolve — `AiAgent::definition()` calls
`AgentRegistry::get()`, which throws on an unknown key, and
`agent_conversations.ai_agent_id` is `restrictOnDelete`. Deleting either
class 500s the Inbox list. Archived seeded rows (`sales`, `support`, names
`Sales Agent (archived)` / `Support Agent (archived)`) stay seeded on a
fresh `db:seed`. Adding a persona is a code change plus a coverage-test
update, not a Settings form.

Site scope is conversation provenance, not a grant. `agent_conversations.site_id`
is seeded at create (inbound destination, demo pane, or null). It is not RBAC.
Written pipeline rows stamp `source = ai_agent` and `ai_agent_id`
(`PipelineSource::AiAgent`) — the CRM home is `../04-crm-pipeline.md`.

`AiAgent` is a real Eloquent model so agent writes can stamp
`activity_log.causer_type` (D-AI-4). **No morph-map alias**, for the same
reason `employee` has none (invariant 15). Archive-only (`archived_at`).

Conversation `site_id` is seeded at create, not inside tools.
`InboundSiteContext` matches the **inbound destination** (the operator-owned
identity on `site_sender_identities.from_number` / `from_email` — the number
or address the customer contacted), never the customer's From. Order: sender
identity for that channel + destination; else a site-scoped
`communication_accounts.site_id`; else null (company-scoped sender, web chat).
If identity site and account site disagree, the identity wins and a tier-1
`SystemEvent` `ai.inbound.site_disagreement` is recorded — create still
succeeds. An explicit `site_id` on the request (demo pane) still wins. That
context site is a provenance licence under source (3) via `FactRegistry::seedContext()`.
Eval fixtures may set `principal.site: none` so the harness leaves `site_id`
null (default remains Madrid).



## Map

| File | What it holds |
|---|---|
| [runtime-and-guards.md](runtime-and-guards.md) | Principal, runtime, guardrails, traces, telemetry, demo surface |
| [tools-and-write-policies.md](tools-and-write-policies.md) | Tool catalogue, write policy, pending actions |
| [channels-and-bindings.md](channels-and-bindings.md) | Live channels |
| [voice.md](voice.md) | Customer-facing voice (Vocal Bridge) |
| [internal-agents.md](internal-agents.md) | Copilot, summary agent, template assistant |

## Known gaps

These are defects with a home, not open questions.

1. **Conversation redaction (AR-03).** `agent_conversation_messages`,
   `agent_tool_invocations`, `agent_handoffs.detail`, and
   `agent_pending_actions.payload.body` hold names, emails, balances, and
   drafted replies. `chat_sessions.visitor_meta` arrives with S26-07c.
   `contact_verifications` holds a contact id, a channel id and a code hash
   — no plaintext PII, but a record that a named person was asked to prove
   identity. Voice adds `voice_sessions` (`caller_number`, `contact_id`,
   `bridge_session_id`) and `voice_session_turns.answer_text`. Processor
   audio joins the list if Vocal Bridge retains call audio. `config/redaction.php`
   covers `activity_log` and `system_events` only (it comments
   `contact_verifications` and the voice tables but `contacts:redact` does
   not touch them). `ai_summaries` **is** already covered (invariant 53).
   Transcripts are evidence in a lien or auction dispute — they retain on
   contract terms, not on the telemetry pruning schedule that covers tier-1
   system events. **Blocking before a provider channel binding is set to
   `auto` in production. Customer voice does not launch while AR-03 is
   open.** S26-07 already writes real inbound text onto these
   tables. Detail: `../../reference/10-open-decisions.md`.
2. **Two authorization systems.** `agent_write_policies` governs what agents
   may write; `roles` / `role_permissions` / `employee_roles` governs what
   people may write. They will drift. Whether an agent should hold a real
   grant with a real site scope (write policy reduced to mode and quota) is
   Undecided in `10` — recorded so it is settled deliberately rather than by
   accretion. S27-03's policy merge (strictest-wins onto `concierge`) does
   not settle this.
3. **The panel has no test tooling.** `unit-hq-panel/package.json` carries
   `lint`, `typecheck`, `build` and nothing else: no vitest, no runner, no
   spec files. CI runs lint then typecheck only. Every panel-side property
   — verification state labels, the promotion timeline row, the
   verification-denied chip, the read-only bindings table — rests on
   typecheck plus manual verification. Typecheck does not catch an
   unreachable branch.

## What is deliberately not built

A customer-facing agent tool **never**:

- writes to the ledger (`charges`, `payments`, `allocations`, reversals,
  write-offs);
- mutates a `Contract` or `ContractItem`, schedules a rate change, applies or
  removes a discount;
- grants, restores, or suspends access;
- issues, voids, or reissues an invoice;
- confirms that a payment has been received (invariant 11 — confirmation is
  rail-specific and never optimistic);
- creates a `Contract` row;
- returns data belonging to a contact other than the principal.

Not with confirmation. Not with an operator in the loop. Not behind a flag.
Those are operator actions reached through operator surfaces (invariant 54a).
Offer and Reservation creation are permitted under 54b, through
`App\Support\Leasing\`, not through this never-list.

Also not built:

- **Sending** an offer. Creation is not delivery (D-AI-10). Replies go out
  through `AgentSend`; the offer link is still sent by an operator or a later
  playbook action. No `OfferDelivery` row from the agent.
- `webchat` as a comms `Channel` enum value and its adapter — S26-07c.
  Agent `AgentChannel::Webchat` is a profile only.
- RAG / vector retrieval (D-AI-5). Knowledge is curated key lookup.
- Binding mode `review` (delayed auto-send with a cancel window). `draft` and
  `auto` shipped in S26-06/07; do not set a provider channel to `auto` in
  production until AR-03 lands.
- Copilot on this shared runtime.
- Insights reports on agent performance (tables are shaped for them; harvest
  principle applies).
- Delinquency autonomy — never, in any sprint.
- Auto-lead capture — S26-07b.
- Binding create/edit/archive slideover, Inbox draft card, `/chat/:token` —
  S26-08. A read-only Channels tab shipped in S27-05.
- Customer-facing voice. `AgentChannel::Voice` stays out of
  `AgentChannel::bindable()` and `POST /api/agent-conversations` keeps its
  422. Sprint 28.

## Related docs

- `../02-facility.md` — `site_service_areas`, site coordinates, `size_guides`
- `../03-pricing.md` — quote-to-offer `price_id` continuity (invariant 66)
- `../04-crm-pipeline.md` — `source` / `ai_agent_id` on Offer and Reservation
- `../06-communications.md` — inbound sender matching; `RespondWithAgent`;
  `SendContext` source `ai_agent`; GSM-7 / SMS segment ceiling apply to
  agent drafts only
- `../07-people-and-auth.md` — employee RBAC is a different axis from agent
  authorization (D-AI-2)
- `../../reference/09-conventions-and-invariants.md` — invariants 54a/54b, 55–72, amended 38 / 48 / 58 / 64; 40 pointer to S26-07b
- `../12-automation-engine.md` — `CreateObjectAllowlist` (unchanged; agent surface is deliberately wider)
- `../../reference/10-open-decisions.md` — D-AI-1…23, D-V1…4; AR-03 is a known compliance
  defect (blocking before a provider binding is `auto` in production), not
  an open question
- `../08-activity-logging.md` — tier-2 `ai` channel; tier-3 copilot voice sessions; activity log is not a transcript
- `../01-stack.md` — stack line and `/demo/chat` page map


History: S19, S22, S23, S24, S25, S26, S27, S28, S34, S35 — [sprint index](../../history/README.md).
