# S24-05 — `sales.create_reservation`

**Repo:** `unit-hq-api`
**Depends on:** `S24-00`, `S24-01`, `S24-02`, `S24-03`
**Blocks:** `S24-06`

## Goal

The sales agent can put a hold on a unit. This is the highest-risk tool in the
sprint and ships in `propose` mode.

## Why this one is different

Every other agent write creates a record. This one **removes inventory from
sale**. A `unit_holds` row makes a unit unavailable to the counter, to the
website, and to every other prospect. A model in a retry loop, or one talked into
it, can hold out a site.

There is no ledger consequence and no fiscal consequence, so this is not
invariant 54a territory. But "reversible" is not "harmless" — the units are
unsellable until someone notices.

## Tool

`App\Support\Ai\Tools\SalesCreateReservationTool implements AgentTool, ProposableTool`

| Property | Value |
|---|---|
| `key()` | `sales.create_reservation` |
| `requiredVerification()` | `ChannelAsserted` |
| `isWrite()` | `true` |
| Seeded policy | `mode = propose`, `max_per_conversation = 1`, `max_per_day = 20` |

**`ChannelAsserted`, not `Anonymous`.** This is the one place the sprint raises a
floor above the `crm.create_*` precedent. A hold should require that the prospect
arrived through a channel we matched to a contact — an email address or a phone
number that exists. A fully anonymous webchat visitor gets a quote and an offer,
not inventory. Write that reasoning into the docstring; it will be questioned.

`propose` mode means the floor rarely bites in S24 — an operator is clicking
anyway. It matters the day someone promotes the policy to `commit`, which is
exactly when nobody will be reading this file.

### Schema

| Arg | Type | Required | Notes |
|---|---|---|---|
| `deal_id` | integer | yes | ownership via `AllowlistedParent` |
| `unit_class_id` | integer | yes | must exist at the deal's site |
| `offer_option_id` | integer | no | when the hold follows an accepted option |

Deliberately **absent**, and non-negotiable:

- **`unit_id`** — auto-pick only, through `ReservationCreation`'s availability
  branch (`availableOn(SiteClock::today($site))` + `lockForUpdate`). A model that
  names a unit is a model that can be steered onto a specific unit. Copilot's
  `Ai/Tools/CreateReservation` accepts `unit_id` because an employee approved
  it; this tool has no such warrant.
- **`expires_at`** — `ReservationCreation::defaultExpiry()` from
  `LeasingSettings`. Never a model argument. This is the single most important
  omission in the file: a model-set expiry is an unbounded hold.
- **`site_id`** — read from the deal. A deal with a null `site_id` is
  `ToolResult::error`, not a guess (`ReservationCreation` already refuses this).
- **`contact_id`** — from the deal.
- **`status`** — always the column default (`pending`).

### Handle

1. `AllowlistedParent::resolve('deal', …)` — same rule as everywhere else.
2. deal has a `site_id`, else error.
3. unit class exists at that site with a current rate + price, else `notFound`.
4. **active-hold check** (`S24-01`): this contact already has an active
   agent-sourced reservation at this (site, unit_class) → `ToolResult::error`
   with a display line saying a hold is already in place. Do not create a second.
5. `ReservationCreation::create(…, unitId: null, expiresAt: null,
   LeasingActor::agent($ctx->agent))`.
6. no available unit → `ToolResult::notFound`, and the model should offer
   alternatives via `facility.availability`. Not a handoff; a normal negative.

### FactBag — read this twice

License: the **hold expiry timestamp** and the unit **class** label. That is all.

Do **not** license: the unit id, the unit number, the unit's position on the site
map, or anything else identifying the specific unit. Per `docs/14-ai-agents.md`,
a unit number is tenant-specific and `facility.availability` deliberately returns
counts and classes, never identifiers. A reservation resolves a specific unit —
that resolution belongs in `data` for the operator trace and must not reach the
model's licensed vocabulary.

The hold expiry is a **civil date** and `GroundingGuard` extracts civil dates.
Call `->date(...)` on the `FactBag` or every successful turn is suppressed. This
is the most likely bug in the task.

### `propose()`

Runs steps 1–4 plus a *would-a-unit-be-available* check without locking or
writing. The preview carries: site name, unit class label, the would-be hold
expiry, and the count of currently available units in that class. The operator
approving needs to see that last number.

Between propose and approve the unit may go. `S24-03`'s approve path re-runs
`propose()` first and returns 422 rather than holding a different unit silently
— but note that approval *does* legitimately pick a different unit than the one
notionally available at propose time, because auto-pick runs fresh. That is
correct and should be stated in the preview copy: the operator is approving *a
hold in this class*, not *this unit*.

## Definition change

Add `sales.create_reservation` to `SalesAgentDefinition::toolKeys()`. Not to
`SupportAgentDefinition`.

## Promotion to `commit`

Out of scope for S24. When it is proposed, the bar is: 200+ replayed
conversations through `agent:replay` with zero grounding suppressions on
reservation turns, zero cross-site holds, zero duplicate holds, and a measured
approval rate above 90% (operators were rubber-stamping, so the click was buying
nothing). Never on a date. Write this into `10-open-decisions.md` under
*Undecided* so the bar exists before anyone wants to clear it.

## Tests

- `SalesCreateReservationToolTest` — `propose` mode writes nothing to
  `reservations` or `unit_holds`, full stop. Assert both tables.
- `…CommitPath` — with the policy forced to `commit` in the test: creates one
  reservation, one `unit_holds` row, `source = ai_agent`, expiry from settings.
- `…IgnoresUnitId` / `…IgnoresExpiresAt` — passing either as an argument has no
  effect on the created row.
- `…RefusesSecondHold` — same contact, same class, same site → error, one row
  total.
- `…RefusesDealWithoutSite`.
- `…NoUnitAvailable` — `notFound`, no handoff, nothing written.
- `…FactBagOmitsUnitIdentifier` — assert the unit id is in `data` and **not** in
  the licensed facts; assert a draft naming the unit is suppressed by
  `DisclosureGuard`.
- `…LicensesHoldExpiry` — a draft stating the expiry date passes `GroundingGuard`.
- `AgentToolWriteGuardTest` — `reservations` and `unit_holds` move to *forbidden
  except via `App\Support\Leasing\ReservationCreation`*.
- Eval fixture: prospect asks to hold a unit → tool proposes → canned line
  returned → no success claim in the draft.

## Acceptance

- [ ] Ships with `mode = propose` seeded; `commit` is not reachable by config alone.
- [ ] No unit id and no expiry can be supplied by the model.
- [ ] Hold expiry is licensed; unit identity is not.
- [ ] One active agent-sourced hold per (contact, site, unit_class), enforced
      under the existing lock.
- [ ] `propose` writes nothing to `reservations` or `unit_holds`.
- [ ] The promotion bar is written into `10-open-decisions.md`.
