# S24-08 — Docs and invariants

**Repo:** `unit-hq-api` + `unit-hq-panel`
**Depends on:** all of S24
**Blocks:** —

## Goal

Amend invariant 54, add the three invariants this sprint earns, and update the
domain docs so the next person reads the current rules rather than the old ones.

This task is not paperwork. Invariant 54 currently says the opposite of what the
code will do. Shipping S24 without landing this leaves the repo self-contradicting,
and `AGENTS.md` tells every AI assistant to trust `09` over the code.

## 1. Split invariant 54

Replace the existing 54 with **54a** and **54b**. Keep the number — renumbering
breaks every doc cross-reference in the repo.

### 54a — unchanged in substance, narrowed in scope

> **A customer-facing agent never writes to the ledger, mutates a contract,
> grants access, issues an invoice, or confirms a payment.** Not with
> confirmation, not with an operator in the loop, not behind a flag — those are
> operator actions reached through operator surfaces. Payment confirmation
> remains rail-specific (invariant 11); an agent stating that a payment cleared
> is a defect regardless of what the ledger says. Forbidden tables: `charges`,
> `payments`, `allocations`, `contracts`, `contract_items`, `invoices`,
> `access_grants`, `access_suspensions`. Enforced by `AgentToolWriteGuardTest`.

### 54b — new

> **Pipeline objects (`Offer`, `Reservation`) may be created by an agent only
> through a named transactional entry point in `App\Support\Leasing\`, under an
> explicit `agent_write_policies` row.** Never through a generic field map, never
> through a copy of the transaction. Token, expiry, status, unit selection and
> contact are server-derived; none may be a model argument. Every such row
> carries `source = ai_agent` and `ai_agent_id`. Enforced by
> `AgentToolWriteGuardTest` and `LeasingEntryPointParityTest`.

**Write the reasoning for the split into the doc, not just the rule.** The next
person to read 54 will wonder why Offer moved and Contract did not. The answer —
an Offer writes no ledger row, no occupancy and no fiscal artifact, carries an
expiry, and is voidable; a Contract is the billing anchor — belongs in the text.

Also amend the sentence in 54 that reads *"Permitted agent writes are exactly the
automation allowlist: Contact, Deal, Task, Note."* That is now false. The
automation `CreateObjectAllowlist` is unchanged (`12-automation-engine.md`); the
agent surface is deliberately wider. Say so explicitly, and say why the two
diverged, or someone will "fix" the inconsistency by widening the automation
allowlist to match.

## 2. New invariants

**61. An agent write is idempotent within its conversation.** A retried tool
call with identical normalised arguments produces one row, not two. The key is
computed after schema validation and coercion, never over raw model output.
Enforced by `AgentIdempotencyTest`.

**62. A pending action is an intent, never a result.** Approval re-runs the
tool's validation against current state and may fail; no code path replays a
stored payload into the database. Resolution is by an authenticated employee
`POST` from the panel only — not a model, tool, inbound message, voice turn, or
automation node (extends invariant 60). Enforced by `PendingActionApprovalTest`.

**63. A forbidden claim may be licensed only by a tool that earned it in the
same turn.** `ForbiddenClaimKey` has exactly one case, `AvailabilityGuarantee`.
Payment confirmation, fee waiver, access grant, legal advice and contract
mutation are never licensable, by any tool, in any sprint. A licence does not
persist across turns. Enforced by `ForbiddenClaimLicensingTest` and
`ForbiddenClaimKeyTest`.

## 3. `docs/14-ai-agents.md`

Rewrite these sections:

- **Tool catalogue** — the table gains `sales.create_offer` and
  `sales.create_reservation` with their levels, write flags and default modes.
  Sixteen tools becomes eighteen; the count is stated in prose, so fix it.
- **"Writes that are permitted mirror `CreateObjectAllowlist` exactly"** — no
  longer true. Rewrite with the 54b rule and the divergence rationale.
- **"What is deliberately not built"** — remove `Offer` and `Reservation` from
  the never-list. Keep `Contract`. Add the S24 scope-outs: sending an offer,
  support-agent writes, delinquency autonomy, per-channel autonomy.
- **New section: Write policy and autonomy** — `agent_write_policies`, the three
  modes, quota semantics, the raise-only verification floor, and the promotion
  bar for reservations from `S24-05`.
- **New section: Pending actions** — the propose/approve loop, why approval
  re-validates, click-only.
- **Trace tables** — add `agent_pending_actions` and the new
  `agent_tool_invocations` columns.
- **Guardrails** — the claim-licensing mechanism, and the note that
  `availability_guarantee` is now conditional.

## 4. `docs/10-open-decisions.md`

Under **Decided (do not reopen)**:

- **D-AI-8 — Offer and Reservation creation are permitted; Contract is not.**
  The ledger/occupancy/fiscal reasoning. Note that this reverses a documented
  position and is deliberate.
- **D-AI-9 — Write policy is a table, not `ai_agents.settings`.** Invariant 58:
  settings are tuning knobs; an autonomy mode with quotas is a permission.
- **D-AI-10 — Creation is not delivery.** An agent may create an offer and may
  not send it. The consent, suppression and threading consequences of agent
  sending are a separate decision.
- **D-AI-11 — Reservation ships in `propose`.** With the promotion bar.

Under **Undecided**:

- The reservation active-hold constraint shape (the three options from `S24-01`,
  with option 1 taken and 2/3 recorded as rejected-for-now).
- Agent-initiated sending.
- Support-agent write tools.
- Whether `unit_holds.created_by` should widen to a morph so an agent can be
  stamped directly, rather than null-plus-properties.

Under **Explicitly out of scope**, remove the stale line:

> **`action.create_object` for Contract / Reservation / Offer** — creation is not
> a plain insert…

It is half-resolved. Rewrite it: the generic handler still cannot do it, and
after `S24-00` the transactional entry points exist, so a **dedicated** automation
node for Offer / Reservation is now cheap and is the obvious S25 candidate.
Contract stays out.

## 5. `docs/04-crm-pipeline.md`

Add to the Offer and Reservation sections: rows may originate from an agent;
`source` / `ai_agent_id`; the public offer-accept path stamps `public_link`.
Note that all creation now routes through `App\Support\Leasing\`.

## 6. `docs/AGENTS.md`

The routing table gains a row:

| Agent write policies / pending actions / leasing entry points | `14-ai-agents.md` + `roadmap/sprint-24-agent-pipeline-writes/` |

Add to the non-negotiables summary: *Offer / Reservation creation goes through
`App\Support\Leasing\` — never a second copy of the transaction.* That line is
the one most likely to be violated by a future assistant working quickly, and
`AGENTS.md` is what it will read first.

## 7. `docs/ops/`

Runbook for `agents:recall` (`S24-01`): when to use it, the dry-run default, what
it refuses to touch, and the fact that accepted offers are reported rather than
reversed.

## 8. `docs/roadmap/README.md`

Update section 1 to reflect that customer-facing agents now write pipeline
objects. Add S24 to the sprint list.

## Tests

- `InvariantDocCoverageTest` if one exists — otherwise skip; do not build a doc
  linter in this task.
- Confirm every test named in 54a, 54b, 61, 62 and 63 actually exists and is
  green. An invariant citing a test that does not exist is worse than an
  uncited one.

## Acceptance

- [ ] `09-conventions-and-invariants.md` no longer contradicts the shipped code.
- [ ] 54 is split, keeps its number, and carries the reasoning for the split.
- [ ] 61, 62, 63 added, each citing a test that exists and passes.
- [ ] `14-ai-agents.md` tool table matches `ToolRegistry` exactly — check by
      running the coverage test, not by eye.
- [ ] The stale `action.create_object` scope-out is rewritten, not deleted.
- [ ] `AGENTS.md` carries the one-line entry-point rule.
- [ ] A reader who knows nothing about S24 can find, in `10-open-decisions.md`,
      why Offer moved and Contract did not.
