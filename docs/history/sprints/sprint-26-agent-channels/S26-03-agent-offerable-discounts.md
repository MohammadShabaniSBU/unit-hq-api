# S26-03 — Agent-offerable discounts with customer-facing terms

**Depends on:** S26-00
**Blocks:** nothing
**Touches:** `unit-hq-api` (incl. seeders), `unit-hq-panel`
**Trace evidence:** trace-43

## Problem

`pricing.discounts` returns the raw operator catalogue:
`10% off (id 1); 20% off (id 2); Long-stay promo (id 3)`. The agent read it
to the customer verbatim. Two defects:

- The catalogue is an operator tool. Some rows exist for walk-in negotiation
  or a campaign, not for an automated agent to volunteer to anyone who asks.
- "20% off" with no conditions is an offer, not a promotion. It invites
  "can you do 25?" — which `HandoffRules::price_negotiation` then hard-escalates.
  The agent is setting up the conversation it is forbidden to have.

## What to build

### Schema

Migration on `discounts`:

| Column | Type | Notes |
|---|---|---|
| `agent_offerable` | boolean, default `false` | Operator opt-in per catalogue row |
| `customer_terms` | jsonb nullable | `{ "en": "...", "es": "...", "fr": "..." }` — the sentence the agent may say. Required when `agent_offerable = true` (application validation + CHECK `agent_offerable = false OR customer_terms IS NOT NULL` on Postgres; SQLite skip) |

Archive-only semantics of the catalogue are unchanged.

### Tool

`PricingDiscountsTool`:

- Returns only `agent_offerable = true`, non-archived rows applicable to the
  site (same site/org dominance the catalogue already uses).
- `display` is the locale-resolved `customer_terms` per row, not the
  operator `name`. Locale ladder: principal locale → site locale → `en`.
- `FactBag` absorbs the percent / free-weeks tokens from the terms so the
  draft may repeat them.
- Empty → display `No promotions are currently available at {site}.` and
  **no** `Refs:` line (nothing to license).

`SalesCreateOfferTool` / `SalesProposeOfferTool`: `discount_id` must be
`agent_offerable` → else `denied: not_allowed_for_agent` (dispatcher-level,
before `handle()`, via `entityArguments()` type `discount` + a new
`ArgumentProvenance` check that the discount ref came from
`pricing.discounts` in this conversation). A licensed-but-not-offerable
discount cannot happen after this, but the check is cheap and fail-closed.

### Seeders

`DiscountCatalogueSeeder` (used by both `DatabaseSeeder` and
`StageSeeder`):

| Row | `agent_offerable` | `customer_terms.en` |
|---|---|---|
| 10% off | `false` | — |
| 20% off | `false` | — |
| Long-stay promo | `true` | "Commit to 4 weeks or more and your first 2 weeks are free." |

Add `es` / `fr` strings. Deterministic, `updateOrCreate` on `name`.

### Panel

Settings → Facility → Discounts catalogue: an "Offerable by AI agents"
toggle and a per-locale terms textarea (required when on). Types in
`app/types/`, strings in all three locale files.

## Acceptance criteria

- [ ] Migration up/down on Postgres and SQLite.
- [ ] `pricing.discounts` at Madrid Centro on the demo world returns exactly
      the long-stay row with its terms sentence; `10% off` never appears.
- [ ] `sales.propose_offer(discount_id: 2)` → `denied: not_allowed_for_agent`,
      no `handle()` call.
- [ ] Panel toggle saves; enabling without terms is a 422 with a field error.
- [ ] Replay fixture `sales_discount_not_offerable.yaml` (new): prospect asks
      "any discounts?" → agent states the long-stay terms only.

## Out of scope

- Agent applying a discount not selected by the customer.
- Per-agent offerable sets (one flag, all agents).
