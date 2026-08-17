# Customer-facing AI agents

The **agent runtime** — principal, tool contract, guardrails, telemetry — plus
a single demo surface at panel `/demo/chat`. Copilot (internal, employee
principal, screen output) stays on its existing Laravel AI SDK path and is
**not** migrated onto this runtime. Support and sales agents are
customer-facing: contact principal, **draft-turn** output. Nothing here sends
an email, SMS, or WhatsApp message, or creates a `messages` row.

The same runtime, tool set, and guardrails are what a channel will call when
transport lands. Rendering-instead-of-sending is `suggest` autonomy with a
null transport.

## What an agent is

Three surfaces share the word "agent" and must not be confused:

| Surface | Principal | Output | Runtime |
|---|---|---|---|
| Copilot | Employee (`AgentAudience::Internal`) | Screen (sidebar) | Existing Laravel AI SDK path — left alone |
| Support agent | Contact | Draft turn (channel-shaped) | `App\Support\Ai\AgentRuntime` |
| Sales agent | Contact (often anonymous) | Draft turn (channel-shaped) | same |

Inbound sender matching in `06-communications.md` was designed as a
**routing** decision: match `contact_channels` → attach to a thread; no match
→ `comms_triage`. An agent turns that match into an **authorization**
decision — "does this row belong to the contact I am talking to, and at what
verification level?" That promotion is why `VerificationLevel` exists. A
matched inbound address is `channel_asserted`, not `verified`. Asking "what's
my balance?" at `channel_asserted` is refused; the same question at `verified`
returns a ledger figure. See D-AI-2 in `10-open-decisions.md`.

## Principal and verification

`App\Support\Ai\AgentPrincipal` is an immutable value object, constructed once
per turn at the HTTP / console boundary and **passed as an argument** into the
runtime and every tool call (D-AI-1, invariant 56). Factories:

- `anonymous(?siteId, locale)` — no contact
- `channelAsserted(contactId, ?siteId, locale)` — sender matched, not proven
- `verified(contactId, ?siteId, locale)` — identity confirmed
- `employee(employeeId, ?siteId, locale)` — internal audience (copilot-shaped;
  unused by the customer-facing loop)

`ownsContact()` is strict identity, not "related to". A contact does not own
another contact's row because they share a deal.

`agent_conversations` stores the facts (`contact_id`, `verification_level`);
the principal is rebuilt from them each turn and never cached as an
authorization scope on a model. Never resolve a principal from `auth()`,
`request()`, a container binding, or a static inside a tool.

`VerificationLevel::satisfies()` is the only comparison anyone may write
(rank: `anonymous` 0, `channel_asserted` 1, `verified` 2).

| Tier | Level | Content |
|---|---|---|
| Public | `anonymous` | Catalogue prices, availability, site info, FAQ |
| Asserted | `channel_asserted` | Nothing extra — reserved for lead-shaped writes (`crm.create_note` requires it) |
| Private | `verified` | Anything tenant-specific: balance, invoices, contract, access, unit identifier |

A unit number is tenant-specific. So is a move-out date. Err upward.

**Employee RBAC does not authorize agent tools (D-AI-2).** RBAC answers "which
sites may this staff member see" (`07-people-and-auth.md`). Agent
authorization answers "does this row belong to the contact I am talking to."
Tools may still resolve site through `SubjectSite`, but the gate is
`VerificationLevel` + contact ownership. Reusing `employee_roles` for a
Contact principal would silently grant cross-tenant reads.

The operator hitting `/demo/chat` is authorized by `Permission::AiAgentUse`
(`ai_agent.use`) — that is the *operator* of the harness, not the principal
the agent is talking to.

## Runtime

Shared helpers live under `App\Support\Ai\` (same tier as
`App\Support\Billing\`, not an `app/Services/` layer):

```
App\Support\Ai\
├── AgentRuntime.php              // the turn loop
├── AgentPrincipal.php
├── AgentContext.php              // principal + channel + definition + conversation
├── AgentTurn.php
├── ChannelProfile.php
├── Agents/                       // AgentDefinition, registry, support / sales
├── Tools/                        // AgentTool, ToolDispatcher, ToolResult, FactBag
├── Guards/                       // inbound + outbound pipelines
├── Drivers/                      // ModelDriver, LaravelAiDriver, CassetteDriver
└── Eval/                         // agent:replay harness
```

Copilot summaries (`Summaries/`, `CopilotDispatcher`) share the namespace and
are a different path. Do not treat them as part of this loop.

### Turn loop (`AgentRuntime::turn`)

Kill switches run at the runtime entry point, not only in the controller:
`config('agents.enabled')` and `ai_agents.is_active` / not archived. A switch
that covers one caller is not a switch.

1. Rebuild / assert the principal against the conversation facts.
2. **Inbound guards** (`InboundGuardPipeline`): `LoopGuard` → `BudgetGuard` →
   `HandoffRules`. A match short-circuits: no model call, write
   `agent_handoffs`, set state `awaiting_human`, return a canned line.
3. Persist the `user` message row.
4. Model call via `ModelDriver`. Persistence is **not** held open across it.
5. Tool loop (capped by `config('agents.max_tool_calls_per_turn')`). Each call
   goes through `ToolDispatcher` (below), persists `agent_tool_invocations`,
   merges into the turn `FactBag`.
6. Final assistant draft → **outbound guards**. A block writes the message
   with `blocked_by` set and converts the turn to a handoff. The customer
   never sees a blocked draft.
7. Persist the assistant message, write agent-attributed `ai_usage_events`
   (reserve then settle), update `last_turn_at`.
8. Return `AgentTurn` (draft, channel, facts, invocations, handoff, usage).

Timeouts (`agents.turn_timeout_ms`) become a handoff `error`, never a partial
send.

### Four dispatch gates (`ToolDispatcher`)

Authorization happens **before** `AgentTool::handle()` is reached:

1. Tool is in the current `AgentDefinition::toolKeys()` → else
   `denied: not_allowed_for_agent`
2. `principal.verification.satisfies(tool.requiredVerification())` → else
   `denied: verification`
3. Arguments validate against `schema()` → else `error`
4. Contact-scoped arguments match `principal->ownsContact()` → else
   `denied: ownership`
5. `handle($principal, $arguments, $ctx)`

A tool that re-checks internally is fine; a tool that *only* checks internally
is a defect (invariant 56).

### `ModelDriver`

```
stream(messages, tools, model, ?onDelta): ModelResponse
```

- `LaravelAiDriver` — production, Laravel AI SDK. Live providers receive underscore wire names (`facility_availability`); catalogue keys stay dotted (`facility.availability`).
- `CassetteDriver` — deterministic replay for `php artisan agent:replay`
  (default, CI-safe, no network). Live recording uses `RecordingModelDriver`.

Bound by `config('agents.driver')`.

### `ChannelProfile`

Channel changes real behaviour with no transport. `ChannelProfile::for()`
feeds the system prompt (length / register) and `ChannelGuard`:

| Channel | Characters | HTML | Subject | Signature | Target sentences |
|---|---|---|---|---|---|
| `sms` | 1 600 soft cap; GSM-7 / UCS-2 segments | no | no | no | 2 |
| `email` | none | yes | yes | yes | 8 |
| `whatsapp` | none; template-outside-window is advisory this sprint | no | no | no | 3 |
| `webchat` | none | no | no | no | 4 |

WhatsApp `requiresTemplateOutsideWindow` is a trace advisory until a real
thread supplies `last_inbound_at` (`06-communications.md` / `WhatsAppWindow`).

### `ToolResult` and `FactBag`

Tools never return a raw number for the model to format. `display` is
pre-rendered prose the model may quote (`BillingMath` / `MoneyDisplay` for
money; exclusive tax, currency from the price row — D1, invariant 30).
`facts` licenses every amount, date, unit identifier, and percent the draft
may emit. `GroundingGuard` diffs the draft against the turn `FactBag`
(plus facts already licensed by earlier unblocked assistant turns).

## Tool catalogue

The tool surface is the defence; prompt text is defence-in-depth. Sixteen
tools. Definitions in code (`SupportAgentDefinition` / `SalesAgentDefinition`);
`ai_agents` rows are instances (D-AI-6, invariant 58).

| Tool | Level | Write | Sales | Support |
|---|---|---|---|---|
| `facility.availability` | anonymous | | ✓ | |
| `facility.site_info` | anonymous | | ✓ | ✓ |
| `pricing.quote` | anonymous | | ✓ | |
| `pricing.discounts` | anonymous | | ✓ | |
| `sales.propose_offer` | anonymous | proposal only — persists nothing | ✓ | |
| `crm.create_contact` | anonymous | ✓ | ✓ | |
| `crm.create_deal` | anonymous | ✓ | ✓ | |
| `crm.create_task` | anonymous | ✓ | ✓ | ✓ |
| `crm.create_note` | channel_asserted | ✓ | | ✓ |
| `contract.summary` | verified | | | ✓ |
| `billing.balance` | verified | | | ✓ |
| `billing.next_charge` | verified | | | ✓ |
| `billing.invoices` | verified | | | ✓ |
| `access.status` | verified | | | ✓ |
| `kb.faq_lookup` | anonymous | | ✓ | ✓ |
| `agent.escalate` | anonymous | | ✓ | ✓ |

Sales has **no** billing, contract, or access tools. A prospect asking about
someone's balance is a handoff.

Writes that **are** permitted mirror `CreateObjectAllowlist` exactly:
`Contact`, `Deal`, `Task`, `Note` (`12-automation-engine.md`). Contract /
Reservation / Offer creation is a transactional path (`ContractBilling`, offer
acceptance), not a field map — the same reasoning that excluded them from the
automation allowlist. `sales.propose_offer` returns a fully priced proposal
and writes **no** `Offer` row.

### Why the agent cannot tell you your balance

`billing.balance` requires `verified`. At `anonymous` or `channel_asserted`
the dispatcher returns `denied: verification` **before** `handle()` and never
touches the ledger. Sales cannot call the tool at all
(`not_allowed_for_agent`). That is why flipping the demo verification toggle
is the argument for the architecture.

### Why a quoted price is trustworthy

`pricing.quote` reads the current `prices` row (currency from
`prices.currency`, never the site), resolves tax via `TaxResolver`, computes
with `BillingMath` (exclusive: `tax = round(net × rate/100, 2)`), and puts
the rendered string in `display` and the cents in `FactBag`. The model quotes
that string. If it invents a figure, a tax percent, or a unit id,
`GroundingGuard` suppresses the draft and hands off (`grounding_failure`). A
suppressed draft is never delivered (invariant 55).

Other tool notes:

- `facility.availability` goes through `App\Support\Occupancy\Availability`
  (invariant 5 / 36). Counts and classes, **not** unit identifiers.
- `facility.site_info` and `kb.faq_lookup` absorb display tokens into
  `FactBag` so quoted addresses, hours, and phones are licensed.
- `billing.balance` returns an array keyed by currency and never a summed
  figure (invariant 30). Derived at read time (invariant 5).
- `billing.next_charge` reads the contract's snapshotted cadence (invariant
  18); date boundaries through `SiteClock` (D8).
- `billing.invoices` lists issued invoices. No PDF, no signed URL.
- `access.status` reports active / suspended / overlocked and a reason
  category. Never a gate code, credential, or door id. A delinquency
  suspension is an escalation trigger, not an explanation.
- `kb.faq_lookup` reads curated snippets from `config/ai-knowledge/{locale}.php`
  by key (`access_hours`, `insurance_required`, `notice_period`,
  `prohibited_items`, `overlock_policy`, `deposit`, `id_required`,
  `payment_methods`). No free-text search, no embeddings (D-AI-5). Unknown
  key → `not_found` → escalate, never improvise policy.
- `crm.create_contact` sets `contacts.source = ai_agent` and deduplicates on
  `contact_channels`.
- `agent.escalate` is model-invocable (`trigger_source = model`) and does not
  replace deterministic pre-model rules.

## Guardrails and handoff

Order is the contract. Inbound runs against the raw input **before** any
model call; outbound runs against the assistant draft **before** it leaves
the runtime.

**Inbound:** `LoopGuard` → `BudgetGuard` → `HandoffRules`.

**Outbound:** `DuplicateDraftGuard` → `GroundingGuard` → `ForbiddenClaimGuard`
→ `DisclosureGuard` → `ChannelGuard`.

A block at outbound suppresses the draft, writes `blocked_by` on the message
row, and writes `agent_handoffs` (`trigger_source = guardrail`).

### Deterministic handoff rules (pre-model)

`App\Support\Ai\Guards\HandoffRules`. Keyword lists in `config/ai-handoff.php`
(en / es / fr); **rule keys and reasons are enum cases**, not config strings.
Word-boundary, accent-folded, case-insensitive. False positives are cheap; false
negatives are expensive.

| Rule key | Reason | Trigger |
|---|---|---|
| `legal_or_complaint` | `legal_or_complaint` | lien, auction, solicitor / lawyer / abogado, ombudsman, court, chargeback, erasure / GDPR, death / estate, damage or insurance claim |
| `delinquency` | `delinquency` | open delinquency case on the principal, **or** arrears / overlock / cut lock / "why can't I get in" |
| `price_negotiation` | `price_negotiation` | discount / cheaper / match / negotiate beyond the catalogue |
| `move_out_commitment` | `unsupported_intent` | notice, vacate, move out, terminate — collect intent, never commit a date |
| `payment_dispute` | `unsupported_intent` | "I already paid", "charged twice", refund |
| `customer_requested` | `customer_requested` | human / agent / manager / "real person" |
| `third_party` | `legal_or_complaint` | asking about another tenant |

**No autonomy inside delinquency, ever, in any sprint.** An agent chasing or
discussing debt in ES/UK touches collections law. It is a hard escalation.

### Grounding

Highest-value outbound guard. Extracts currency amounts, civil dates
(`2026-08-17`, `17/08/2026`), unit-shaped identifiers, and percents from the
draft; every token must be in the licensed `FactBag` (this turn's tools plus
earlier unblocked assistant facts, plus numbers the customer themselves
supplied). Relative-day words (`today` / `tomorrow`, and es/fr equivalents)
are not dates. Invented `21%` VAT is the exact failure this exists for.

### Forbidden claims

Pattern set over the draft (shared `config/ai-handoff.php` plus per-agent
`forbiddenClaims()`): payment confirmation, fee waiver, access grant,
availability guarantee ("I've held it"), legal advice, contract mutation.
Match → block + `unsupported_intent`.

### Disclosure

Leak check: below `verified`, a draft that contains an amount, unit
identifier, contract date, or address is blocked. AI disclosure (EU AI Act
Art. 50): the first assistant turn of a customer-facing conversation must
state that it is automated; if missing, the configured line is **appended**,
not blocked.

### Channel

SMS over `maxCharacters` → one shorter retry, then handoff. Segment maths
are GSM-7 vs UCS-2. Email without a subject when `supportsSubject` → block.
HTML in a plain-text channel is stripped. WhatsApp session-vs-template is
advisory until S23.

### Prompt injection

Customer text and tool results are **data**. The system prompt says so; the
real defence is the tool surface. There is no tool that applies a discount —
only one that lists catalogue ids. `sales.propose_offer` cannot exceed
catalogue terms. Ownership is checked before `handle()`. No guard tries to
*detect* injection by pattern.

## Trace tables

Agent conversations are a **reasoning trace, not the message store** (D-AI-3,
invariant 57). Invariant 38 is unchanged: a `messages` row means exactly one
real send or receipt. Agent drafts are never `messages` rows.
`message_thread_id` and `emitted_message_id` are always null this sprint; when
channels land they link the trace to the canonical thread. The Inbox stays
the single operator surface.

`AiAgent` is a real Eloquent model so agent writes can stamp
`activity_log.causer_type` (D-AI-4). **No morph-map alias**, for the same
reason `employee` has none. Archive-only (`archived_at`). No
`HasAutomationTriggers` on any agent table.

| Model | Table | Role |
|---|---|---|
| `AiAgent` | `ai_agents` | Instance, not definition. `key` must resolve to an `AgentDefinition`. `settings` = tuning knobs only — never the prompt or tool list |
| `AgentConversation` | `agent_conversations` | One conversation. `audience` (`internal` \| `customer`), `origin` (`demo` \| `inbox` \| `webchat`, **never null**), `channel`, principal facts, `state` (`active` \| `awaiting_human` \| `handed_off` \| `closed`) |
| `AgentConversationMessage` | `agent_conversation_messages` | Append-only turns (`sequence`). `blocked_by` when a draft was suppressed. `emitted_message_id` always null this sprint |
| `AgentToolInvocation` | `agent_tool_invocations` | What it looked at: `tool_key`, arguments, result, `denied_reason`, verification snapshots |
| `AgentHandoff` | `agent_handoffs` | Escalation: `reason`, `trigger_source` (`rule` \| `model` \| `customer` \| `guardrail`), `detail` |

CHECK constraints bind the shape in the database: internal audience requires
`employee_id` and forbids `contact_id`; customer audience forbids
`employee_id`; `verified` requires a contact; `origin = demo` requires
`created_by_employee_id`.

`origin` is never null (D-AI-7, invariant 59). Demo traffic is excluded by an
**explicit filter at each call site** — never a global scope (invariant 46).
Demo conversations must not reach Insights, eval corpora, or any
operator-facing metric.

## Telemetry

`ai_usage_events` is operational telemetry, not the ledger (invariant 48).
Customer-facing turns stamp `ai_agent_id` + `agent_conversation_id` and leave
`employee_id` null (`CHECK (employee_id IS NOT NULL OR ai_agent_id IS NOT NULL)`).
Reserve before the provider call, settle after with real token counts.
Estimated cost is derived at **read time** from `ai_model_prices` — never
stored, never an in-place rate update, never reconciled to the provider
invoice, never summed across currencies. The figure attributes spend between
employees **or agents**; it is not an accounting record.

Tier-1 `SystemEvent` `ai.turn.failed` on driver errors and timeouts.

## Demo surface

Panel `/demo/chat` (`unit-hq-panel/app/pages/demo/chat.vue`). Flag-gated; **not
an operator surface**.

- `origin = demo` is refused with 422 unless `config('agents.demo_enabled')`
  is true (`AGENTS_DEMO_ENABLED`, default **false**).
- `verification_level` is accepted from the client **only** for `origin = demo`.
  For `inbox` / `webchat` it will be derived from how the message arrived.
- `audience` is derived from whether a `contact_id` is present, never trusted
  from the client.
- Permission: `Permission::AiAgentUse`. Nav visible when the flag is on and
  the employee holds the permission.
- `GET /api/ai/demo-personas` is the one genuinely demo-shaped endpoint
  (seeded contacts with contract / balance / delinquency flags).

Three inputs a live channel would supply are selected by the operator instead
of being faked: agent (`support` / `sales`), channel (`email` / `sms` /
`whatsapp` / `webchat`), principal + verification (persona + level toggle).
The right pane is the trace — tools, guardrail pass/block, usage.

API (all `auth:sanctum`, no `/api/demo/*` prefix): `GET /api/ai/agents`,
`POST/GET /api/agent-conversations`, `GET …/{id}`, `POST …/{id}/turns` (SSE),
`POST …/{id}/close`.

Eval: `php artisan agent:replay` replays YAML fixtures under
`tests/Fixtures/agents/` against `CassetteDriver`. Default mode is CI-safe.

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
- creates a `Contract`, `Reservation`, or `Offer` row;
- returns data belonging to a contact other than the principal.

Not with confirmation. Not with an operator in the loop. Not behind a flag.
Those are operator actions reached through operator surfaces (invariant 54).

Also not built:

- Any transport — no `SendContext`, no sender call, no `messages` row.
- `webchat` as a comms `Channel` enum value and its adapter (agent
  `AgentChannel::Webchat` is a profile only).
- RAG / vector retrieval (D-AI-5). Knowledge is curated key lookup.
- `contact_verifications` / OTP — the verification level is a demo toggle.
- Autonomy beyond `suggest` (`review` / `auto` are S23+, gated on measured
  containment, never on a date).
- Per-agent, per-channel autonomy configuration.
- Copilot on this shared runtime.
- Insights reports on agent performance (tables are shaped for them; harvest
  principle applies).
- Delinquency autonomy — never, in any sprint.
- Extending `contacts:redact` to agent tables — **blocking for S23** (AR-03
  in `10-open-decisions.md`). S22 traffic is `origin = demo` against
  `demo:seed` fiction.

## Related docs

- `06-communications.md` — inbound sender matching (today's routing decision);
  the S23 seam where a match becomes `VerificationLevel`; `messages` stays
  canonical (invariant 38)
- `07-people-and-auth.md` — employee RBAC is a different axis from agent
  authorization (D-AI-2)
- `09-conventions-and-invariants.md` — invariants 54–59, amended 48
- `12-automation-engine.md` — `CreateObjectAllowlist` (the write-path parallel)
- `10-open-decisions.md` — D-AI-1…7, AR-03 blocking for S23, deferred autonomy
- `08-activity-logging.md` — tier-2 `ai` channel; activity log is not a transcript
- `01-stack.md` — stack line and `/demo/chat` page map
