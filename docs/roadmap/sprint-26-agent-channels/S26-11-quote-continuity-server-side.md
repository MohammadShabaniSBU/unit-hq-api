# S26-11 — Quote continuity is resolved server-side; the model never passes a price id

**Depends on:** S26-01
**Blocks:** nothing
**Touches:** `unit-hq-api`
**Evidence:** demo SMS conversation, deal 813, `sales.create_offer` →
`invalid_arguments: quoted_price_id is required after a catalogue quote for
this unit class`, then handoff. Arguments the model sent:
`{deal_id: 813, options: [{site_id: 1, unit_class_id: 6, move_in_date: "2026-08-30"}]}`.

## Problem

Same failure class as S26-00, one layer down. `pricing.quote` and
`sales.propose_offer` return `price_id` and `tax_rate_id` in `data` only.
The model sees a money sentence; a price is not an `EntityType`, so there is
no `Refs:` line for it either. `quoted_price_id` / `quoted_tax_rate_id` are
on `EntityArgumentExemptions` as "catalogue continuity", i.e. deliberately
outside provenance — yet `SalesCreateOfferTool::propose()` (line ~203)
requires `quoted_price_id` whenever `PriorCatalogueQuote::namesClass()` says
the class was quoted. After any quote, `create_offer` can succeed only if the
model guesses an integer. The recovery hint ("pass quoted_price_id from the
quote") points at a value it cannot obtain, so S26-01's retry budget is
spent on identical calls and the turn hands off.

`PriorCatalogueQuote` already reads the invocation `result` blob, which
holds `price_id`, `tax_rate_id`, `unit_class_id`, `site_id`. The server has
everything it needs to do the continuity check itself. Continuity is server
state; making the model carry it was the design error.

## What to build

### 1. `PriorCatalogueQuote::latestFor()`

Extend `App\Support\Ai\Tools\PriorCatalogueQuote`:

```php
/** @return array{price_id:int, tax_rate_id:?int, invocation_id:int}|null */
public static function latestFor(?AgentContext $ctx, int $siteId, int $unitClassId): ?array
```

Most recent `ok` invocation of `pricing.quote` or `sales.propose_offer` in
this conversation whose `result` names `(site_id, unit_class_id)`; for
`sales.propose_offer` read the line-item array (`result.line_items[].price_id`)
— pick the item matching the class. Returns null when the class was never
quoted. `namesClass()` becomes `latestFor(...) !== null`.

### 2. `sales.create_offer` and `sales.propose_offer` resolve continuity

In both `propose()` paths, per option:

| Situation | Behaviour |
|---|---|
| Class quoted in this conversation, `quoted_price_id` omitted | Use `latestFor()`'s `price_id` / `tax_rate_id` as the quoted pair. Run the existing belongs-to-rate and `price_superseded` checks against it. |
| Class quoted, `quoted_price_id` supplied | Must equal `latestFor()`'s `price_id` → else `invalid_arguments` ("does not match the price quoted in this conversation"). Then the same checks. |
| Class never quoted, `quoted_price_id` omitted | Allowed, as today (no continuity to enforce). |
| Class never quoted, `quoted_price_id` supplied | `invalid_arguments` — a price id with no prior quote is either a guess or a leak. |

The `invalid_arguments` "quoted_price_id is required" branch is deleted.
`price_superseded` semantics are unchanged: if the catalogue moved since the
quote, the offer is refused and the recovery points at `pricing.quote`.

Record on the offer option payload which invocation supplied the quote
(`quoted_from_invocation_id`) so the trace shows the continuity chain.

### 3. Schema and prompt

- `quoted_price_id` / `quoted_tax_rate_id` are **removed from the model-facing
  schema** of both tools. They stay on `EntityArgumentExemptions::KEYS` only
  as long as `sales.create_reservation` still declares them; audit that tool
  in this task and apply the same resolution if it does.
- `SalesAgentDefinition::roleParagraph()`: drop *"with the same … `quoted_price_id`
  and `quoted_tax_rate_id` you used in `sales.propose_offer`"*. Replace with:
  *"Quote a class with `pricing.quote` before proposing it; `sales.create_offer`
  uses the price you quoted."*
- Schema and prompt hash both move: every cassette goes stale (see below).

### 4. Retry hint quality (S26-01 follow-up)

The retry loop spent its budget on an unreachable hint. Add to `AgentRuntime`
tool loop: if the model retries a tool with **byte-identical arguments** after
an `invalid_arguments`, do not consume a second model call on the same
result — feed back a stronger `Recovery:` line once ("you repeated the same
arguments; change them or escalate") and count it as the final retry. Cheap
guard against wasted turns; test it.

## Seeders / demo

None. Demo world already has the quote → offer path.

## Cassettes

`agent:replay --seal` for all; live re-record for
`sales_madrid_boxes_to_offer.yaml`, `sales/quote-then-offer`, `sales/create-offer`,
`sales/propose-offer` (their model responses contain the removed argument).
List in the PR.

## Acceptance criteria

- [ ] Quote class 6 at site 1, then `sales.create_offer` with only
      `site_id + unit_class_id` → `ok`; the option's `price_id` equals the
      quoted price; `quoted_from_invocation_id` set.
- [ ] Same, but the catalogue price changes between quote and offer →
      `price_superseded`, recovery `pricing.quote`.
- [ ] `quoted_price_id` supplied but ≠ the conversation's quote →
      `invalid_arguments`; supplied with no prior quote → `invalid_arguments`.
- [ ] Never-quoted class, no `quoted_price_id` → `ok` (unchanged).
- [ ] `sales.propose_offer` after a `pricing.quote` for the same class
      reuses that price without any model argument.
- [ ] Runtime: identical-argument retry after `invalid_arguments` gets the
      stronger hint once and does not exceed `max_tool_retries`.
- [ ] Replay of the demo SMS conversation (add as fixture
      `sales/sms-quote-to-offer.yaml`, `live_only`): ends with an `ok`
      `sales.create_offer`, zero handoffs.
- [ ] `AgentToolCoverageTest` and `ToolResultContractTest` green with the
      schema change.

## Out of scope

- Making `Price` an `EntityType`. It is not something the model should
  reason about; that is the point of this task.
- Sending the offer (D-AI-10).
