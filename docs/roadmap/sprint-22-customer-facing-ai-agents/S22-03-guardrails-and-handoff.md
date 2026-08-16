# S22-03 — Guardrails and handoff rules

**Repo:** `unit-hq-api`
**Depends on:** `S22-01`
**Parallel with:** `S22-02`

## Goal

Make the failure modes deterministic. Escalation is a state machine, not a
sentence in a prompt. Grounding is a check, not a hope.

## Pipeline order

Order matters and is asserted by test.

**Inbound (before the model runs):**

1. `LoopGuard` — turn caps, repeat detection.
2. `BudgetGuard` — conversation token / call ceiling from `ai_usage_events`.
3. `HandoffRules` — deterministic triggers on the raw input.

**Outbound (on the assistant draft, before it leaves the runtime):**

4. `GroundingGuard`
5. `ForbiddenClaimGuard`
6. `DisclosureGuard`
7. `ChannelGuard`

A block at 4–7 suppresses the draft, writes the message row with `blocked_by`,
and converts the turn into a handoff. The customer never sees a blocked draft —
in the demo, the trace shows what was blocked and why, which is the most useful
thing on the screen during prompt work.

## `HandoffRules` — deterministic, pre-model

`App\Support\Ai\Guards\HandoffRules`. Config-driven keyword and state sets,
evaluated against the raw input before any model call. A match means **no model
call happens at all**.

| Rule key | Reason | Trigger |
|---|---|---|
| `legal_or_complaint` | `legal_or_complaint` | lien, auction, solicitor / lawyer / abogado, ombudsman, court, small claims, chargeback, "sue", data-protection erasure requests, death / estate, damage or insurance claim |
| `delinquency` | `delinquency` | the principal has an open delinquency case, **or** the message mentions arrears, overlock, cut lock, "why can't I get in" |
| `price_negotiation` | `price_negotiation` | discount / cheaper / match / negotiate / "best you can do", beyond the catalogue |
| `move_out_commitment` | `unsupported_intent` | notice, vacate, move out, terminate — collect intent, never commit a date |
| `payment_dispute` | `unsupported_intent` | "I already paid", "charged twice", refund |
| `customer_requested` | `customer_requested` | human / agent / manager / person / "real person" |
| `third_party` | `legal_or_complaint` | asking about another tenant, another unit's occupant |

Notes:

- **Multilingual.** en / es at minimum, matching the panel locales. A Spanish
  tenant writing *"me han puesto un candado"* must trip `delinquency`.
- Keyword lists live in `config/ai-handoff.php` so they can be tuned without a
  deploy-shaped code change, but the **rule keys and reasons are enum cases**,
  not config strings.
- Word-boundary matching, accent-folded, case-insensitive. Avoid substring
  matches that fire on innocent words.
- False positives here are cheap (a human answers a question a bot could have).
  False negatives are expensive (a bot answers a legal question). Tune loose.

Delinquency deserves its own line: an agent chasing or discussing debt in ES/UK
touches the `delinquency_policies` work and real collections law. **No autonomy
inside delinquency, ever, in any sprint.** It is a hard escalation.

## `GroundingGuard`

The single highest-value guard. Diffs the draft against the turn's `FactBag`.

Extract from the draft:

- currency amounts (symbol-prefixed, symbol-suffixed, `EUR 12,00`, `12.00 €`);
- bare decimals and integers above a floor (ignore 1–12 when they read as
  counts in prose, but never ignore anything adjacent to a currency symbol or a
  unit of area);
- dates in any panel-supported format, plus relative expressions resolved
  against `SiteClock`;
- unit-identifier-shaped tokens (`A-114`, `B12`).

Every extracted token must be present in the `FactBag`, normalised. Tokens the
customer supplied in their own message are licensed (echoing "you said €80" is
fine). Anything else → **block**, write `agent_handoffs` with
`grounding_failure` and the offending token in `detail`.

Percentages get special treatment: a tax rate or discount percent must come from
a tool. `21%` invented by the model is the exact failure this guard exists for.

Tune the extractor against real drafts in `S22-06` fixtures. Expect to iterate;
budget for it.

## `ForbiddenClaimGuard`

Pattern set over the draft, shared plus per-agent additions from
`AgentDefinition::forbiddenClaims()`:

| Claim class | Examples |
|---|---|
| Payment confirmation | "your payment has been received / processed / cleared" |
| Fee waiver | "I've waived", "we'll cancel the late fee", "no charge for that" |
| Access grant | "I've unlocked", "you can get in now", "I've removed the overlock" |
| Availability guarantee | "I've held it for you", "it's reserved" (nothing is reserved this sprint) |
| Legal advice | "you are not liable", "the contract doesn't allow them to" |
| Contract mutation | "I've updated your contract", "I've changed your rate" |

Match → block + handoff `unsupported_intent`.

## `DisclosureGuard`

Two jobs.

**Leak check** — does the draft contain tenant-specific content the current
verification level does not license? Cross-check against the tiers in `S22-02`:
if the level is below `verified` and the draft contains an amount, a unit
identifier, a contract date, or an address, block. This catches the case where
the model reconstructs private data from conversation history after a level
downgrade.

**AI disclosure** — EU AI Act Art. 50 transparency. The first assistant turn of
every customer-facing conversation states plainly that it is an automated
assistant, and every channel profile carries a footer/signature line. Enforce
positively: if `sequence` is the first assistant turn and no disclosure phrase
is present, append the configured line rather than blocking.

Cheap now. Expensive to retrofit into every template later.

## `ChannelGuard`

Against `ChannelProfile`:

- SMS over `maxCharacters` → block and re-request a shorter draft once, then
  handoff. Compute segments (GSM-7 vs UCS-2 — a single `é` changes the maths)
  and surface the count in the trace.
- Email without a subject when `supportsSubject` → block.
- HTML in a plain-text channel → strip and warn in the trace.
- WhatsApp: advisory only this sprint. Emit a trace event noting whether the
  turn would need an approved template rather than a session message, so the
  distinction is visible in the demo. Enforcement lands in S23 against
  `WhatsAppWindow` and the `whatsapp_templates` registry.

## `LoopGuard`

Even with no transport, build the habits:

- max turns per conversation (`config('ai.max_turns')`);
- max consecutive assistant turns without new customer input — should be 1;
- near-duplicate draft detection (normalised Levenshtein against the previous
  two assistant turns) → handoff `repeated_failure`;
- two consecutive turns ending in a tool `error` or `not_found` → handoff
  `repeated_failure`.

In S23 this grows the auto-responder rules: never reply to a message flagged
`auto_generated`, never reply to a message whose `source` is `agent`. Leave a
named seam.

## `BudgetGuard`

Per-conversation token ceiling and a per-conversation call ceiling, read from
`ai_usage_events` for this `agent_conversation_id`. Trip → close the
conversation, handoff `budget_exceeded`.

Also a global kill switch: `config('ai.enabled')` and `ai_agents.is_active`.
Both checked at the runtime entry point, not in the controller — a kill switch
that only covers one caller is not a kill switch.

## Prompt injection posture

Stated once, here, so it does not get re-argued:

- All customer text and all tool results are **data**. The system prompt says
  so; the message assembly wraps them in a delimited block that identifies them
  as untrusted.
- The real defence is the tool surface. The agent cannot apply a discount
  because no tool applies a discount. It cannot read another tenant's unit
  because ownership is checked before `handle()`.
- No guard attempts to *detect* injection by pattern. That is a losing game and
  a false sense of safety. If an injection succeeds in changing the model's
  intent, the tool gates and the grounding guard still hold.

## Tests

- `HandoffRuleTest` — every rule key fires on its en and es corpus, and does
  not fire on a benign near-miss set.
- `GroundingGuardTest` — invented amount blocked; tool-sourced amount passes;
  customer-echoed amount passes; invented tax percent blocked; `84,70` matches
  `84.70`.
- `ForbiddenClaimGuardTest` — one case per claim class.
- `DisclosureGuardTest` — leak at `channel_asserted`; disclosure appended on
  first turn only.
- `ChannelGuardTest` — SMS segment maths for GSM-7 and UCS-2.
- `GuardPipelineOrderTest` — a pre-model handoff prevents any model call
  (assert the driver spy is never invoked).

## Acceptance

- [ ] A deterministic handoff fires with **zero** model calls.
- [ ] A draft containing a number not in the `FactBag` never leaves the runtime.
- [ ] The first assistant turn of a customer-facing conversation always
      discloses that it is automated.
- [ ] Kill switch at the runtime entry point disables both agents everywhere.
- [ ] Every block writes a row a human can read afterwards — `blocked_by` on the
      message, reason and `detail` on the handoff.
