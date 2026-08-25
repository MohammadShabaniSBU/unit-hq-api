# S26-02 — Capture stated needs, promote the principal, resolve the site

**Depends on:** S26-00, S26-01
**Blocks:** nothing
**Touches:** `unit-hq-api` (incl. seeders)
**Trace evidence:** trace-7, trace-56, trace-57, trace-64

## Problem

Three things the customer told us were dropped on the floor.

1. **Need fields.** "I wanna move on next Monday", "business", "20 to 24
   boxes" were all stated. `crm.create_deal` schema is `contact_id, site_id,
   unit_class_id, notes`; `sales.propose_offer` has `move_in_date` and left it
   null. `deals.expected_move_in`, `expected_stay_length`,
   `expected_stay_period`, `desired_size`, `desired_unit_class_id` exist
   (`04-crm-pipeline.md`) and were never written.
2. **Principal stays anonymous after identity.** `crm.create_contact` created
   contact 833 from a self-stated email. The conversation's
   `verification_level` stayed `anonymous`, so `sales.create_reservation`
   (floor `channel_asserted`) was unreachable for the rest of the
   conversation.
3. **Site never resolved.** Postcode 28001 returned `match_reason: no_match`
   for all five sites because the demo world seeds no `site_service_areas`.
   Every prospect gets the five-site list.

## What to build

### Deal need fields on tools

`crm.create_deal` and `sales.propose_offer` schemas gain optional:

| Key | Type | Writes |
|---|---|---|
| `expected_move_in` | ISO date | `deals.expected_move_in` |
| `expected_stay_length` + `expected_stay_period` | int + `week`\|`month` | same-named columns |
| `desired_size_m2` | decimal string | `deals.desired_size` |
| `purpose` | `personal`\|`business` | `deals.notes` prefix until a column exists — **do not** add a column in this task; record in `10-open-decisions.md` |

`sales.create_offer` (S26-01) writes `move_in_date` → `expected_move_in`
only when the deal's value is null (never overwrite an operator's value).
Relative dates ("next Monday") are resolved by the **model** into ISO before
the call; the runtime injects `today` (site-local, `SiteClock`) in the
identity block already, so this is a prompt instruction, not code:
*"Convert relative dates to ISO using the date in this prompt before passing
them to a tool."* `GroundingGuard` treats a customer-supplied relative day as
not-a-date (unchanged); the ISO value becomes licensed once a tool echoes it
in `display` — `crm.create_deal` display must echo the stored move-in date.

### Principal promotion

After an `ok` `crm.create_contact` (or a match on an existing contact via its
dedupe path) in a `customer`-audience conversation whose principal is
`anonymous`, `AgentRuntime` updates `agent_conversations.contact_id` and sets
`verification_level = channel_asserted`, then **rebuilds the principal for
the remainder of the turn** (`AgentPrincipal::channelAsserted`). Rules:

- Only upward (`VerificationLevel::satisfies` guard; never demote).
- Only when the conversation has no `contact_id` yet. A second
  `create_contact` in a conversation that already has one is refused
  `invalid_arguments` ("conversation already belongs to a contact").
- Webchat self-stated identity is **asserted, not verified** — `verified`
  still requires the OTP path that is out of scope (14-ai-agents "not built").
- Tier-2 activity `ai.conversation.principal_promoted` on the `ai` channel
  with `{from, to, contact_id}`.

CHECK constraints on `agent_conversations` (`verified` requires contact) are
unchanged and still hold.

### Service areas in the demo world

Add `SiteServiceAreaSeeder` (idempotent, `updateOrCreate` on
`(site_id, kind, value)`) seeding `kind = postcode_prefix` for the five demo
sites, fixed arrays, no randomness:

No prefix may appear under two sites (the lookup index is `(kind, value)`):

| Site | Prefixes |
|---|---|
| Madrid Centro | `2800`, `2801` |
| Madrid Norte | `2803`, `2804` |
| Madrid Sur | `2805`, `2819` |
| Madrid Este | `2802`, `2807` |
| Madrid Oeste | `2811` |

Wire it into `StageSeeder` right after `SizeGuideSeeder` (~line 208) and
into `DatabaseSeeder` at the same point. `SiteResolver` must return
`service_area_prefix` for `28001` → Madrid Centro.

## Acceptance criteria

- [ ] `crm.create_deal` with `expected_move_in: 2026-08-31` writes the column
      and echoes the date in `display`; `GroundingGuard` accepts a draft that
      repeats `31/08/2026`.
- [ ] `sales.propose_offer` with `expected_move_in` shows it in the proposal
      summary and passes it through to `sales.create_offer` payload.
- [ ] Runtime test: anonymous conversation → `crm.create_contact` ok → same
      turn `sales.create_reservation` reaches the dispatcher's `propose`
      step (not `denied: verification`); `agent_conversations` row shows
      `channel_asserted` + `contact_id`; activity row written.
- [ ] Second `crm.create_contact` in the same conversation → `invalid_arguments`.
- [ ] `php artisan demo:seed --fresh` seeds fourteen `site_service_areas` rows
      (nine prefixes + five exact postcodes); `facility.find_sites(query:
      "Madrid 28001")` returns `match_reason: service_area_prefix` for Madrid
      Centro only.
- [ ] `php artisan db:seed` produces the same rows (idempotent on re-run).

## Out of scope

- OTP / `contact_verifications`.
- A `deals.purpose` column.
