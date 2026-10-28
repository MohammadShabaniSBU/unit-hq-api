# S27-06 — Invariants, decisions, docs

**Depends on:** S27-00 … S27-05
**Blocks:** nothing
**Touches:** `unit-hq-api/docs`

Lands last. Nothing in this task changes behaviour; everything in it records
behaviour the sprint changed.

## Invariants (`09-conventions-and-invariants.md`)

Next free number is **71**.

> **71. One customer-facing agent definition serves a conversation, and tool
> exposure is decided by verification, not by which agent answered.**
> `AgentDefinition::eligible()` is not a routing mechanism — audience
> selection lives on `agent_channel_bindings.audience`, where an operator can
> see and change it. A definition may narrow its own tool list, but a tool
> that returns `VerificationLevel::Verified` from `requiredVerification()` is
> protected by dispatch gate `verification`, never by its absence from a
> `toolKeys()` array. Adding a tenant tool to an agent's list is therefore
> safe; lowering a tool's floor is not.

> **72. Verification is earned inside a conversation and never inherited.**
> A principal reaches `VerificationLevel::Verified` only by consuming a
> `contact_verifications` challenge delivered to a `contact_channels` row that
> already belonged to the contact. The destination is resolved server-side and
> is never an argument. Caller ID, a self-stated address matching an existing
> contact, and a prior verified conversation for the same contact all stop at
> `channel_asserted`. `origin = demo` writes `verified` directly and is
> excluded from every metric (invariant 59).

Also amend **invariant 58**'s surrounding prose: `ai_agents` rows are
instances, archived instances stay seeded, and a definition class whose key
still appears in `ai_agents` is never deleted.

## Decisions (`10-open-decisions.md`)

Under Decided:

> **D-AI-22 — One customer-facing agent (`concierge`).** `SalesAgentDefinition`
> and `SupportAgentDefinition` are merged into `ConciergeAgentDefinition`
> holding the union of both tool surfaces. The split could not route: D-AI-19
> allows one agent per `(channel, site)`, so `eligible()` could only subtract
> from `audience`, and the seeded email binding dropped every prospect. The
> safety property that justified the split (sales lacks billing tools) was
> gate 1 of nine, and gate 3 already required `verified` for all five tenant
> tools. The role paragraph branches on verification so an unverified caller
> is not told the account tools exist. Both legacy definitions stay
> registered forever — `AiAgent::definition()` resolves them for historical
> conversations and `agent_conversations.ai_agent_id` is `restrictOnDelete`.

> **D-AI-23 — Verification is a conversation-scoped OTP over an existing
> channel.** `contact_verifications`; code hashed at rest; destination
> resolved server-side from `contact_channels`, never supplied; delivery via
> `SmsSender` / `EmailSender` with a transactional `SendContext`; TTL 10
> minutes, 5 attempts, 3 issues per contact per hour. Promotion runs through
> `PrincipalPromotion`. Not inherited across conversations. No tenant
> credential, session, or portal is created — this verifies a conversation,
> not a login.

Remove from Undecided: the **Cross-agent handoff** row. It is answered — there
is no second customer agent to hand off to.

Amend **AR-03** rather than closing it: `contact_verifications` joins the list
of agent tables outside `config/redaction.php`. The row holds a contact id, a
channel id and a code hash — no plaintext PII, but it is a record that a named
person was asked to prove identity, and it belongs in the redaction scope with
the rest. AR-03 stays blocking before any provider binding goes to `auto`.

## `14-ai-agents.md`

The largest edit. Sections to rewrite:

| Section | Change |
|---|---|
| **Agent registry** | "Two personas ship as definitions" → one live, two retained. State the never-delete rule and why. |
| **Principal and verification** | Add the OTP path. Remove the framing that `verified` is a demo-only level. |
| **Tool catalogue** | The Sales / Support columns collapse to one Concierge column plus the existing Level column. The Level column is now the whole story, which is the point. |
| **Why the agent cannot tell you your balance** | Rewrite. The current answer is partly "the sales agent has no such tool"; the answer is now entirely the verification gate, and there is a path through it. Describe the path. |
| **Live channels / Bindings** | `audience` semantics under one agent; `eligible()` no longer subtracts. |
| **Promotion** | Two paths, not one. |
| **What is deliberately not built** | Remove `contact_verifications` / OTP from the list. Leave everything else, including customer-facing voice — that stays not-built until sprint 28. |
| **Known gaps** | Gap 1 (AR-03) gains `contact_verifications`. Gap 2 (two authorization systems) is unchanged and explicitly not settled by the S27-03 policy merge. |

S27-05 originally asked the write-policy page to surface an inline note on
any row whose activity log carried `ai.write_policy.merged`. That
affordance is dropped: S27-03's strictest-wins merge narrows nothing against
seeded data (support holds zero write policies; sales policies carry
forward unchanged), so the event never fires and the note can never render.
The activity row remains the audit trail if a future non-seeded conflict
does narrow a value.

## `AGENTS.md`

No routing-table change (`14-ai-agents.md` is already the entry for agent
work). Add one line to the non-negotiables summary: verification is earned,
never asserted.

## Acceptance criteria

- [ ] Invariants 71 and 72 written; 58's prose amended.
- [ ] D-AI-22 and D-AI-23 recorded; cross-agent-handoff row removed from
      Undecided; AR-03 amended, not closed.
- [ ] `14-ai-agents.md` sections above rewritten; no sentence in the file
      still describes two customer-facing personas.
- [ ] Sprint 26's README left untouched — it is history, not current state.
- [ ] `docs/roadmap/README.md` sprint index updated.
- [ ] Grep check: no doc outside `roadmap/sprint-2[2-6]-*` refers to "the
      sales agent" or "the support agent" as live.
