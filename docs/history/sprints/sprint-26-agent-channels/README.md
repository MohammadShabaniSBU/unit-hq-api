# Sprint 26 — Agent conversation completion & live channels

**Origin:** review of a demo webchat conversation on the Keevaris sales agent
(Madrid Centro, site 1, 2026-08-25) plus the exported trace. The conversation
reached an agreed proposal — Trastero 12 m², €166.91/month, no discount,
move-in next Monday — and ended in a handoff because `sales.create_offer`
could not be called with arguments the model had ever seen. Contact 833 and
deal 812 were created; the deal carries no move-in date. No offer.

S25 fixed tool identity and provenance. This sprint closes the loop the model
still cannot close (ids it must pass but is never shown; a rate id no tool
surfaces), captures what the customer told us, and then wires the runtime to
real inbound channels behind an explicit per-agent × channel × site binding
that defaults to **off**.

## Findings → tasks

| # | Finding | Trace evidence | Task |
|---|---|---|---|
| 1 | `display` never shows entity ids; the model guessed `site_id: 1` and `unit_class_id: 8` for the 16 m² XL (id 12), then gave up: *"I don't have the specific unit class ID"* | trace-7, trace-15, trace-29, trace-30 | S26-00 |
| 2 | `sales.create_offer` requires `unit_class_rate_id`; no tool ever returns one — the tool is uncallable from the model | trace-65 | S26-01 |
| 3 | First `invalid_arguments` → immediate `agent.escalate(reason: error)`; recovery hint never fed back | trace-65, trace-66 | S26-01 |
| 4 | Customer said "next Monday"; `crm.create_deal` has no move-in field, `sales.propose_offer` left `move_in_date` null | trace-57, trace-64 | S26-02 |
| 5 | After `crm.create_contact` the principal stayed `anonymous`; `sales.create_reservation` (floor `channel_asserted`) unreachable | trace-56 | S26-02 |
| 6 | Postcode 28001 → `no_match` on all five sites; demo has no service areas | trace-7 | S26-02 |
| 7 | `pricing.discounts` returns the raw catalogue ("20% off") with no terms; invites the negotiation `HandoffRules` then hard-escalates | trace-43 | S26-03 |
| 8 | AI disclosure appended as a trailing sentence on turn one | trace-4 (`appended: true`) | S26-04 |
| 9 | "move forward with a reservation for your preferred move-in date" passed `forbidden_claim` | turn 8 draft | S26-04 |
| 10 | Input tokens 3,140 → 18,214 over nine turns (~$0.35 / conversation) | usage rows trace-6 … trace-68 | S26-05 |
| 11 | No inbound listener; `origin = inbox` scaffolded, nothing calls `AgentRuntime::turn` from a real message | `14-ai-agents.md` "Not built" | S26-07 |
| 12 | No operator control for *which* agent answers *which* channel at *which* site, or whether it answers unknown senders | — | S26-06, S26-08 |

## Sequencing

```
S26-00 ──┬── S26-01
         ├── S26-02
         └── S26-03
S26-04 (independent)
S26-05 (independent)
S26-06 ──── S26-07 ──┬── S26-07b
                     ├── S26-07c
                     └── S26-08
S26-09 (last — invariants + docs)
```

S26-00 changes every `display` string and therefore every eval cassette;
it lands first and re-records once. S26-01/02/03 depend on the refs line
existing. S26-06 (bindings) must exist before S26-07 (listener) so the
listener has something to check; there is no "temporarily enabled" state.
S26-07b (auto-lead capture) and S26-07c (webchat) depend on S26-07 but
are **not** gates on the sprint DoD. S26-08 Inbox work depends on S26-07;
`/chat/:token` alone depends on S26-07c.

## Seeding rule for this sprint

Every seeder change lands in **both** `DatabaseSeeder` and the demo stage
(`Database\Seeders\Demo\StageSeeder` → `php artisan demo:seed --fresh`).
Stage generation performs **no random draws** (`09` code conventions): fixed
arrays, sorted inserts, no `fake()` / `mt_rand()` / `shuffle()`.

## Definition of done for the sprint

Replay the S26 fixture `sales_madrid_boxes_to_offer.yaml` (recorded from this
conversation) through `agent:replay`: it must end with an `ok`
`sales.create_offer` invocation, a deal with `expected_move_in` set, zero
handoffs, and zero guardrail denials. Then send one real SMS to a demo site
number with the sales binding in `draft` mode and approve the reply from the
Inbox. That SMS + approve loop needs S26-07 (and S26-08 for the Inbox
card); S26-07b and S26-07c are not gates on this DoD.

## Convention notes for implementers

- No `app/Services/`. Runtime helpers stay under `App\Support\Ai\`, leasing
  entry points under `App\Support\Leasing\`, comms under
  `App\Support\Communications\`.
- Agents never create `messages` rows themselves. A send goes through
  `EmailSender` / `SmsSender` / `WhatsAppSender` with a `SendContext`
  (invariant 38).
- Principals are constructed at the boundary and passed down (D-AI-1,
  invariant 56). The listener in S26-07 is a boundary.
- Panel: i18n for every string, `Array<T>`, `useApi()`.
