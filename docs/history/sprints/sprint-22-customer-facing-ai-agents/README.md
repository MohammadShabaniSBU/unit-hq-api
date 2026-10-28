# Sprint 22 — Customer-facing AI agents (runtime + demo harness)

> Renumber `S22` if sprint 22 is already taken. Task ids are referenced across
> the files in this folder and in `docs/09-conventions-and-invariants.md`.

## What ships

The **agent runtime** — principal model, tool contract, guardrails, telemetry,
eval harness — plus a single operator-facing surface at panel `/demo/chat` for
presentation and prompt iteration.

**No channel is connected.** Nothing this sprint sends an email, an SMS, a
WhatsApp message, or creates a `messages` row. The runtime's output is a
**draft turn**; the demo page is a sink that renders the draft instead of
delivering it.

This is deliberate and is not a throwaway: rendering-instead-of-sending is
`suggest` autonomy with a null transport. The same runtime, tool set, and
guardrails ship to production in S23 when a transport is bolted on.

## Why the demo is built against the real shapes

Three inputs a live channel would supply are **selected by the operator** in the
demo instead of being faked away:

| Input | Demo control | Why it cannot be skipped |
|---|---|---|
| Agent | picker (`support` / `sales`) | Different tool sets, different refusal rules |
| Channel | picker (`email` / `sms` / `whatsapp` / `webchat`) | Changes length budget, HTML vs plain, subject line, signature — `ChannelProfile` must exist now or every reply is email-shaped forever |
| Principal + verification | contact picker + level toggle | The tool gate is verification level. If this is stubbed, the entire authorization model is untested at the moment it matters most |

The verification toggle is the demo. `channel_asserted` → "what's my balance?" →
agent declines and offers to verify. Flip to `verified` → same question → real
figure off the ledger. That single interaction is the argument for the
architecture, and it costs nothing extra because the gate has to exist anyway.

## Decisions ratified for this sprint

| # | Decision | Rationale |
|---|---|---|
| **D-AI-1** | **`AgentPrincipal` travels as an explicit constructor/method argument and is re-validated at every tool call site.** Never a container singleton, never resolved from the request inside a tool, never an ambient static. | Superficially resembles what invariant 46 forbids, so it needs an explicit ruling: the principal is the *subject* of the request, not an authorization *scope*. Scoping stays explicit and local; the principal is passed, not ambient. See `S22-01`. |
| **D-AI-2** | **Employee RBAC does not authorize agent tools.** RBAC answers "which sites may this staff member see." Agent authorization answers "does this row belong to the contact I am talking to." Tools resolve site through `SubjectSite` but gate on `VerificationLevel` + contact ownership. | Reusing `employee_roles` for a Contact principal is a category error and would silently grant cross-tenant reads. |
| **D-AI-3** | **Agent conversations are a reasoning trace, not a message store.** Invariant 38 is unchanged: a `messages` row still means exactly one real send or receipt. Agent drafts are never `messages` rows. When channels land, `agent_conversations.message_thread_id` links the trace to the thread; the thread stays canonical. | Prevents a shadow inbox that operators must reconcile against the real one. |
| **D-AI-4** | **`ai_agents` is a real Eloquent model, archive-only, with no morph-map alias.** | An agent's writes must stamp as the agent in `activity_log.causer_type`, which requires a model. No alias, for the same reason `employee` has none (invariant 15 / `10-open-decisions.md`). |
| **D-AI-5** | **No RAG in v1.** Curated per-site FAQ snippets in `config/ai-knowledge/`, retrieved by key, not by embedding. | A self-storage FAQ (hours, access, insurance, notice period, prohibited items, overlock) fits in a prompt. `pgvector` does not exist in the SQLite test path. Add retrieval when the curated set stops fitting. |
| **D-AI-6** | **Agent definitions and tools are code; `ai_agents` rows are instances.** An `ai_agents.key` with no matching `AgentDefinition` is a defect, not data — enforced by `AgentDefinitionCoverageTest`. | Same grep-as-test discipline as `Permission` (invariant 43). |
| **D-AI-7** | **`agent_conversations.origin` is non-null from day one** (`demo` / `inbox` / `webchat`). | One column keeps demo traffic out of Insights and out of eval corpora permanently. Adding it later means backfilling — the D3 / I5 reasoning. |

## Explicitly out of scope

- Any transport. No `SendContext`, no `EmailSender` / `SmsSender` /
  `WhatsAppSender` call, no `messages` row, no thread, no webhook.
- `agent_drafts`, `contact_verifications` (the level is a demo toggle),
  `ai_agent_channel_settings`, autonomy modes beyond `suggest`.
- The `webchat` `Channel` enum value and its adapter (S23).
- Extending `contacts:redact` to agent tables — **blocking for S23, not for
  this sprint**, because demo traffic runs against `demo:seed` fiction. See
  `S22-07` and AR-03 in `10-open-decisions.md`.
- Insights reports on agent performance (containment, handoff mix, accept
  rate). The tables are shaped to support them; no report ships.
- Any RAG / vector store.

## Task sequence

| Task | Title | Depends on | Rough size |
|---|---|---|---|
| `S22-00` | Schema, models, permission | — | M |
| `S22-01` | Runtime, principal, tool contract | `00` | L |
| `S22-02` | Tool set (sales + support) | `01` | L |
| `S22-03` | Guardrails and handoff rules | `01` | M |
| `S22-04` | API surface + SSE streaming + telemetry | `01`–`03` | M |
| `S22-05` | Panel `/demo/chat` | `04` | L |
| `S22-06` | Eval harness (`agent:replay`) | `02`, `03` | M |
| `S22-07` | Docs, invariants, open decisions | all | S |

`S22-02` and `S22-03` can run in parallel once `S22-01` lands. `S22-06` is the
cheapest it will ever be right now — there is zero production risk this sprint,
and it is the only thing that lets a prompt or model change ship later without a
knot in someone's stomach. Do not let it slip to S23.

## Sprint definition of done

- `php artisan agent:replay` green for both agents against the cassette set.
- `php artisan test` green, including `AgentDefinitionCoverageTest`,
  `AgentToolCoverageTest`, `PermissionCoverageTest`, `PanelPermissionMirrorTest`,
  `RouteAuthCoverageTest`.
- `bun run lint` + `bun run typecheck` green.
- Five demo scenarios reproducible end-to-end against `demo:seed` (see
  "Presentation script" below).
- `docs/14-ai-agents.md` merged; `09` invariants 53–58 merged; `AGENTS.md` row
  added.

## Presentation script (the five scenarios to nail)

Recorded here so `S22-02`/`S22-03`/`S22-06` can target them explicitly. A demo
that does five things flawlessly beats one that does twenty adequately.

1. **Verified balance** — support agent, `verified`, "how much do I owe?" →
   `billing.balance` → grounded figure, correct currency, no invented tax.
2. **The refusal** — same question at `channel_asserted` → tool denied, agent
   explains and offers verification. Trace shows `denied: verification`.
3. **Quote to proposal** — sales agent, anonymous, "do you have a 10 m² in
   Madrid and what does it cost?" → `facility.availability` +
   `pricing.quote` → catalogue price with exclusive tax rendered → proposed
   offer object in the trace. No `Offer` row is created.
4. **Handoff** — support agent, "I got a letter about an auction" → deterministic
   rule fires before the model runs → banner: *would escalate — reason:
   `legal_or_complaint`*.
5. **Channel shape** — same support answer rendered as `sms` (segment counter)
   and as `whatsapp` (session/template chip). Demonstrates `ChannelProfile`
   without any transport.

## Open questions for the sprint review

- Does the sales agent get a path to `awaiting_signature` in S23? S14's e-sign
  flow makes offer → accept → envelope reachable, and S14-00's no-pre-signature-
  deposit rule means it reaches signature but never money — the right shape.
  Decide before tool boundaries harden.
- Who owns the escalation SLA once channels connect? An agent that hands off at
  02:00 into an unwatched queue produces excellent containment metrics and a
  worse experience than the autoresponder it replaced.
