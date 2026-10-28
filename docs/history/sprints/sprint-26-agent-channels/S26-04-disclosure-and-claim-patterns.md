# S26-04 — Disclosure in the greeting; reservation-shaped claim patterns

**Depends on:** nothing
**Blocks:** nothing
**Touches:** `unit-hq-api`
**Trace evidence:** trace-4 (`disclosure`, `appended: true`), turn-8 draft

## Problem

1. The first assistant turn ended with *"…what you're storing. I am an
   automated assistant."* — `DisclosureGuard` filled the line in because
   the model omitted it. Correct outcome, poor sentence. The fill-in exists as
   a safety net (EU AI Act Art. 50), not as the normal path.
2. Turn 8: *"I'll create the offer and move forward with a reservation for
   your preferred move-in date next Monday."* passed `ForbiddenClaimGuard`.
   The `availability_guarantee` pattern set matches "I've held it" /
   "reserved for you" but not "move forward with a reservation" or "I'll
   create the reservation". No reservation had been committed
   (`sales.create_reservation` is `propose`), so the claim was unlicensed.

## What to build

### Disclosure by construction

- `AssemblesSystemPrompt`: when `conversation.audience = customer` **and**
  the conversation has no prior assistant message, inject an explicit
  instruction: *"This is the first message of the conversation. Open with
  one short sentence stating that you are an automated assistant for
  {company}, then answer."* Locale-resolved from `config/ai-handoff.php`
  disclosure strings (they already exist per locale).
- `DisclosureGuard` fill-in stays exactly as is (safety net). Add
  `detail.prompted = true` on the guard event so the trace shows whether the
  model complied or the guard patched.
- Channel profiles: for `sms` the disclosure sentence counts toward the
  segment budget — keep it ≤ 60 chars per locale.

### Claim patterns

Extend `availability_guarantee` in `config/ai-handoff.php` (en/es/fr), word-
boundary, accent-folded:

- en: `move forward with (a|the) reservation`, `(create|make|place|set up)
  (a|the|your) reservation`, `reserve (it|one|a unit|the unit) for you`,
  `hold (it|one|a unit) for you`, `(it's|it is) (reserved|held)`
- es / fr equivalents (`hacer la reserva`, `te lo reservo`, `je vous le
  réserve`, …).

Licensing is unchanged: a committed `sales.create_reservation` this turn
licenses the claim; `propose()` does not. The correct phrasing after a
proposal is the existing prompt line — a hold is *subject to colleague
confirmation*. Add that exact sentence as a `CannedReply` the redraft may
use so the model has a licensed alternative instead of a suppressed draft.

### Redraft, not handoff, on first claim block

Today a `ForbiddenClaimGuard` block → suppress + handoff. For
`availability_guarantee` and `capacity_guidance` only (the two licensable
keys, invariant 63), use `GuardrailVerdict::retry` with the offending
phrase and the licensed alternative, bounded by the existing
`max_redraft_attempts`, then handoff. Non-licensable keys (payment
confirmation, fee waiver, access grant, legal advice, contract mutation)
stay block-and-handoff — a redraft is not the answer to "I've waived your
fee".

## Acceptance criteria

- [ ] First customer-facing turn on the replayed S26 fixture contains the
      disclosure as the opening sentence; `disclosure` guard event has
      `prompted: true, appended: false`.
- [ ] Second turn does not repeat the disclosure.
- [ ] `ForbiddenClaimGuardTest`: each new pattern blocks in each locale;
      the same sentence after a committed reservation this turn passes.
- [ ] Runtime test: an unlicensed "move forward with a reservation" draft
      yields verdict `retry`, the redraft contains "subject to colleague
      confirmation", and no handoff is written.
- [ ] A "your fee has been waived" draft still blocks and hands off.

## Out of scope

- New `ForbiddenClaimKey` cases.
- Changing the redraft budget default.
