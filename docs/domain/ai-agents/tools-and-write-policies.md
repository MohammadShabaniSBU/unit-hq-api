# Tools and write policies

Part of the customer-facing agent docs. Map: [README](README.md).


## Tool catalogue

The tool surface is the defence; prompt text is defence-in-depth. The live
definition is `ConciergeAgentDefinition` (24 keys). `ai_agents` rows are
instances (D-AI-6, invariant 58). The Level column is the whole story:
tenant tools sit on the list and are refused at dispatch gate 3 until the
principal is `verified` (invariant 71).

| Tool | Level | Write | Concierge |
|---|---|---|---|
| `facility.availability` | anonymous | | ✓ |
| `facility.find_sites` | anonymous | | ✓ |
| `facility.site_info` | anonymous | | ✓ |
| `facility.size_guide` | anonymous | | ✓ |
| `calendar.resolve` | anonymous | | ✓ |
| `pricing.quote` | anonymous | | ✓ |
| `pricing.discounts` | anonymous | | ✓ |
| `sales.propose_offer` | anonymous | proposal only — persists nothing | ✓ |
| `sales.create_offer` | anonymous | ✓ (`commit`) | ✓ |
| `sales.create_reservation` | channel_asserted | ✓ (`propose`) | ✓ |
| `sales.send_quote` | anonymous | ✓ (`commit`, 3 / conversation, 10 / day) | ✓ |
| `crm.create_contact` | anonymous | ✓ | ✓ |
| `crm.update_contact` | anonymous | ✓ | ✓ |
| `crm.create_deal` | anonymous | ✓ | ✓ |
| `crm.create_task` | anonymous | ✓ | ✓ |
| `crm.create_note` | verified | ✓ | |
| `identity.request_code` | channel_asserted | ✓ (`commit`, 3 / conversation, 10 / day) | ✓ |
| `identity.verify_code` | channel_asserted | ✓ | ✓ |
| `contract.summary` | verified | | ✓ |
| `billing.balance` | verified | | ✓ |
| `billing.next_charge` | verified | | ✓ |
| `billing.invoices` | verified | | ✓ |
| `access.status` | verified | | ✓ |
| `kb.faq_lookup` | anonymous | | ✓ |
| `agent.escalate` | anonymous | | ✓ |
| `channel.send` | — | ✓ (runtime-only) | |

`channel.send` is registered and **unclaimed** (`RuntimeOnlyTools`). A
definition listing it fails `AgentToolCoverageTest`. Binding mode picks
`propose()` vs `handle()`; the model never sees the tool.

`crm.create_note` is registered and **unclaimed by the live agent**. A
customer conversation has no `created_by_employee_id`, so a note it wrote
could not be attributed. Its floor is `verified`. Legacy `support` still
lists it.

A prospect asking about someone's balance is refused at gate 3
(`denied: verification`), then offered `identity.request_code`. The
union of tool lists exposes nothing extra to an unverified caller.

Writes that **are** permitted: `Contact`, `Deal`, `Task`, `Note` (the same
four as `CreateObjectAllowlist`), plus `Offer` and `Reservation` through named
entry points in `App\Support\Leasing\` under an explicit `agent_write_policies`
row (invariant 54b). The automation allowlist is unchanged
(`../12-automation-engine.md`) — a generic field map still cannot mint a token,
pin a unit, or take a hold. The agent surface is wider because it calls those
entry points, not because the allowlist grew. Token, expiry, status, unit
selection and contact are server-derived; none may be a model argument.
`sales.propose_offer` returns a fully priced proposal and writes **no**
`Offer` row. Contract creation stays forbidden (invariant 54a).

### Why the agent cannot tell you your balance

`billing.balance` requires `verified`. At `anonymous` or `channel_asserted`
the dispatcher returns `denied: verification` **before** `handle()` and never
touches the ledger. The live agent holds the tool; the gate is the whole
defence (invariant 71).

The path through the gate: `identity.request_code` sends a hashed OTP to a
`contact_channels` row already on the contact; `identity.verify_code`
consumes it; `PrincipalPromotion` stamps `verified` and writes a
`kind: promotion` trace row (`method: otp`). The next turn's
`billing.balance` passes gate 3. Flipping the demo verification toggle
still writes `verified` directly and remains a demo-only affordance
(invariant 59 / 72).

### Why a quoted price is trustworthy

`pricing.quote` reads the current `prices` row (currency from
`prices.currency`, never the site), names that row as `price_id`, resolves tax
via `TaxResolver` at `SiteClock::today($site)` (never `Carbon::today()`), and
returns `billing_interval` / `billing_interval_count` from org `BillingSettings`
so the summary is a period-qualified quote (`€70.00 net / €84.70 incl. 21% tax,
per month — Small at Madrid Centro`). It computes with `BillingMath` (exclusive:
`tax = round(net × rate/100, 2)`), and puts the rendered string in `display`
and the cents (and `as_of`) in `FactBag`. The model quotes that string. If it
invents a figure, a tax percent, or a unit id, `GroundingGuard` suppresses the
draft and hands off (`grounding_failure`). A suppressed draft is never delivered
(invariant 55).

`sales.create_offer` takes licensed `site_id` + `unit_class_id` per option
and resolves the active `UnitClassRate` server-side at
`SiteClock::today($site)` — `unit_class_rate_id` is never a model argument
(D-AI-17). Quote continuity is also server-side: `PriorCatalogueQuote::latestFor`
reads the most recent `ok` `pricing.quote` or `sales.propose_offer` for that
`(site_id, unit_class_id)` and supplies the quoted `price_id` / `tax_rate_id`.
The model does not pass those ids. A supplied `quoted_price_id` that disagrees
with the conversation's quote, or one with no prior quote, is
`invalid_arguments`. When a quote is on the trace, the creation path asserts
that row is still the current catalogue price for the junction; a closed window
returns `price_superseded` with
`detail: { superseded: 'price' | 'tax_rate', quoted, current }` and recovery
`pricing.quote`. A tax-rate version change uses the same error code; `detail`
tells them apart. The option payload records `quoted_from_invocation_id` so
the trace shows the continuity chain. "Was this class quoted?" is answered from
the **trace** (`PriorCatalogueQuote`), not `FactRegistry` (invariant 66). The
agent never silently offers a number different from the one it stated.
`sales.propose_offer` line items echo `site_id`, `unit_class_id`, `price_id`
and `tax_rate_id` so a later propose of the same class reuses that price (and
refuses if the catalogue moved). Optional `move_in_date` writes
`deals.expected_move_in` only when the deal's value is null.

Other tool notes:

- `facility.find_sites` runs `SiteResolver` (city, postcode, or coordinates).
  Every row is an `EntityRef` of type `site`. `match_reason` is on each payload
  row **and** in `display` — `only_site` / `service_area` /
  `service_area_prefix` / `site_postcode` / `locality` / `distance` mean this
  is the customer's site; `no_match` means present the list and ask. Data-only
  is not enough: the model quotes `display`.
- `facility.site_info` takes an optional `site_id` (argument → principal site →
  resolver with no query). A resolver-supplied reason is echoed in `display`.
  Display tokens (address, hours, phone) are absorbed into `FactBag` so quoted
  strings are licensed.
- `facility.availability` goes through `App\Support\Occupancy\Availability`
  (invariant 5 / 36). Available-or-not per class, **not** counts or unit
  identifiers.
- `facility.size_guide` is a **fit recommendation**, not a FAQ. `kb.faq_lookup`
  answers facts-about-the-site (hours, policy, prohibited items) as a static
  snippet for a curated key. Size guide takes a quantity predicate, resolves a
  band under site-over-org / class-over-band dominance, emits `EntityRef`s, and
  licenses `CapacityGuidance` for this turn. "What are your access hours" →
  `kb.faq_lookup`. "What size do I need for 24 boxes" → `facility.size_guide`.
  Do not add the next lookup tool as another FAQ key by default.
- `billing.balance` returns an array keyed by currency and never a summed
  figure (invariant 30). Derived at read time (invariant 5).
- `billing.next_charge` reads the contract's snapshotted cadence (invariant
  18); date boundaries through `SiteClock` (D8).
- `billing.invoices` lists issued invoices. No PDF, no signed URL.
- `access.status` reports active / suspended / overlocked and a reason
  category. Never a gate code, credential, or door id. A delinquency
  suspension is an escalation trigger, not an explanation.
- `kb.faq_lookup` reads curated snippets from `config/ai-knowledge/{locale}.php`
  by key (`access_hours`, `insurance_required`, `notice_period`,
  `prohibited_items`, `overlock_policy`, `deposit`, `id_required`,
  `payment_methods`). Display tokens are absorbed into `FactBag`. No free-text
  search, no embeddings (D-AI-5). Unknown key → `not_found` → escalate, never
  improvise policy.
- `calendar.resolve` is a deterministic parser (en/es/fr) against
  `SiteClock::today($site)` or `config('app.timezone')`. The ISO date lands in
  `FactBag`; garbage phrases return `invalid_arguments` with a hint to ask
  for the exact date. No entities, so no `Refs:` line.
- `crm.create_contact` sets `contacts.source = ai_agent` and deduplicates on
  `contact_channels`. An `ok` result in an anonymous customer conversation
  promotes the principal to `channel_asserted` (D-AI-18). A second call in
  the same conversation is `invalid_arguments`.
- `crm.update_contact` writes `first_name` / `last_name` on the conversation's
  contact. No `contact_id` argument. Missing contact recovers with
  `crm.create_contact`. Email and phone stay untouched. Does not promote.
- Voice (`VoiceToolSurface`) includes `sales.propose_offer`,
  `sales.create_offer`, and `crm.update_contact` in addition to the
  milestone-one reads and lead writes. `sales.create_reservation` stays off
  voice. After `sales.create_offer`, the spoken reply must not read the
  public preview link.
- `identity.request_code` sends a 6-digit code to a `contact_channels` row
  already on the contact. Optional argument is channel *type* only. Display
  names a masked destination. Seeded policy: `commit`,
  `max_per_conversation = 3`, `max_per_day = 10`.
- `identity.verify_code` consumes the open challenge under a row lock.
  Wrong / expired / exhausted / consumed all fail identically. An `ok`
  result promotes the principal to `verified` (D-AI-23).
- `crm.create_deal` / `sales.propose_offer` accept optional need fields
  (`expected_move_in`, stay length/period, `desired_size_m2`). Relative dates
  go through `calendar.resolve`; the model must not compute a date itself.
  `purpose` (`personal` \| `business`) is prefixed onto `deals.notes` until
  a column exists (`10`).
- `pricing.discounts` returns only `agent_offerable` rows; `display` is the
  locale-resolved `customer_terms` sentence, not the operator name. Empty
  catalogue → no `Refs:` line. A `discount_id` that is not offerable is
  `denied: not_allowed_for_agent` before `handle()`.
- `sales.create_offer` calls `OfferCreation`. Seeded policy: `commit`,
  `max_per_conversation = 2`, `max_per_day = 50`. Creates; does not send
  (D-AI-10).
- `sales.send_quote` sends the written catalogue quote through
  `QuoteDelivery` (`SmsSender` / `EmailSender`). Destination is resolved
  server-side from `contact_channels` (and the voice session caller number
  for SMS). Optional `via` is a preference (`sms` \| `email`), never an
  address. Display carries no figure. WhatsApp waits on an approved
  template. Seeded policy: `commit`, `max_per_conversation = 3`,
  `max_per_day = 10`.
- `sales.create_reservation` calls `ReservationCreation` with auto-pick;
  `unit_id` and `expires_at` are never model arguments. Seeded policy:
  `propose`, `max_per_conversation = 1`, `max_per_day = 20`. Floor is
  `channel_asserted` — a fully anonymous webchat visitor gets a quote and an
  offer, not inventory.
- `agent.escalate` is model-invocable (`trigger_source = model`) and does not
  replace deterministic pre-model rules. `reason: error` while a retry budget
  remains is refused (see turn loop).
- `channel.send` — runtime-only; see Live channels.

## Write policy and autonomy

Write autonomy is a table, not JSON (D-AI-9, invariant 58).
`agent_write_policies` is unique on `(ai_agent_id, tool_key)`. Operators edit
it at Settings `/settings/ai-agents`.

| `mode` | Meaning |
|---|---|
| `off` | Dispatcher denies before `handle()`. No row. |
| `propose` | Tool dry-runs, persists `agent_pending_actions`; operator clicks to commit. |
| `commit` | Tool writes in the turn. |

**Hazard: absent row = `commit`, unlimited.** A missing policy is not "off"
and is not "propose". That default must not change `crm.create_contact` /
`create_deal` / `create_task`. `sales.create_offer` and
`sales.create_reservation` seed their own rows explicitly so they cannot
inherit it by accident. Deleting a policy row restores unlimited commit.

Write policies are per-instance. S27-03 merged sales and support onto
`concierge` with strictest-wins (`off` < `propose` < `commit`; lower quota
wins; higher `min_verification` wins). Against seeded data the merge
narrows nothing — support held zero write policies, sales policies carried
forward unchanged — so `ai.write_policy.merged` never fires and the panel
does not surface an inline note. The activity row remains the audit trail
if a future non-seeded conflict does narrow a value. Legacy `sales` /
`support` policy rows stay in place as the audit trail of what the merge
read.

Quotas (`max_per_conversation`, `max_per_day`) count `agent_tool_invocations`
where `status = ok` — committed writes, not attempts. A denied call does not
burn quota. `max_per_day` rolls at app-timezone midnight. Null = unlimited.

`min_verification` may **raise** the tool's declared floor, never lower it
(`AgentWritePolicy::effectiveVerification()`). String comparison against
verification levels is a defect.

### Reservation promotion

Reservation stays in `propose` until a measured bar is met — never a calendar
date (D-AI-11): 200+ replayed conversations through `agent:replay` with zero
grounding suppressions on reservation turns, zero cross-site holds, zero
duplicate holds, and a measured approval rate above 90% (operators were
rubber-stamping, so the click was buying nothing). The bars are measured.
They are never dated.

This is not `PrincipalPromotion`. Principal promotion (anonymous →
`channel_asserted` via `crm.create_contact`; `channel_asserted` → `verified`
via `identity.verify_code`) is a runtime event on the conversation, recorded
as trace kind `promotion`. See the turn loop above.

## Pending actions

`mode = propose` persists an intent, never a result (invariant 62). Write
tools that can dry-run implement `ProposableTool`: `propose()` validates
against live state and returns `payload` + `preview` with no writes;
`commit()` persists. **Payload vs preview:** payload is stable write inputs
(ids, catalogue refs) — never `now()`, `defaultExpiry()`, availability
counts, or rendered display. Preview holds those derived values and is never
compared on approve.

Between propose and approve the world moves — the catalogue price changes, the
auto-picked unit gets rented. Approval re-runs the same `App\Support\Leasing\`
entry point against current state and may fail. No code path replays a stored
payload into the database.

Resolution is a click-only employee `POST` from `/leasing/agent-approvals` —
not a model, tool, inbound message, voice turn, or automation node (extends
invariant 60). Status: `pending` \| `approved` \| `rejected` \| `expired` \|
`superseded`. A second proposal of the same `(conversation, tool_key)` flips
the prior pending row to `superseded`.

