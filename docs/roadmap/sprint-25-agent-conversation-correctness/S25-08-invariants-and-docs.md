# S25-08 — Invariants 64–66, `14-ai-agents.md`, doc rewrites

**Depends on:** all preceding tasks (lands last)
**Touches:** `unit-hq-api/docs/`

## Problem

The AI agent domain is the only part of this product with **no domain doc**.
S24 shipped nine tasks, split invariant 54, and added invariants 61–63 — and
`AGENTS.md` still has no row pointing anywhere near it. Everything lives in
sprint folders, which means the next agent sprint re-derives decisions that were
already made, and the conflict-flagging instruction at the bottom of `AGENTS.md`
("if a request conflicts with `09`, flag it") cannot fire for agent work,
because the rules are not in `09`.

Two of this sprint's findings are direct consequences: the argument-provenance
hole existed because "grounding" was understood as a claims rule and nobody had
written down that it does not cover inputs.

## What to build

### Invariants for `09-conventions-and-invariants.md`

**64. Entity identifiers in tool arguments must be licensed.** An id passed to
an agent tool must trace to a prior tool result, an explicit user statement, or
conversation context. Never inferred, never sequential, never carried only in
the model's own bookkeeping. Enforced by `ArgumentProvenance` as a **deny**
verdict pre-dispatch, and by a coverage test that fails when a tool adds an
undeclared `*_id` argument. This is the input-side mirror of claim licensing;
it shares that plumbing and must not fork it.

**65. Tool failures return a machine error code and a recovery affordance.** A
prose-only failure is a defect, because it forces escalation where retry was
available. `ToolError` carries `error_code`, optional `recovery {tool, hint}`,
and `candidates` when the failure is ambiguity rather than absence. Argument
schema validation runs before idempotency: a malformed call is not a retryable
write and consumes no idempotency slot and no quota.

**66. A price shown to a customer names the immutable `prices` row it came
from.** Any write derived from that quote asserts the row is still current and
refuses (`price_superseded`) if a successor has been inserted. The agent never
silently offers a number different from the one it stated. Same reasoning as
"approval never replays a stored payload" — live state wins and divergence
surfaces.

### `14-ai-agents.md` — the missing domain doc

| Section | Contents |
|---|---|
| Agent registry | `ai_agents`, personas, model binding, site scope, `ai_agent_id` provenance on written rows, `PipelineSource` |
| Write policy | `agent_write_policies`, propose vs commit modes, quotas, the absent-row-means-commit hazard |
| Dispatch gate | Ordering and the reasoning for it: schema → argument provenance → idempotency → quota → write policy |
| Propose / commit | `agent_pending_actions`, `ProposableTool`, approval re-validates against live state and never replays a stored payload, payload-vs-preview split |
| Guards | The full set with verdict vocabulary (`pass` / `warn` / `deny`) and which are enforcing |
| Licensing | `ForbiddenClaimKey` (claims, turn-scoped, never persisted) and `FactRegistry` (arguments, conversation-scoped, rebuilt from trace) — why the scopes differ |
| Tool contract | `ToolResult`, `EntityRef`, `ToolError`, `ToolErrorCode` |
| Trace | Envelope fields, what enters model context vs what persists |
| Cost | `ai_usage_events`, `ai_model_prices`, invariant 48, why estimated cost never reconciles to the provider invoice |
| Promotion | The measured bars, and that they are measured — never dated |
| Known gaps | Conversation redaction; the two-authorization-systems question below |

### `AGENTS.md`

Add the row that has been missing:

| Working on… | Read |
|---|---|
| AI agents, Copilot, agent tools, guards, write policies | `14-ai-agents.md` |

### `10-open-decisions.md`

- Record the argument-provenance decision under Decided, with the reasoning that
  grounding licenses outputs and cannot license inputs.
- Record the `FactRegistry` conversation-scope divergence from turn-scoped claim
  licences, so nobody "fixes" it later for consistency.
- Move **agent conversation redaction** out of Undecided. It is a known
  compliance defect (`agent_conversation_messages` hold names, emails, balances;
  `config/redaction.php` covers `activity_log` and `system_events` only), and
  parking a GDPR gap in a table of open questions understates it.
- Add, under Undecided: **agent authorization model.** `agent_write_policies`
  governs what agents may write; `roles` / `role_permissions` / `employee_roles`
  governs what people may write. Two authorization systems will drift silently.
  The question to settle is whether an agent holds a real grant with a real site
  scope — with write policy reduced to mode and quota — or whether the parallel
  table is the deliberate answer. Not settled in this sprint; recorded so it is
  settled deliberately rather than by accretion.

### Cross-references

- `06-communications.md` — GSM-7 transliteration and the SMS segment ceiling
  under the sender section; note that it applies to agent drafts only.
- `02-facility.md` — `site_service_areas`, `sites.latitude` / `longitude`, and
  `size_guides` in the related-tables index.
- `03-pricing.md` — quote-to-offer `price_id` continuity, referencing invariant 66.

## Acceptance criteria

- [ ] Invariants 64–66 in `09`, worded as rules with their enforcement
      mechanism named.
- [ ] `14-ai-agents.md` exists and covers every section above.
- [ ] `AGENTS.md` routing row added.
- [ ] `10-open-decisions.md` updated on all four points.
- [ ] Each preceding task's doc-facing change landed in the domain doc rather
      than only in this sprint folder.
- [ ] One home per rule: nothing restated in two documents. Cross-references
      point; they do not duplicate.
