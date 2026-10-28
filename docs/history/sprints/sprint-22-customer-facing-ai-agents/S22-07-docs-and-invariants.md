# S22-07 — Docs, invariants, open decisions

**Repo:** `unit-hq-api` (`docs/`)
**Depends on:** all S22 tasks
**Merge:** last, in the same PR train — not a follow-up ticket

## New doc: `docs/14-ai-agents.md`

Sections, in this order:

1. **What an agent is** — the three-way split: copilot (internal, employee
   principal, screen output) vs support/sales agents (customer-facing, contact
   principal, message output). State the reframe explicitly: inbound sender
   matching was designed as a **routing** decision; an agent turns it into an
   **authorization** decision, and that promotion is the reason
   `VerificationLevel` exists.
2. **Principal and verification** — `AgentPrincipal`, the three levels, the tier
   table from `S22-02`, why employee RBAC does not apply (D-AI-2).
3. **Runtime** — `App\Support\Ai\` layout, the turn loop, the four dispatch
   gates, `ModelDriver`.
4. **Tool catalogue** — the full table from `S22-02` with verification levels
   and which agent holds what. Keep this current; it is the doc people will
   actually read.
5. **Guardrails and handoff** — pipeline order, rule table, grounding.
6. **Trace tables** — the schema, and the D-AI-3 statement that these are a
   trace, not the message store.
7. **Telemetry** — `ai_usage_events` with agent attribution, cost is an estimate
   and never an accounting record.
8. **Demo surface** — `/demo/chat`, `origin = demo`, the `ai.demo_enabled` flag.
9. **What is deliberately not built** — the never-list, no RAG, no transport, no
   autonomy beyond `suggest`, no delinquency autonomy ever.

Cross-link `06-communications.md` (the S23 seam), `07-people-and-auth.md`
(why RBAC is a different axis), `09` (the new invariants), `12-automation-engine.md`
(the `CreateObjectAllowlist` parallel).

## `docs/AGENTS.md`

Add a row to the routing table:

```
| AI agents / copilot / tools / guardrails | `14-ai-agents.md` |
```

Add to the non-negotiables summary:

```
- Customer-facing agents never write to the ledger, mutate contracts, grant
  access, or confirm payment. No money, date, or unit identifier in agent output
  originates from the model.
```

## `docs/09-conventions-and-invariants.md` — new invariants 53–58

Match the existing voice: numbered, bolded lead sentence, the reasoning
underneath, and the name of the test that enforces it.

**53. A customer-facing agent never writes to the ledger, mutates a contract,
grants access, issues an invoice, or confirms a payment.** Not with
confirmation, not with an operator in the loop, not behind a flag — those are
operator actions reached through operator surfaces. Permitted agent writes are
exactly the automation allowlist: `Contact`, `Deal`, `Task`, `Note`.
Contract / Reservation / Offer creation is a transactional path
(`ContractBilling`, offer acceptance), not a field map — the same reasoning that
excluded them from `CreateObjectAllowlist`. Payment confirmation remains
rail-specific (invariant 11); an agent stating that a payment cleared is a
defect regardless of what the ledger says. Enforced by `AgentToolWriteGuardTest`.

**54. No money, date, or unit identifier in agent output originates from the
model.** Every figure comes from a `ToolResult` and is rendered by the tool
through `BillingMath`; the model quotes the rendered string. `GroundingGuard`
diffs the draft against the turn's `FactBag` and suppresses anything untraced.
With exclusive tax and per-row currency (D1, invariant 30), a model performing
its own arithmetic is a guaranteed defect, not a risk. A suppressed draft
becomes a handoff and is never delivered.

**55. `AgentPrincipal` is explicit and re-validated at every tool call site.**
It is passed as an argument, never resolved from the container, the request, a
static, or a job payload inside a tool, and never cached as an authorization
scope on a model. `agent_conversations` stores the facts (`contact_id`,
`verification_level`); the principal is rebuilt from them per turn. This is the
subject of the request, not an ambient scope — the discipline invariant 46
protects, applied to a different axis (D-AI-1). Authorization gates run *before*
`AgentTool::handle()` is reached; a tool that only checks internally is a defect.

**56. Agent conversations are a reasoning trace, not the message store.**
Invariant 38 is unchanged: a `messages` row means exactly one real send or
receipt. Agent drafts are never `messages` rows. When channels land, the trace
links to the canonical thread via `agent_conversations.message_thread_id`; the
thread stays the truth and the Inbox stays the single surface.

**57. Agent definitions, tools, prompts, and permissions are code;
`ai_agents` rows are instances.** An `ai_agents.key` with no `AgentDefinition`,
or a definition claiming an unregistered tool key, is a defect, not data —
`AgentDefinitionCoverageTest` / `AgentToolCoverageTest`. Same grep-as-test
discipline as invariant 43. `ai_agents.settings` holds tuning knobs only, never
the prompt or the tool list.

**58. `agent_conversations.origin` is never null, and demo traffic is excluded
by an explicit filter at each call site.** Never a global scope (invariant 46).
Demo conversations must not reach Insights, eval corpora, or any operator-facing
metric.

Also amend **invariant 48**: "attributes spend between employees" →
"attributes spend between employees **or agents**". Everything else about 48
stands — telemetry not ledger, mutable status, no `NUMERIC(10,2)` path, read-time
cost from `ai_model_prices`, never summed across currencies.

## `docs/10-open-decisions.md`

### Add to "Decided (do not reopen)"

D-AI-1 through D-AI-7 from the sprint README, in the existing voice. In
particular D-AI-1 needs to be written out fully, because it is the one a
reviewer will otherwise flag as an invariant-46 violation on every future PR.

### Add to "Undecided"

| Topic | Note |
|---|---|
| Autonomy beyond `suggest` | `review` (delayed auto-send with a cancel window) and `auto` (allowlisted intents only) are S23+. Gate `auto` on measured containment, never on a date. |
| Agent → `awaiting_signature` | S14's e-sign path makes offer → accept → envelope reachable, and S14-00's no-pre-signature-deposit rule means a sales agent could reach signature but never money. Decide before tool boundaries harden. |
| Escalation SLA ownership | An agent that hands off at 02:00 into an unwatched queue produces excellent containment metrics and a worse experience than the autoresponder it replaced. Product decision, not engineering. |
| Agent performance Insights | Containment rate, handoff mix by reason, operator edit distance in `suggest` mode, first-response time, agent-sourced reservations, cost per conversation per currency. Tables are shaped for it; harvest principle applies (live bounded queries, no rollups). |
| Copilot onto the shared runtime | Deliberately not done in S22. Separate, testable migration once the customer-facing path proves itself. |

### Amend AR-03 (agent conversation redaction)

Currently logged as a known gap. Change its status from deferred to
**blocking for S23**:

> **AR-03 — blocking before any channel connects.** `contacts:redact` must cover
> `agent_conversation_messages`, `agent_tool_invocations`, and
> `agent_handoffs.detail`. S22 traffic is `origin = demo` against `demo:seed`
> fiction, so the gap is tolerable for one sprint. The moment a real inbound
> message reaches an agent, these tables hold verbatim tenant text, balances,
> and addresses. Extend `config/redaction.php` before, not after.

Also record the retention distinction: agent transcripts are evidence in a lien
or auction dispute. They retain on contract terms, **not** on the telemetry
pruning schedule that covers tier-1 system events.

### Add to "Explicitly out of scope (for now)"

- Any agent transport — no `SendContext`, no sender call, no `messages` row.
- `webchat` as a `Channel` enum value and its adapter.
- RAG / vector retrieval for agent knowledge (D-AI-5).
- `contact_verifications` / OTP — the verification level is a demo toggle in S22.
- Per-agent, per-channel autonomy configuration.

## `docs/01-stack.md`

Update the AI line:

> **AI:** internal Copilot plus customer-facing agents (support / sales) on a
> shared tool-and-guardrail runtime in `App\Support\Ai\`. Conversations and
> traces stored in DB. Demo surface at panel `/demo/chat`, gated by
> `ai.demo_enabled`.

Add to the panel page map:

> - **Demo** — `/demo/chat`: agent console (agent / channel / persona /
>   verification pickers, channel-skinned conversation, tool-and-guardrail trace).
>   Flag-gated; not an operator surface.

## `docs/08-activity-logging.md`

Document the new `ai` tier-2 channel and its three events
(`agent.conversation.started`, `agent.handoff`, `agent.guardrail.blocked`), with
the note that properties never carry draft text and the activity log is not a
transcript.

## Acceptance

- [ ] `14-ai-agents.md` merged and linked from `AGENTS.md` and `00-overview.md`'s
      doc index.
- [ ] Invariants 53–58 merged; invariant 48 amended.
- [ ] D-AI-1…7 in "Decided"; AR-03 upgraded to blocking-for-S23.
- [ ] `01-stack.md` and `08-activity-logging.md` updated.
- [ ] A reader who has never seen the sprint can answer, from the docs alone:
      *why can't the agent tell me my balance?* and *why is the price in that
      message trustworthy?*
