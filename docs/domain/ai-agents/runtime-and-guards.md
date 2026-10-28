# Runtime and guards

Part of the customer-facing agent docs. Map: [README](README.md).


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
| Asserted | `channel_asserted` | Nothing extra — reserved for lead-shaped writes (`sales.create_reservation`, `identity.request_code` / `identity.verify_code`). A matched inbound address lands here; it is not proof. |
| Private | `verified` | Anything tenant-specific: balance, invoices, contract, access, unit identifier |

A unit number is tenant-specific. So is a move-out date. Err upward.

**`verified` is earned inside the conversation (invariant 72, D-AI-23).** A
principal reaches it only by consuming a `contact_verifications` challenge
delivered to a `contact_channels` row that already belonged to the contact:

- The destination is resolved server-side. The model may pass an optional
  channel *type* (`email` \| `sms`); it never supplies an address or number.
  Accepting a destination from the conversation would turn the flow into
  attacker-nominated delivery.
- The plaintext code exists only in the outbound message. The row stores
  `code_hash` (sha256). Delivery goes through `SmsSender` / `EmailSender`
  with `SendContext(class: transactional)` (invariant 38 / 69). A suppressed
  address returns a machine error + recovery affordance; no send is
  attempted.
- Config (`agents.verification.*`): TTL 10 minutes, 6 digits, 5 attempts,
  3 issues per contact per hour. "Open" is a read-time predicate — not
  consumed, not expired, attempts remaining (invariant 13). Single-open-
  challenge is enforced under a row lock in `VerificationChallenge::issue()`,
  not by a partial unique on `now()`.
- A new conversation with the same contact starts at `channel_asserted`
  again. Caller ID, a self-stated address matching an existing contact, and
  a prior verified conversation all stop at `channel_asserted`.
- `origin = demo` is the remaining writer of `verified` without a challenge.
  Demo traffic is excluded from every metric (invariant 59).

**Employee RBAC does not authorize agent tools (D-AI-2).** RBAC answers "which
sites may this staff member see" (`../07-people-and-auth.md`). Agent
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
├── ContextWindow.php             // bound the message list sent to the driver
├── AgentSend.php                 // sole path from draft to a real send
├── AgentChannelBindings.php      // resolve (channel, site) → binding or null
├── AgentPrincipal.php
├── AgentContext.php              // principal + channel + definition + conversation
├── AgentTurn.php
├── ChannelProfile.php
├── Agents/                       // AgentDefinition, registry, concierge + retained sales / support
├── Identity/                     // VerificationChallenge, destination, masked display
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
that covers one caller is not a switch. Inbox-origin turns additionally
refuse when `AgentChannelBindings::resolve` returns null, `mode = off`, or
a different agent (defence in depth, invariant 68).

1. Rebuild / assert the principal against the conversation facts.
2. **Inbound guards** (`InboundGuardPipeline`): `LoopGuard` → `BudgetGuard` →
   `HandoffRules`. A match short-circuits: no model call, write
   `agent_handoffs`, set state `awaiting_human`, return a canned line.
3. Persist the `user` message row.
4. **`ContextWindow::build()`** immediately before `ModelDriver::stream()`
   (`AgentRuntime::streamMetered`). Persistence is unchanged — the table
   holds the full history; only the bounded list is sent. `build()` is
   idempotent so a guard-redraft can call the driver again on an
   already-trimmed list. Persistence is **not** held open across the driver
   call.
5. Tool loop (capped by `config('agents.max_tool_calls_per_turn')`, default 10). Each call
   goes through `ToolDispatcher` (below), persists `agent_tool_invocations`,
   merges into the turn `FactBag`. Exhausting the cap does **not** hand off:
   dropped `tool_use` blocks (the slice past the remaining budget) get a
   synthetic `unavailable` tool message (`detail.skipped = true`, no
   `agent_tool_invocations` row) so every assistant `tool_call_id` has a
   matching result, then one tools-disabled model call writes the reply from
   the results already in hand. Mid-turn prose on the capped response is
   discarded — it is a preamble, never the delivered draft. Handoff with
   `detail: max_tool_calls_per_turn` only if that close call comes back empty. **Retry before escalate:** a `ToolError`
   whose `error_code` is in `{invalid_arguments, not_found, site_unresolved,
   unlicensed_argument, price_superseded}` is fed back as `display` plus a
   `Recovery:` line (`recovery.hint` / `recovery.tool`). The runtime tracks
   `consecutiveFailures[tool_key]` within the turn and only converts to a
   handoff (`reason: error`, `trigger_source = rule`) when the same tool has
   failed `config('agents.max_tool_retries')` (default 2) times. A model
   `agent.escalate(reason: error)` while that budget remains is answered
   with `ToolError(invalid_arguments)` and is **not** persisted as a handoff.
   Byte-identical arguments after `invalid_arguments` do not re-dispatch: the
   runtime feeds a stronger `Recovery:` line once ("you repeated the same
   arguments; change them or escalate"), persists the skipped attempt
   (`detail.skipped = true`), and allows one more model call. A further
   identical call, or any retryable failure of that tool after the warning,
   hands off with `detail: identical_retry`.
   **Principal promotion (D-AI-18, D-AI-23):** after an `ok` tool result in a
   customer-audience conversation, `PrincipalPromotion` may stamp the
   conversation and rebuild the principal for the remainder of the turn.
   Two paths, both upward only:
   - `crm.create_contact` (or its dedupe path) from `anonymous` →
     `channel_asserted`. Never `verified` from a self-stated identity.
   - `identity.verify_code` from `channel_asserted` → `verified`. The
     conversation's `contact_id` must already equal the verified contact.
   Each successful promotion writes `agent.conversation.principal_promoted`
   on the `ai` channel **and** an append-only `agent_principal_promotions`
   row (trace kind `promotion`, `{from, to, method}`:
   `contact_created` \| `otp`). No SSE event — the row arrives through
   `hydrate()` after the turn.
6. Final assistant draft → **outbound guards**. A block writes the message
   with `blocked_by` set and converts the turn to a handoff. The customer
   never sees a blocked draft.
7. Persist the assistant message, write agent-attributed `ai_usage_events`
   (reserve then settle), update `last_turn_at`. Inbox origin then dispatches
   `channel.send` as a runtime-only terminal step (`maybeSendOnChannel`):
   binding `draft` → `propose()` + pending action; binding `auto` →
   `handle()` via `AgentSend`.
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

Channel shapes the draft even before a send. `ChannelProfile::for()`
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

WhatsApp `requiresTemplateOutsideWindow` is advisory on demo / eval turns.
On a live inbox turn the window is **enforced**: `last_inbound_at` is read
from `$conversation->messageThread`; a closed window refuses the send and
hands off `channel_constraint` so an operator can send a template.

### `ToolResult`, identity echo, and `FactBag`

Tools never return a raw number for the model to format. `display` is
pre-rendered prose the model may quote (`BillingMath` / `MoneyDisplay` for
money; exclusive tax, currency from the price row — D1, invariant 30).
`ToolResult::modelText()` feeds the model `display` plus a deterministic
`Refs:` line from `entities` (invariant 67). `result_summary` and persisted
message content stay `display`. Structured `data` and `entities` persist on
the invocation row and stay out of model context (invariant 65). An id the
model can pass but was never shown on a `Refs:` line is a defect in the
tool.

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
share `CassetteKey::promptHash`, not the input. `identityBlock` always
emits `Today: {ISO} ({weekday})` — `SiteClock::today($site)` when a site is
set, otherwise `config('app.timezone')` — so a conversation with `site:
none` still knows the civil date. Relative-date conversion is
`calendar.resolve`, not a prompt instruction. `display` does **not**
participate — it is instance-specific. A task that changes a prompt-visible
`display` string must re-record affected cassettes
(`agent:replay --live --record`).


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
| `DuplicateDraftGuard` | yes | Block + handoff. Ignores `voice_source` assistant rows (mirrored front desk). Near-duplicate is an exact folded match, or both the first and last 255 characters within 10% Levenshtein (PHP's cap) — a shared opening with a different tail is not a loop |
| `GroundingGuard` | yes | Single unlicensed date or money token: retry with the redraft budget, then block + handoff. Two or more tokens, or an identifier or percent: block + handoff immediately (invariant 55 — a suppressed draft is never delivered) |
| `ForbiddenClaimGuard` | yes | Licensable keys (`availability_guarantee`, `capacity_guidance`): retry with a licensed alternative, then block + handoff if the redraft budget is exhausted. Other keys: block + handoff. Two keys are licensable (invariant 63) |
| `DisclosureGuard` | leak: yes; AI Act line: fill-in | First customer turn is **prompted** to open with the configured sentence; if missing, the line is **prepended**, not blocked |
| `ChannelGuard` | SMS ceiling: yes; warn band: no; WhatsApp window: advisory | Email missing `Subject:` is filled in, not blocked. Voice markdown emphasis (`**5 m²**`) is stripped, not blocked |

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
| `move_out_commitment` | `unsupported_intent` | give notice / vacate / move out / terminate — collect intent, never commit a date. Bare "notice" / "preaviso" / "préavis" stay off the list so a notice-period FAQ does not escalate |
| `payment_dispute` | `unsupported_intent` | "I already paid", "charged twice", refund |
| `customer_requested` | `customer_requested` | human / agent / manager / "real person" |
| `third_party` | `legal_or_complaint` | asking about another tenant |

**No autonomy inside delinquency, ever, in any sprint.** An agent chasing or
discussing debt in ES/UK touches collections law. It is a hard escalation.

### Grounding

Highest-value outbound guard. Extracts currency amounts, civil dates
(`2026-08-17`, `17/08/2026`, `14 September 2026`, `14 de septiembre de 2026`,
`le 14 septembre 2026`), unit-shaped identifiers, and percents from the
draft; every token must be in the licensed `FactBag` (this turn's tools plus
earlier unblocked assistant facts, plus numbers the customer themselves
supplied). Written-month forms normalize to ISO — licensing `2026-09-14`
permits those spoken strings; licensing today's `as_of` does not permit
another day, and a bare year is never a date. Relative-day words (`today` /
`tomorrow`, and es/fr equivalents) are not dates. Invented `21%` VAT is the
exact failure this exists for. A single unlicensed date or money amount is a
`retry` (drop the value or ask the customer) bounded by `max_redraft_attempts`;
two or more tokens, or an identifier or percent, still block and hand off
immediately.

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
Match → block + `unsupported_intent` for unlicensable keys. For the two
licensable keys (`availability_guarantee`, `capacity_guidance`) an unlicensed
match is a bounded **redraft** (`GuardrailVerdict::retry`) quoting a licensed
alternative; exhausting the redraft budget falls through to block + handoff.

`availability_guarantee` and `capacity_guidance` are **conditional** — licensed
only by the tool that earned them this turn (invariant 63). Mechanism and
scope: Licensing above. Unlicensed capacity claims are redrafted, not
rewritten in place. When licensed, the response cites the band and carries the
guide's disclaimer. "Should work well" is fine; "will fit" is not.

### Disclosure

Leak check: below `verified`, a draft that contains an amount, unit
identifier, contract date, or address is blocked. AI disclosure (EU AI Act
Art. 50): the first assistant turn of a customer-facing conversation must
open by stating that it is an automated assistant for the company. The
system prompt names the configured sentence verbatim on that turn. If the
model omits it, `DisclosureGuard` **prepends** the line (safety net), and
the guard event records `prompted` / `appended`. On voice the Vocal Bridge
foreground agent speaks first, so the spoken line is
`ai-handoff.voice_greeting` in the **site default locale** (`SiteLocale`
from the site the number is bound to — not inferred from the caller).
That spoken greeting is the Art. 50 line on voice. `DisclosureGuard` does
not prepend (and the system prompt does not instruct) the chat disclosure
on the first delegated reply — Deepgram already said it.
`voice_greeting` is a separate key from `disclosure` on purpose: voice will
take a recording clause later, and the spoken line has a different legal
sign-off owner than chat.

### Channel

Voice only: markdown emphasis (`**bold**`, `*italic*`, `__bold__`,
backticks) is stripped before the draft is returned to keevaris-voice.
`strip_tags` does not catch those markers, and Deepgram would otherwise
speak the asterisks. WhatsApp keeps `*bold*` — that is native formatting
there, not TTS. Email and internal keep markup.

keevaris-voice piggybacks unsent caller / front-desk turns on each
delegation as `context_segments`. `VoiceTranscriptMirror` appends those
rows onto `agent_conversation_messages` before `AgentRuntime::turn`:
caller → `role=user`, `voice_source=stt`; front desk → `role=assistant`,
`voice_source=fast_model`, content prefixed `[front desk] `, `fact_keys`
left null so `GroundingGuard` never licenses a figure the fast model
invented. `voice_sessions.mirrored_transcript_sequence` is the watermark
— a repeated batch inserts nothing twice. `LoopGuard` excludes
`voice_source` assistant rows from `max_turns` and from the consecutive-
assistant count; they are context, not billed turns. The voice
`ChannelProfile` addendum tells the model the call is already under way
(never re-greet) and to treat `[front desk]` turns as unverified.

SMS only: `Gsm7Transliterator` rewrites the body **before** segment counting
(`²`/`³` → `2`/`3`, `€` → `EUR`, dashes/quotes/ellipsis/NBSP). Characters
already in GSM-7 (Spanish accented vowels, `ñ`, `¿`, `¡`, `º`/`ª`) pass
through. Never applied to email or WhatsApp — rendering `€` as `EUR` in an
email is a downgrade with no compensating benefit. Operator-authored SMS
templates are unchanged (`../06-communications.md`).

Config `agents.channel.sms`: `warn_segments` (default 3), `max_segments`
(default 5), `max_redraft_attempts` (default 2). At or above warn: verdict
`warn`, send proceeds. Above max: verdict `deny`, reason `sms_too_long`; the
draft returns to the model to shorten, then escalates. Segment counting is
the same counter `SmsSender` uses for the inbox composer — do not fork it.

`detail.gsm7_transliterated` is set only when the body actually changed. The
pre-transliteration body is `originalBody` on the **client session only**
(D-AI-15). It is not a column, not on `agent_handoffs.detail`, and disappears
on reload. Transliteration does not reverse.

Email without a `Subject:` line is **filled in** from the first sentence of
the body (or `Your enquiry`), not blocked. If that sentence is longer than
70 characters, cut at the last clause boundary (`,` `;` `:` `—` `–`) before
70; if none, cut at the last word boundary and append `…`. Trailing
punctuation and locale stopwords (`config/ai-handoff.php`
`subject_stopwords`) are dropped so the subject never ends mid-clause. An
explicit `Subject:` line is honoured and stripped from the body; the prompt
asks the model to open with one of at most 70 characters. Lines before
`Subject:` are discarded so the body is the sendable email, not model
narration. HTML in a plain-text channel is stripped. The demo email skin
renders Markdown. WhatsApp session-vs-template is advisory on demo / eval;
on a live inbox turn the window is enforced (see Live channels).

### Prompt injection

Customer text and tool results are **data**. The system prompt says so; the
real defence is the tool surface. There is no tool that applies a discount —
only one that lists catalogue ids. `sales.propose_offer` cannot exceed
catalogue terms. Ownership is checked before `handle()`. No guard tries to
*detect* injection by pattern.


## Trace tables

Agent conversations are a **reasoning trace, not the message store** (D-AI-3,
invariant 57). Invariant 38 is unchanged: a `messages` row means exactly one
real send or receipt. Agent drafts are never `messages` rows. On inbox origin,
`message_thread_id` links the trace to the canonical thread and
`emitted_message_id` is set by `AgentSend` after a real send. Demo origin
leaves both null. The Inbox stays the single operator surface. Registry:
Agent registry above. No `HasAutomationTriggers` on any agent table.

| Model | Table | Role |
|---|---|---|
| `AiAgent` | `ai_agents` | Instance, not definition. `key` must resolve to an `AgentDefinition`. `settings` = tuning knobs only — never the prompt or tool list |
| `AgentConversation` | `agent_conversations` | One conversation. `audience` (`internal` \| `customer`), `origin` (`demo` \| `inbox` \| `webchat`, **never null**), `channel`, principal facts, `state` (`active` \| `awaiting_human` \| `handed_off` \| `closed`), `message_thread_id` (inbox), `agent_handback_at` |
| `AgentConversationMessage` | `agent_conversation_messages` | Append-only turns (`sequence`). `blocked_by` when a draft was suppressed. `subject_message_id` on the inbound that drove the turn; `emitted_message_id` set by `AgentSend` after a real send |
| `AgentChannelBinding` | `agent_channel_bindings` | Per `(channel, site)` which agent answers, at what `mode` / `audience` / `outside_hours`. Absent row = off (invariant 68) |
| `AgentToolInvocation` | `agent_tool_invocations` | What it looked at: `tool_key`, arguments, result, `denied_reason`, verification snapshots, `idempotency_key`, `result_type` / `result_id` on committed writes |
| `AgentPendingAction` | `agent_pending_actions` | Propose-mode intent: server-normalised `payload`, operator `preview`, `status`, `expires_at`. Approval re-validates; never a result snapshot |
| `AgentHandoff` | `agent_handoffs` | Escalation: `reason`, `trigger_source` (`rule` \| `model` \| `customer` \| `guardrail`), `detail` |
| `AgentWritePolicy` | `agent_write_policies` | Per-agent, per-tool autonomy: `mode`, quotas, raise-only `min_verification`. Absent row = `commit` unlimited |
| `AgentGuardrailEvent` | `agent_guardrail_events` | One verdict per guard per message. Consumers key by `message_id`, never array order |
| `AgentPrincipalPromotion` | `agent_principal_promotions` | Mid-conversation principal upgrade. `from_level` / `to_level` / `method` (`contact_created` \| `otp`). Append-only; assembled as trace kind `promotion`. No SSE event — arrives through `hydrate()` after the turn |
| `ContactVerification` | `contact_verifications` | Conversation-scoped OTP challenge. Code hashed at rest; destination is a `contact_channels` id. In AR-03 redaction scope |

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

Kinds: `tool`, `guardrail`, `usage`, `handoff`, `promotion`. A `promotion`
row carries `{from, to, method}` and sorts after the invocation that caused
it (`seq = TraceSeq::max() + 1`).

The model receives `modelText()` (`display` plus the `Refs:` line,
invariant 67); `data` and `entities` persist on the invocation.
`originalBody` is client-session only (D-AI-15) and is not an envelope
field.

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
  For `inbox` it is derived from how the message arrived (`channel_asserted`).
  `webchat` derivation is S26-07c.
- `audience` is derived from whether a `contact_id` is present, never trusted
  from the client.
- Permission: `Permission::AiAgentUse`. Nav visible when the flag is on and
  the employee holds the permission.
- `GET /api/ai/demo-personas` is the one genuinely demo-shaped endpoint
  (seeded contacts with contract / balance / delinquency flags).

Three inputs a live channel would supply are selected by the operator instead
of being faked: agent (the live `concierge` instance; archived `sales` /
`support` rows do not appear), channel (`email` / `sms` / `whatsapp` /
`webchat`), principal + verification (persona + level toggle). The
verification select is a demo affordance with no production equivalent — it
is the only remaining writer of `verified` outside a real challenge
(invariant 59 / 72). The right pane is the trace — tools, guardrail
pass/block, usage, and `kind: promotion` rows.

API (all `auth:sanctum`, no `/api/demo/*` prefix): `GET /api/ai/agents`,
`POST/GET /api/agent-conversations`, `GET …/{id}`, `POST …/{id}/turns` (SSE),
`POST …/{id}/close`.

Eval: `php artisan agent:replay` replays YAML fixtures under
`tests/Fixtures/agents/` against `CassetteDriver`. Default mode is CI-safe.

