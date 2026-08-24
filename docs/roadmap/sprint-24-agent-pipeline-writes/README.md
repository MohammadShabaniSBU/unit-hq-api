# Sprint 24 — Agent pipeline writes (Offer + Reservation)

> **Read first:** `docs/09-conventions-and-invariants.md` and `docs/14-ai-agents.md`.
> This sprint **amends invariant 54**. That amendment is a deliberate, argued
> change landed in `S24-08` — not a licence to relax it further. If a task here
> appears to require breaking any *other* invariant, stop and flag it.

## Why this sprint exists

The sales agent (`SalesAgentDefinition`) can create a Contact, a Deal and a
Task. It cannot create an Offer or a Reservation. The business wants both.

Two things stand in the way, and they are not the same thing.

**1. The mechanical problem (real).** Offer creation, offer acceptance and
reservation creation are transactions — token minting, `Unit::resolveUnitIdForRate`,
`lockForUpdate` on the unit row, `writeReservationHold`, price pinning, deal/site
agreement. They cannot be expressed as a field map. This is why they were
excluded from `CreateObjectAllowlist` and from the agent tool surface.

**2. The policy problem (overstated).** Invariant 54 currently forbids agent
creation of Offer / Reservation absolutely — *"not with confirmation, not with
an operator in the loop, not behind a flag."* That absolutism is doing real work
for the ledger, contracts, access and invoices. It is doing much less for an
Offer, which writes no ledger row, no occupancy, no fiscal artifact, carries an
expiry and is voidable.

The sprint splits invariant 54 into 54a (ledger / contract / access / invoice /
payment confirmation — unchanged, absolute) and 54b (pipeline objects — permitted
only through named transactional entry points, under an explicit per-agent write
policy, with quotas, idempotency and server-derived expiry).

## The drift is already here

`grep -rn "writeReservationHold" app/` returns **four** call sites:

| Call site | Notes |
|---|---|
| `Http/Controllers/ReservationController::store` | the reference implementation |
| `Http/Controllers/OfferOptionController::select` | offer acceptance path |
| `Ai/Tools/CreateReservation` (internal Copilot) | **copy** of the whole transaction |
| `Ai/Tools/CreateOffer` (internal Copilot) | **copy** of offer creation |

And they have already diverged. `Ai/Tools/CreateOffer` does not call
`AppliesCreateAttributes` and writes no `SystemEvent`. `Ai/Tools/CreateReservation`
accepts `expires_at` **and** `unit_id` straight from the model. Adding a fifth
and sixth copy for the customer-facing runtime is not an option.

`S24-00` is therefore a hard prerequisite, and it pays for itself before any
agent feature ships.

## Risk split — Offer and Reservation are not symmetric

| | Offer | Reservation |
|---|---|---|
| Ledger effect | none | none |
| Inventory effect | none | **writes `unit_holds`** — takes a unit off the market |
| Reversible | expire / void | release hold |
| Worst case | a wrong catalogue price reaches a prospect | a looping agent holds every unit at a site |

They ship at different autonomy levels. Offer goes to `commit`. Reservation
ships in `propose` (operator clicks) and is promoted per-agent only on measured
containment from `agent:replay` — never on a date.

## Task index

| Task | Repo | Depends on | Summary |
|---|---|---|---|
| `S24-00` | api | — | Extract `App\Support\Leasing\` entry points; collapse four call sites. Pure refactor |
| `S24-01` | api | 00 | Provenance (`source`, `ai_agent_id`) on `offers` / `reservations`; hold uniqueness; `agents:recall` |
| `S24-02` | api | 00 | `agent_write_policies`; write-policy gate in `ToolDispatcher`; idempotency on invocations |
| `S24-03` | api | 02 | `agent_pending_actions` + approval API; re-validation at approval; expiry sweep |
| `S24-04` | api | 00, 01, 02 | `sales.create_offer` tool |
| `S24-05` | api | 00, 01, 02, 03 | `sales.create_reservation` tool |
| `S24-06` | api | 04, 05 | Guardrail reconciliation + eval fixtures |
| `S24-07` | panel | 02, 03 | Write-policy settings, pending-actions queue, trace pane |
| `S24-08` | api + panel | all | Docs, invariant 54 split, new invariants 61–63 |

## Sequencing

```
S24-00 ──┬── S24-01 ──┐
         └── S24-02 ──┼── S24-04 ──┐
                 └─── S24-03 ──────┼── S24-05 ── S24-06 ── S24-08
                          └──────── S24-07 ─────────────────┘
```

`S24-00` and `S24-08` are the two that must not be skipped. Everything between
them is a feature; those two are the reason the feature is safe.

## Out of scope for S24

- **Sending** the offer. Creation is not delivery. `OfferDelivery` / `SendContext`
  stays operator- or automation-triggered. An agent that can create *and* send is
  a separate decision and turns invariant 57 ("trace, not message store") into a
  live question.
- Contract creation. Invariant 54a, unchanged, forever.
- Support agent write tools. Sales only.
- Autonomy for the delinquency domain. Never, in any sprint.
- Migrating Copilot (`app/Ai/`) onto the customer-facing runtime — but Copilot's
  two duplicated tools **are** rewritten to call the shared entry points in `S24-00`.
- Per-channel autonomy configuration.
