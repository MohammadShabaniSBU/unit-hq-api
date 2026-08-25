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

`agent_conversations` stores the facts (`contact_id`, `verification_level`,
`site_id`); the principal is rebuilt from them each turn and never cached as an
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

## Agent registry

`ai_agents` rows are **instances**, not definitions (D-AI-6, invariant 58). An
`ai_agents.key` with no matching `AgentDefinition` is a defect — enforced by
`AgentDefinitionCoverageTest`. The row binds a model (`ai_agents.model`),
display name, archive/active flags, and tuning knobs in `settings`. It never
holds the prompt or the tool list.

Two personas ship as definitions (`SupportAgentDefinition`,
`SalesAgentDefinition`); seeded rows use keys `support` and `sales`. Adding a
persona is a code change plus a coverage-test update, not a Settings form.

Site scope is conversation provenance, not a grant. `agent_conversations.site_id`
is seeded at create (inbound destination, demo pane, or null). It is not RBAC.
Written pipeline rows stamp `source = ai_agent` and `ai_agent_id`
(`PipelineSource::AiAgent`) — the CRM home is `04-crm-pipeline.md`.

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
├── Tools/                        // AgentTool, ToolDispatcher, ToolResult, FactBag, FactRegistry
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

### Dispatch gates (`ToolDispatcher`)

Authorization happens **before** `AgentTool::handle()` is reached.
`ToolDispatcher::GATE_SEQUENCE` is the order `dispatch()` walks — this is the
code order, not the shortened sprint-file list:

1. `allowlist` — tool is in the current `AgentDefinition::toolKeys()` (and
   registered) → else `denied: not_allowed_for_agent`
2. `policy_off` — policy `mode = off` → `denied: not_allowed_for_agent`
3. `verification` — `principal.verification.satisfies(effectiveVerification())`
   → else `denied: verification`
4. `schema` — arguments validate against `schema()` → else `error` /
   `error_code: invalid_arguments`. **Before idempotency:** a malformed call is
   not a retryable write and consumes no idempotency slot and no quota
   (invariant 65).
5. `provenance` — `ArgumentProvenance::denyIfUnlicensed` (invariant 64). Rebuilds
   a conversation-scoped `FactRegistry` from channel/contact context, explicit
   user-stated identifiers (`site 1`, `site with id 1`, `unit A-114`), and
   `entities` on prior `status = ok` invocations. `AgentRuntime::turn()` calls
   `beginTurn()` after persisting the user message so the memo resets each turn;
   each subsequent ok result is `absorb`ed so later calls in the same turn see
   it. A miss is `denied: unlicensed_argument` with `ToolError` recovery naming
   the licensing tool (`facility.availability` for `unit_class`,
   `facility.find_sites` for `site`). There is no discovery exception:
   `facility.availability` with an unlicensed `unit_class_id` is denied too.
   Nested `unit_class_rate_id` is licensed transitively: the rate id itself is
   never an `EntityRef`; it may only resolve to a licensed `unit_class`
   (`checkRateIds`, re-asserted in `CatalogueLinePricer`, fail-closed if the
   registry is missing). Missing rate rows use the same reason — not an
   existence oracle. Unknown or missing `related_to_type` morph aliases are
   `invalid_arguments`, never `unlicensed_argument` — there is no licensing
   tool for an untyped id. **Provenance before ownership:** fabricated and
   real-but-unowned ids fail identically. Write tools receive the same registry
   on `AgentContext::$factRegistry`.
6. `ownership` — contact-scoped arguments match `principal->ownsContact()` →
   else `denied: ownership`
7. `idempotency` — replay (write tools)
8. `quota` — write tools
9. `propose_or_handle` — `propose` or `handle($principal, $arguments, $ctx)`

An empty argument bag is an object (`{}`), never a JSON list. `ArgumentBag`
normalises at the model-response boundary; `agent_tool_invocations.arguments`
uses `ArgumentBagCast`.

A tool that re-checks internally is fine; a tool that *only* checks internally
is a defect (invariant 56).

`denied_reason` is the gate vocabulary (`verification`, `ownership`, `quota_exceeded`,
…). `error_code` (`ToolErrorCode`) is the machine code. A provenance denial has
both. Denial and failure `display` is a recovery-oriented one-liner; the
developer `message` is trace-only and is never fed to the model.

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
| `voice` | 600 | no | no | no | 2 |

`voice` is on the enum so `ChannelProfileTest` stays exhaustive. Copilot does
**not** read this profile — spoken form is `$voice` on `CrmCopilotAgent`.

WhatsApp `requiresTemplateOutsideWindow` is a trace advisory until a real
thread supplies `last_inbound_at` (`06-communications.md` / `WhatsAppWindow`).

### `ToolResult`, identity echo, and `FactBag`

Tools never return a raw number for the model to format. `display` is
pre-rendered prose the model may quote (`BillingMath` / `MoneyDisplay` for
money; exclusive tax, currency from the price row — D1, invariant 30). It is
the single line fed to the model (and stored as `result_summary`). Structured
`data` and `entities` persist on the invocation row and stay out of model
context (invariant 65).

`entities` is `Array<EntityRef>` — `{ type, id, label, context }` — every
entity the result names. A payload that contains `site_id` / `unit_class_id`
(or any other entity id) must list a matching ref. `EntityType` morph-overlapping
cases (`contact`, `deal`, `offer`, `reservation`, `contract`, `unit`, `invoice`,
`task`, `note`) use the morph-map alias verbatim; non-morph additions are
`site`, `unit_class`, `discount`.

The invocation `result` JSON **merges at top level**: payload keys sit beside
reserved siblings `entities` and optional `error`
(`{ code, message, recovery, candidates, detail }`). `data` itself must not declare
those keys. Historical rows without them remain valid (missing `entities` = none).
Replay strips the reserved keys before reconstructing in-memory `data`.

Tool failures return `ToolError` (`error_code` + developer `message` + optional
`recovery: { tool, hint }` + `candidates` + optional `detail`) — invariant 65.
`facility.site_info` with no `site_id` argument and no site on the principal
asks `SiteResolver` with no query: exactly one active site succeeds
(`match_reason: only_site` in `display`); otherwise `site_unresolved` with
`candidates` and `recovery.tool = facility.find_sites`. Candidates do **not**
mint licences — the model must call `find_sites`. That error does **not** set
`handoffReason`; the tool loop continues until `max_tool_calls_per_turn`.

`facts` licenses every amount, date, unit identifier, and percent the draft
may emit. `GroundingGuard` diffs the draft against the turn `FactBag`
(plus facts already licensed by earlier unblocked assistant turns).

`FactRegistry` and `ForbiddenClaimKey` have different scopes on purpose —
see Licensing below. `entityArguments()` on each tool (or
`EntityArgumentExemptions`) is how the coverage test finds every `*_id`
schema key. Cassette hashes still use `key` + `description` + `schema` only
— `entityArguments()` is not in the hash.

Eval cassettes (`CassetteKey`) hash the **fully interpolated** system prompt
and tool schema assembly (`key`, `description`, `schema`). Trace
`prompt_version` hashes the template without `identityBlock` (D-AI-14). They
share `CassetteKey::promptHash`, not the input. `display` does **not**
participate — it is instance-specific. A task that changes a prompt-visible
`display` string must re-record affected cassettes
(`agent:replay --live --record`).

## Tool catalogue

The tool surface is the defence; prompt text is defence-in-depth. The
catalogue below… Definitions in code (`SupportAgentDefinition` /
`SalesAgentDefinition`); `ai_agents` rows are instances (D-AI-6, invariant 58).

| Tool | Level | Write | Sales | Support |
|---|---|---|---|---|
| `facility.availability` | anonymous | | ✓ | |
| `facility.find_sites` | anonymous | | ✓ | ✓ |
| `facility.site_info` | anonymous | | ✓ | ✓ |
| `facility.size_guide` | anonymous | | ✓ | ✓ |
| `pricing.quote` | anonymous | | ✓ | |
| `pricing.discounts` | anonymous | | ✓ | |
| `sales.propose_offer` | anonymous | proposal only — persists nothing | ✓ | |
| `sales.create_offer` | anonymous | ✓ (`commit`) | ✓ | |
| `sales.create_reservation` | channel_asserted | ✓ (`propose`) | ✓ | |
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

Writes that **are** permitted: `Contact`, `Deal`, `Task`, `Note` (the same
four as `CreateObjectAllowlist`), plus `Offer` and `Reservation` through named
entry points in `App\Support\Leasing\` under an explicit `agent_write_policies`
row (invariant 54b). The automation allowlist is unchanged
(`12-automation-engine.md`) — a generic field map still cannot mint a token,
pin a unit, or take a hold. The agent surface is wider because it calls those
entry points, not because the allowlist grew. Token, expiry, status, unit
selection and contact are server-derived; none may be a model argument.
`sales.propose_offer` returns a fully priced proposal and writes **no**
`Offer` row. Contract creation stays forbidden (invariant 54a).

### Why the agent cannot tell you your balance

`billing.balance` requires `verified`. At `anonymous` or `channel_asserted`
the dispatcher returns `denied: verification` **before** `handle()` and never
touches the ledger. Sales cannot call the tool at all
(`not_allowed_for_agent`). That is why flipping the demo verification toggle
is the argument for the architecture.

### Why a quoted price is trustworthy

`pricing.quote` reads the current `prices` row (currency from
`prices.currency`, never the site), names that row as `price_id`, resolves tax
via `TaxResolver` at `SiteClock::today($site)` (never `Carbon::today()`), and
returns `billing_interval` / `billing_interval_count` from org `BillingSettings`
so the summary is a period-qualified quote (`€70.00 net / €84.70 incl. 21% tax,
per month — Small at Madrid Centro`). It computes with `BillingMath` (exclusive:
`tax = round(net × rate/100, 2)`), and puts the rendered string in `display`
and the cents (and `as_of`) in `FactBag`. The model quotes that string. If it
invents a figure, a tax percent, or a unit id, `GroundingGuard` suppresses the
draft and hands off (`grounding_failure`). A suppressed draft is never delivered
(invariant 55).

`sales.create_offer` accepts optional `quoted_price_id` / `quoted_tax_rate_id`
per option (continuity tokens, not FactRegistry entities). When a prior `ok`
`pricing.quote` or `sales.propose_offer` named that class and the token is
absent, the tool refuses with `invalid_arguments` and a hint to pass
`quoted_price_id` — retryable, no handoff. When the token is present, the
creation path asserts that row is still the current catalogue price for the
junction; a closed window returns `price_superseded` with
`detail: { superseded: 'price' | 'tax_rate', quoted, current }` and recovery
`pricing.quote`. A tax-rate version change uses the same error code; `detail`
tells them apart. "Was this class quoted?" is answered from the **trace**
(`PriorCatalogueQuote`), not `FactRegistry` (invariant 66). The agent never
silently offers a number different from the one it stated.
`sales.propose_offer` line items echo `price_id` and `tax_rate_id` so
propose→create needs no second quote.

Other tool notes:

- `facility.find_sites` runs `SiteResolver` (city, postcode, or coordinates).
  Every row is an `EntityRef` of type `site`. `match_reason` is on each payload
  row **and** in `display` — `only_site` / `service_area` /
  `service_area_prefix` / `site_postcode` / `locality` / `distance` mean this
  is the customer's site; `no_match` means present the list and ask. Data-only
  is not enough: the model quotes `display`.
- `facility.site_info` takes an optional `site_id` (argument → principal site →
  resolver with no query). A resolver-supplied reason is echoed in `display`.
  Display tokens (address, hours, phone) are absorbed into `FactBag` so quoted
  strings are licensed.
- `facility.availability` goes through `App\Support\Occupancy\Availability`
  (invariant 5 / 36). Counts and classes, **not** unit identifiers.
- `facility.size_guide` is a **fit recommendation**, not a FAQ. `kb.faq_lookup`
  answers facts-about-the-site (hours, policy, prohibited items) as a static
  snippet for a curated key. Size guide takes a quantity predicate, resolves a
  band under site-over-org / class-over-band dominance, emits `EntityRef`s, and
  licenses `CapacityGuidance` for this turn. "What are your access hours" →
  `kb.faq_lookup`. "What size do I need for 24 boxes" → `facility.size_guide`.
  Do not add the next lookup tool as another FAQ key by default.
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
  `payment_methods`). Display tokens are absorbed into `FactBag`. No free-text
  search, no embeddings (D-AI-5). Unknown key → `not_found` → escalate, never
  improvise policy.
- `crm.create_contact` sets `contacts.source = ai_agent` and deduplicates on
  `contact_channels`.
- `sales.create_offer` calls `OfferCreation`. Seeded policy: `commit`,
  `max_per_conversation = 2`, `max_per_day = 50`. Does not send.
- `sales.create_reservation` calls `ReservationCreation` with auto-pick;
  `unit_id` and `expires_at` are never model arguments. Seeded policy:
  `propose`, `max_per_conversation = 1`, `max_per_day = 20`. Floor is
  `channel_asserted` — a fully anonymous webchat visitor gets a quote and an
  offer, not inventory.
- `agent.escalate` is model-invocable (`trigger_source = model`) and does not
  replace deterministic pre-model rules.

## Write policy and autonomy

Write autonomy is a table, not JSON (D-AI-9, invariant 58).
`agent_write_policies` is unique on `(ai_agent_id, tool_key)`. Operators edit
it at Settings `/settings/ai-agents`.

| `mode` | Meaning |
|---|---|
| `off` | Dispatcher denies before `handle()`. No row. |
| `propose` | Tool dry-runs, persists `agent_pending_actions`; operator clicks to commit. |
| `commit` | Tool writes in the turn. |

**Hazard: absent row = `commit`, unlimited.** A missing policy is not "off"
and is not "propose". That default must not change `crm.create_contact` /
`create_deal` / `create_task`. `sales.create_offer` and
`sales.create_reservation` seed their own rows explicitly so they cannot
inherit it by accident. Deleting a policy row restores unlimited commit.

Quotas (`max_per_conversation`, `max_per_day`) count `agent_tool_invocations`
where `status = ok` — committed writes, not attempts. A denied call does not
burn quota. `max_per_day` rolls at app-timezone midnight. Null = unlimited.

`min_verification` may **raise** the tool's declared floor, never lower it
(`AgentWritePolicy::effectiveVerification()`). String comparison against
verification levels is a defect.

### Promotion

Reservation stays in `propose` until a measured bar is met — never a calendar
date (D-AI-11): 200+ replayed conversations through `agent:replay` with zero
grounding suppressions on reservation turns, zero cross-site holds, zero
duplicate holds, and a measured approval rate above 90% (operators were
rubber-stamping, so the click was buying nothing). The bars are measured.
They are never dated.

## Pending actions

`mode = propose` persists an intent, never a result (invariant 62). Write
tools that can dry-run implement `ProposableTool`: `propose()` validates
against live state and returns `payload` + `preview` with no writes;
`commit()` persists. **Payload vs preview:** payload is stable write inputs
(ids, catalogue refs) — never `now()`, `defaultExpiry()`, availability
counts, or rendered display. Preview holds those derived values and is never
compared on approve.

Between propose and approve the world moves — the catalogue price changes, the
auto-picked unit gets rented. Approval re-runs the same `App\Support\Leasing\`
entry point against current state and may fail. No code path replays a stored
payload into the database.

Resolution is a click-only employee `POST` from `/leasing/agent-approvals` —
not a model, tool, inbound message, voice turn, or automation node (extends
invariant 60). Status: `pending` \| `approved` \| `rejected` \| `expired` \|
`superseded`. A second proposal of the same `(conversation, tool_key)` flips
the prior pending row to `superseded`.

## Guardrails and handoff

Order is the contract. Inbound runs against the raw input **before** any
model call; outbound runs against the assistant draft **before** it leaves
the runtime.

**Inbound:** `LoopGuard` → `BudgetGuard` → `HandoffRules`.

**Outbound:** `DuplicateDraftGuard` → `GroundingGuard` → `ForbiddenClaimGuard`
→ `DisclosureGuard` → `ChannelGuard`.

A block at outbound suppresses the draft, writes `blocked_by` on the message
row, and writes `agent_handoffs` (`trigger_source = guardrail`).

### Verdict vocabulary

Guard events record `pass` / `warn` / `deny`. Channel length over the SMS
ceiling is `deny` plus a model redraft (`GuardrailVerdict::retry`), bounded
by `max_redraft_attempts`, then a handoff.

| Guard | Enforcing? | Notes |
|---|---|---|
| `LoopGuard` | yes | Inbound short-circuit |
| `BudgetGuard` | yes | Inbound short-circuit |
| `HandoffRules` | yes | Inbound short-circuit |
| `DuplicateDraftGuard` | yes | Block + handoff |
| `GroundingGuard` | yes | Block + handoff (invariant 55) |
| `ForbiddenClaimGuard` | yes | Block + handoff; two keys are licensable (invariant 63) |
| `DisclosureGuard` | leak: yes; AI Act line: fill-in | Missing disclosure is **appended**, not blocked |
| `ChannelGuard` | SMS ceiling: yes; warn band: no; WhatsApp window: advisory | Email missing `Subject:` is filled in, not blocked |

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

### Licensing

Two registries, two scopes, on purpose. Do not collapse them for consistency
(D-AI-13, invariants 63 and 64).

| | `ForbiddenClaimKey` | `FactRegistry` |
|---|---|---|
| Licenses | Claims in the draft ("I've held it", "should work well") | Entity ids in **tool arguments** |
| Scope | **Turn.** Never persisted. | **Conversation.** Rebuilt from the append-only trace, not a table |
| Why | "I've reserved it" three turns later, after the hold was released, is false again | Availability came back two turns before the quote; a turn-scoped registry would deny the *correct* call |
| Denials | Suppress the draft | Do not mint entity licences |

`ForbiddenClaimKey` has two cases: `AvailabilityGuarantee` (licensed by a
committed `sales.create_reservation` — never from `propose()`, never on
`notFound`) and `CapacityGuidance` (licensed by an `ok` `facility.size_guide`
result — never on `not_found`). Payment confirmation, fee waiver, access
grant, legal advice and contract mutation are never licensable.

Direct argument ids are licensed by a prior `ToolResult::entities` entry, an
explicit user statement, or conversation context. Transitive: `unit_class_rate_id`
is licensed iff it resolves to a licensed `unit_class`. Untyped morph aliases
are schema (`invalid_arguments`), not provenance.

### Forbidden claims

Pattern set over the draft (shared `config/ai-handoff.php` plus per-agent
`forbiddenClaims()`): payment confirmation, fee waiver, access grant,
availability guarantee ("I've held it"), capacity guidance ("should work
well" / "will fit"), legal advice, contract mutation.
Match → block + `unsupported_intent`.

`availability_guarantee` and `capacity_guidance` are **conditional** — licensed
only by the tool that earned them this turn (invariant 63). Mechanism and
scope: Licensing above. Unlicensed capacity claims are suppressed, not
rewritten. When licensed, the response cites the band and carries the
guide's disclaimer. "Should work well" is fine; "will fit" is not.

### Disclosure

Leak check: below `verified`, a draft that contains an amount, unit
identifier, contract date, or address is blocked. AI disclosure (EU AI Act
Art. 50): the first assistant turn of a customer-facing conversation must
state that it is automated; if missing, the configured line is **appended**,
not blocked.

### Channel

SMS only: `Gsm7Transliterator` rewrites the body **before** segment counting
(`²`/`³` → `2`/`3`, `€` → `EUR`, dashes/quotes/ellipsis/NBSP). Characters
already in GSM-7 (Spanish accented vowels, `ñ`, `¿`, `¡`, `º`/`ª`) pass
through. Never applied to email or WhatsApp — rendering `€` as `EUR` in an
email is a downgrade with no compensating benefit. Operator-authored SMS
templates are unchanged (`06-communications.md`).

Config `agents.channel.sms`: `warn_segments` (default 3), `max_segments`
(default 5), `max_redraft_attempts` (default 2). At or above warn: verdict
`warn`, send proceeds. Above max: verdict `deny`, reason `sms_too_long`; the
draft returns to the model to shorten, then escalates. Segment counting is
the same counter `SmsSender` uses for the inbox composer — do not fork it.

`detail.gsm7_transliterated` is set only when the body actually changed. The
pre-transliteration body is `originalBody` on the **client session only**
(D-AI-15). It is not a column, not on `agent_handoffs.detail`, and disappears
on reload. Transliteration does not reverse.

Email without a `Subject:` line is **filled in** from the first body line (or
`Your enquiry`), not blocked. Lines before `Subject:` are discarded so the
body is the sendable email, not model narration. HTML in a plain-text channel
is stripped. The demo email skin renders Markdown. WhatsApp session-vs-template
is advisory until a real thread supplies `last_inbound_at`.

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
the single operator surface. Registry: Agent registry above. No
`HasAutomationTriggers` on any agent table.

| Model | Table | Role |
|---|---|---|
| `AiAgent` | `ai_agents` | Instance, not definition. `key` must resolve to an `AgentDefinition`. `settings` = tuning knobs only — never the prompt or tool list |
| `AgentConversation` | `agent_conversations` | One conversation. `audience` (`internal` \| `customer`), `origin` (`demo` \| `inbox` \| `webchat`, **never null**), `channel`, principal facts, `state` (`active` \| `awaiting_human` \| `handed_off` \| `closed`) |
| `AgentConversationMessage` | `agent_conversation_messages` | Append-only turns (`sequence`). `blocked_by` when a draft was suppressed. `emitted_message_id` always null this sprint |
| `AgentToolInvocation` | `agent_tool_invocations` | What it looked at: `tool_key`, arguments, result, `denied_reason`, verification snapshots, `idempotency_key`, `result_type` / `result_id` on committed writes |
| `AgentPendingAction` | `agent_pending_actions` | Propose-mode intent: server-normalised `payload`, operator `preview`, `status`, `expires_at`. Approval re-validates; never a result snapshot |
| `AgentHandoff` | `agent_handoffs` | Escalation: `reason`, `trigger_source` (`rule` \| `model` \| `customer` \| `guardrail`), `detail` |
| `AgentWritePolicy` | `agent_write_policies` | Per-agent, per-tool autonomy: `mode`, quotas, raise-only `min_verification`. Absent row = `commit` unlimited |
| `AgentGuardrailEvent` | `agent_guardrail_events` | One verdict per guard per message. Consumers key by `message_id`, never array order |

CHECK constraints bind the shape in the database: internal audience requires
`employee_id` and forbids `contact_id`; customer audience forbids
`employee_id`; `verified` requires a contact; `origin = demo` requires
`created_by_employee_id`.

`origin` is never null (D-AI-7, invariant 59). Demo traffic is excluded by an
**explicit filter at each call site** — never a global scope (invariant 46).
Demo conversations must not reach Insights, eval corpora, or any
operator-facing metric.

### Envelope

`TraceAssembler` exports every row with:

| Field | Notes |
|---|---|
| `conversation_id` | |
| `turn` | Monotonic within the conversation |
| `seq` | Canonical sort key. Consumers sort by `seq`, never by `turn` or array order |
| `message_id` | Nullable — null for rows that precede a message. Guardrail rows always resolve to the message they judged |
| `model` | The model that produced the turn |
| `prompt_version` | Hash of the **template**, not the interpolated prompt (D-AI-14). `identityBlock` (company, site, timezone, today's date) is omitted so the field is not a per-site, per-day fingerprint. Channel and verification stay in the hash. Eval cassettes hash the fully interpolated prompt — they share `CassetteKey::promptHash`, not the input |
| `occurred_at` | |

The model receives `display` / `summary` only; `data` and `entities` persist
on the invocation. `originalBody` is client-session only (D-AI-15) and is not
an envelope field.

## Telemetry

`ai_usage_events` is operational telemetry, not the ledger (invariant 48).
Customer-facing turns stamp `ai_agent_id` + `agent_conversation_id` and leave
`employee_id` null (`CHECK (employee_id IS NOT NULL OR ai_agent_id IS NOT NULL)`).
Reserve before the provider call, settle after with real token counts.
Estimated cost is derived at **read time** from `ai_model_prices` — never
stored, never an in-place rate update, never a `NUMERIC(10,2)` money column,
never reconciled to the provider invoice, never summed across currencies
(invariants 48 / 30). The figure attributes spend between employees **or
agents**; it is not an accounting record.

`agents:check-model-prices` fails when any recently used or configured model
key has no `ai_model_prices` row effective for that period. Wire it into
scheduled checks so a model swap cannot silently null out cost.

Usage rows record `cached_input_tokens` where the provider reports them.

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

## Voice (Vocal Bridge)

Employee copilot only. Customer-facing agents (`AgentConversation`) are a
non-goal. The panel owns the Vocal Bridge session; VB handles mic, STT, turn
detection, TTS and barge-in. Copilot stays on `CopilotDispatcher` / the `ai`
queue / `private-copilot.{id}` Reverb. Tool approvals stay click-only
(invariant 60).

```
mic → VB (STT + endpointing)
    → vb.onAIAgentQuery(query)
    → POST /api/copilot/conversations/{id}/messages  { source: voice }  → 202
    → Reverb: stream_start → text_delta… → stream_end
    → resolve(promise) with accumulated text, or sendAction('copilot_answer_ready')
    → VB speaks it (adaptive mode)
```

`source` is accepted on both `POST …/messages` and `POST …/decisions`. The
post-approval continuation is a separate generation; without `source: voice`
the resumed half comes back as markdown and gets spoken. `CrmCopilotAgent`
takes `voice: bool` and concatenates a spoken-form block onto the existing
instructions. The panel does not post-process the text.

`AgentChannel::Voice` + `ChannelProfile` exist so `ChannelProfileTest` stays
exhaustive. Copilot does **not** read `ChannelProfile`. `POST /api/agent-conversations`
rejects `channel=voice` (422) — customer-facing voice is not this sprint.

### Turn lifecycle: `settle` vs `deliver`

`app/utils/copilotTurnBus.ts` is a module-level emitter. The copilot store
keeps a **separate `voiceBuffer`**, reset on every `stream_start`.
`ensureStreamingAssistant()` reuses the trailing assistant message, so a
post-approval continuation appends into the same `TextPart`. Reading the
message would re-speak the pre-approval half. The buffer resets per stream,
so the push after approval carries only the continuation.

- **`settle`** — resolve the `onAIAgentQuery` promise once (answer or hang-guard filler).
- **`deliver`** — the real answer. Resolves if we have not spoken yet; otherwise
  `sendAction('copilot_answer_ready', { text })`. A turn is spoken exactly once
  via `resolve` and at most once more via `sendAction`, never both with the
  same text.

`ANSWER_DEADLINE_MS = 25_000` is a **hang-guard, not a UX filler**. VB
AI-agent mode already fills while waiting on our query. The deadline exists
so the promise settles if Reverb drops, against `CrmCopilotAgent`
`#[Timeout(120)]`. Abandoned approvals clear after 5 minutes.

Switching `activeConversationId` mid-turn (the list stays reachable — D-V3)
delivers `copilot.voice.interrupted` and clears the turn. Closing the
slideover does not drop Reverb: `useCopilotStream` lives on `default.vue`.

Auth is `tokenProvider` (verified `@vocalbridgeai/sdk@0.1.1` `.d.ts`), so a
later duration bump can re-mint. The API key stays server-side
(`POST /api/copilot/voice/token`, permission `copilot_voice.use`).

## Known gaps

These are defects with a home, not open questions.

1. **Conversation redaction (AR-03).** `agent_conversation_messages`,
   `agent_tool_invocations`, and `agent_handoffs.detail` hold names, emails,
   and balances. `config/redaction.php` covers `activity_log` and
   `system_events` only. `contacts:redact` does not touch the agent tables.
   `ai_summaries` **is** already covered (invariant 53). Transcripts are
   evidence in a lien or auction dispute — they retain on contract terms, not
   on the telemetry pruning schedule that covers tier-1 system events.
   Blocking before any live channel connects. Detail: `10-open-decisions.md`.
2. **Two authorization systems.** `agent_write_policies` governs what agents
   may write; `roles` / `role_permissions` / `employee_roles` governs what
   people may write. They will drift. Whether an agent should hold a real
   grant with a real site scope (write policy reduced to mode and quota) is
   Undecided in `10` — recorded so it is settled deliberately rather than by
   accretion.

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

- **Sending** an offer. Creation is not delivery (D-AI-10). No `SendContext`,
  no `OfferDelivery` row, no `messages` row from the agent.
- Support-agent write tools for Offer / Reservation. Sales only.
- Any transport — no `SendContext`, no sender call, no `messages` row.
- `webchat` as a comms `Channel` enum value and its adapter (agent
  `AgentChannel::Webchat` is a profile only).
- RAG / vector retrieval (D-AI-5). Knowledge is curated key lookup.
- `contact_verifications` / OTP — the verification level is a demo toggle.
- Autonomy beyond `suggest` for *sending* (`review` / `auto` are later, gated
  on measured containment, never on a date).
- Per-agent, per-channel autonomy configuration.
- Copilot on this shared runtime.
- Insights reports on agent performance (tables are shaped for them; harvest
  principle applies).
- Delinquency autonomy — never, in any sprint.

## Related docs

- `02-facility.md` — `site_service_areas`, site coordinates, `size_guides`
- `03-pricing.md` — quote-to-offer `price_id` continuity (invariant 66)
- `04-crm-pipeline.md` — `source` / `ai_agent_id` on Offer and Reservation
- `06-communications.md` — inbound sender matching; GSM-7 / SMS segment
  ceiling apply to agent drafts only
- `07-people-and-auth.md` — employee RBAC is a different axis from agent
  authorization (D-AI-2)
- `09-conventions-and-invariants.md` — invariants 54a/54b, 55–66, amended 48
- `12-automation-engine.md` — `CreateObjectAllowlist` (unchanged; agent surface is deliberately wider)
- `10-open-decisions.md` — D-AI-1…15, D-V1…4; AR-03 is a known compliance
  defect (Blocking for S23), not an open question
- `08-activity-logging.md` — tier-2 `ai` channel; tier-3 copilot voice sessions; activity log is not a transcript
- `01-stack.md` — stack line and `/demo/chat` page map

