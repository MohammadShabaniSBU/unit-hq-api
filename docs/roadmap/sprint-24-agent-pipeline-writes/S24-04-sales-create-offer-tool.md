# S24-04 — `sales.create_offer`

**Repo:** `unit-hq-api`
**Depends on:** `S24-00`, `S24-01`, `S24-02`
**Blocks:** `S24-06`

## Goal

The sales agent creates a real `Offer` row with a real public token, priced from
the catalogue, through `App\Support\Leasing\OfferCreation`. It does **not** send
it.

## Creation is not delivery

The tool creates the offer and returns the public link. It calls no
`SendContext`, no `EmailSender` / `SmsSender` / `WhatsAppSender`, and writes no
`messages` row or `OfferDelivery` row. Invariant 38 and 57 are untouched: agent
conversations stay a reasoning trace.

Whether an agent may *send* an offer is a separate decision with its own consent,
suppression and threading consequences. It is explicitly out of scope for S24
(sprint README). Reviewers: a sender call in this file is a blocking finding.

## Tool

`App\Support\Ai\Tools\SalesCreateOfferTool implements AgentTool, ProposableTool`

| Property | Value |
|---|---|
| `key()` | `sales.create_offer` |
| `requiredVerification()` | `Anonymous` |
| `isWrite()` | `true` |
| `contactScopedArgumentKeys()` | `[]` — ownership handled by `AllowlistedParent` |
| Seeded policy | `mode = commit`, `max_per_conversation = 2`, `max_per_day = 50` |

Anonymous is correct and matches `crm.create_deal`: a prospect the agent just
created a contact for is anonymous by definition. Ownership is enforced by the
`AllowlistedParent` rule below, not by the verification floor.

### Schema

| Arg | Type | Required | Notes |
|---|---|---|---|
| `deal_id` | integer | yes | ownership via `AllowlistedParent::resolve('deal', …)` |
| `options` | array | yes | 1–4 items |
| `options[].unit_class_rate_id` | integer | yes | must belong to the deal's site |
| `options[].discount_id` | integer | no | **catalogue id only** |
| `options[].label` | string | yes | |
| `options[].description` | string | no | |

Deliberately **absent** from the schema, and non-negotiable:

- **`token`** — minted server-side by `OfferCreation` (invariant 6).
- **`expires_at`** — derived from `LeasingSettings::defaultOfferExpirationValue`
  / `…Unit`. A model that can set its own expiry can write a 400-day offer.
- **`contact_id`** — read from the resolved deal. Passing both invites a mismatch.
- **`status`** — always `draft`. The agent does not get to mark an offer `sent`.
- **any price, amount, percent or discount value** — only a catalogue
  `discount_id` from `pricing.discounts`. This is the prompt-injection defence
  named in `docs/14-ai-agents.md`: there is no tool that applies a discount,
  only one that lists catalogue ids. Preserve that property exactly.

`ToolDispatcher`'s existing validator handles scalars but **not** nested array
shapes. Extend it, or validate `options[]` inside the tool and return
`ToolResult::error` — extending the dispatcher is preferable so the guarantee is
central. Note which you did in the PR.

### Ownership

```php
$deal = AllowlistedParent::resolve('deal', $arguments['deal_id'], $principal);
```

Same rule as `crm.create_deal` / `crm.create_task`: a principal with a contact
must own it; an anonymous principal may only attach to a contact with
`source = ai_agent`. Do not write a second ownership check.

Additionally: every `unit_class_rate_id` must resolve to the **deal's site**.
A cross-site option is `ToolResult::error`, not a silent site switch.

### Handle

1. resolve and own the deal (above).
2. resolve each rate → `unit_class_rates` → `price`; missing price →
   `ToolResult::notFound`.
3. resolve tax via `TaxResolver::resolve(null, $class->tax_rate_code, $site)`;
   `ValidationException` → `ToolResult::error(…, HandoffReason::Error)`. Copy
   the handling from `SalesProposeOfferTool` — it is already correct.
4. compute with `BillingMath::applyTax` (exclusive tax), currency from
   **`prices.currency`**, never the site (D1, invariant 30).
5. resolve any `discount_id` through `Discount::query()->active()` +
   `DiscountSurface::resolve(…, locale: $principal->locale)`; unknown id →
   `notFound`.
6. `OfferCreation::create(…, LeasingActor::agent($ctx->agent))`.
7. build `display` and `FactBag`.

### FactBag — what the model may say

License: every `net` / `tax` / `gross` per option, the tax percent, the offer
expiry date, and the public offer URL.

Do **not** license: any `unit_id`, unit number, or the pinned unit's identifier.
Options are presented at UnitClass / rate level commercially — that is a product
rule from `04-crm-pipeline.md`, and `DisclosureGuard` blocks unit-shaped
identifiers below `verified` anyway. The pinned unit id goes in `data` for the
operator trace only.

The offer URL contains a 64-char token. Confirm how `DraftTokenExtractor`
classifies it — if it reads as an `Identifier`, it must be licensed explicitly or
every successful turn blocks. Write a test that asserts the URL survives
`DisclosureGuard` at `anonymous`.

Reuse `MoneyDisplay::withTax` for the rendered string. The model quotes it; it
never does arithmetic (invariant 55).

### `propose()`

Delegate to `SalesProposeOfferTool`'s computation for the preview and return the
normalised payload. Do not write a third pricing path.

## Keep `sales.propose_offer`

It stays in `SalesAgentDefinition::toolKeys()` and is the tool the agent should
reach for first — quote, then commit only when the prospect says yes. Update its
description to say so explicitly, and add a line to the sales agent's
`roleParagraph()` establishing the order. Prompt text is defence-in-depth, not
the defence, but the ordering is genuinely a behaviour we want.

## Definition change

Add `sales.create_offer` to `SalesAgentDefinition::toolKeys()`. Nothing is added
to `SupportAgentDefinition`.

## Tests

- `SalesCreateOfferToolTest` — happy path creates one offer, one option per
  input, token 64 chars and not the PK, each option carries a pinned `unit_id`,
  `source = ai_agent` + `ai_agent_id` set, `expires_at` from settings.
- `…AttemptsExpiryOverride` — an `expires_at` argument is ignored (or rejected);
  the created row uses the setting either way.
- `…RejectsFreeformDiscount` — an argument shaped like a discount amount or
  percent is not in the schema and does not reach the row.
- `…RejectsCrossSiteRate` — a rate from another site is an error and nothing is
  created.
- `…AnonymousOwnership` — anonymous principal + non-`ai_agent`-sourced deal →
  `denied: ownership`; agent-sourced deal → ok.
- `AgentToolWriteGuardTest` — update: `offers` moves from *forbidden* to
  *forbidden except via `App\Support\Leasing\OfferCreation`*. Assert the tool
  reaches the table only through that class. `contracts`, `charges`, `payments`,
  `allocations`, `invoices`, `access_grants`, `access_suspensions` stay
  absolutely forbidden.
- `…NoSend` — assert no `messages`, `offer_deliveries`, or sender invocation.
  Fake the senders and assert zero calls.
- `GroundingGuardTest` — an offer amount the tool did not license is suppressed;
  the licensed one passes.
- Grounding fixture under `tests/Fixtures/agents/` replayable by `agent:replay`.

## Acceptance

- [ ] The tool creates offers only through `OfferCreation`.
- [ ] Token, expiry, status and contact are all server-derived; none is a model
      argument.
- [ ] No free-form discount value can reach the offer — catalogue ids only.
- [ ] No send occurs, by any path.
- [ ] No unit identifier is licensed into the `FactBag`.
- [ ] The public offer link passes `DisclosureGuard` at `anonymous`, proven by test.
- [ ] `sales.propose_offer` still exists and is documented as the first step.
