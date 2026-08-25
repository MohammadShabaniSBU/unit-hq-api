# S26-01 — `sales.create_offer` is callable; retry before escalate

**Depends on:** S26-00
**Blocks:** S26-02
**Touches:** `unit-hq-api`
**Trace evidence:** trace-65, trace-66

## Problem

`SalesCreateOfferTool::schema()` requires `options[].unit_class_rate_id`.
No tool returns a `unit_class_rate_id`: `sales.propose_offer` echoes
`price_id` and `tax_rate_id`; `pricing.quote` echoes `price_id`;
`facility.availability` echoes `unit_class_id`. The rate id is an internal
junction (`unit_class_rates`) and the model has no legitimate way to learn it.
The tool is structurally uncallable, and the sole `invalid_arguments` it can
ever produce was followed by `agent.escalate(reason: error)` on the first
attempt — a retryable error treated as terminal.

`SalesProposeOfferTool` already resolves the rate server-side from
`site_id + unit_class_id` (~line 94). Create must do the same.

## What to build

### Schema

`options[]` becomes:

| Key | Required | Notes |
|---|---|---|
| `site_id` | yes | Licensed `site` ref |
| `unit_class_id` | yes | Licensed `unit_class` ref |
| `quoted_price_id` | when quoted | unchanged continuity token |
| `quoted_tax_rate_id` | when quoted | unchanged |
| `discount_id` | no | catalogue only; S26-03 narrows to `agent_offerable` |
| `label` | no | **defaults to the unit class label** — the model was inventing labels |
| `description` | no | unchanged |
| `move_in_date` | no | ISO date; written to `deals.expected_move_in` if the deal has none (S26-02 adds the field to propose/create_deal too) |

`unit_class_rate_id` is removed from the schema and from
`entityArguments()`. `ArgumentProvenance::checkRateIds` stays for defence
but is no longer reachable from a model argument; keep its test.

Resolution inside `handle()` / `propose()`: active `UnitClassRate` for
`(site_id, unit_class_id)` at `SiteClock::today($site)`; none →
`ToolError(not_found, recovery: facility.availability)`. All options must be
at the deal's site → else `invalid_arguments`. Then `OfferCreation` exactly as
today; `PriorCatalogueQuote` and `price_superseded` semantics unchanged.

### Retry before escalate

In `AgentRuntime` tool loop:

- When a result is `ToolError` with `error_code ∈ {invalid_arguments,
  not_found, site_unresolved, unlicensed_argument, price_superseded}`, the
  tool message fed to the model is `display` **plus** the `recovery.hint`
  and, if present, `recovery.tool` on a `Recovery:` line. Today `display` is
  a one-liner and the hint stays in the trace.
- Track `consecutiveFailures[tool_key]` within the turn. The model may retry;
  the runtime only converts to a handoff when the same tool has failed
  `config('agents.max_tool_retries')` (default 2) times in a turn, reason
  `error`, `trigger_source = rule`.
- `EscalateTool` with `reason: error` invoked by the model **while a retry
  budget remains** is answered with a `ToolError(invalid_arguments)` whose
  hint says a retry is available, and is **not** persisted as a handoff.
  Model-initiated escalation for any other reason is unchanged.

### Prompt

`SalesAgentDefinition::roleParagraph`: replace the sentence about
`quoted_price_id` with: *"After the prospect agrees, call
`sales.create_offer` with the same `site_id`, `unit_class_id`,
`quoted_price_id` and `quoted_tax_rate_id` you used in `sales.propose_offer`.
If a tool returns a Recovery line, follow it before escalating."*

## Acceptance criteria

- [ ] `SalesCreateOfferTool` schema has no `unit_class_rate_id`; a call with
      `site_id + unit_class_id + quoted_price_id` creates an offer whose
      option references the resolved rate and the quoted price row.
- [ ] Mismatched site between deal and option → `invalid_arguments`, no write,
      no quota consumed.
- [ ] Runtime test: first `invalid_arguments` returns to the model with a
      `Recovery:` line; second consecutive failure of the same tool → handoff
      `error`, `trigger_source = rule`; a model `agent.escalate(error)` between
      them is refused and not persisted.
- [ ] Replay of the S26 fixture ends with `sales.create_offer` `status = ok`,
      `offers.source = ai_agent`, zero handoffs.
- [ ] `PriorCatalogueQuote` tests unchanged and green.

## Out of scope

- Sending the offer (D-AI-10). S26-07 sends *replies*, not offers.
- Promoting `sales.create_reservation` out of `propose` (D-AI-11 bar).
