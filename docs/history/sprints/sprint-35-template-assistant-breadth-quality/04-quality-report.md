# S35-04 — Quality report

## Context

Evals say whether the assistant can do the job; production data says whether
operators keep what it does. Undo and conflict rates are the honest signals.

## Scope

**In:** native Insights report `template-assistant`.
**Out:** per-employee leaderboards; alerts.

## Schema changes

None.

## Implementation notes

- Register in `App\Support\Insights\NativeReports`, section `operations`, Owner-only
  visibility by default; `php artisan insights:sync` adds the row (no reseed).
- Definitions (add to `report-definitions.md`, EN + ES):

  | Term | Definition |
  |---|---|
  | Turns | Turns with status ≠ `running` in range |
  | Undo rate | `undone` ÷ (`applied` + `undone`) |
  | Conflict rate | `conflict` ÷ completed turns |
  | Failure rate | `failed` ÷ turns, broken down by `error_key` |
  | Turns per published version | Applied turns ÷ versions published with `ai_assisted = true` |
  | Median latency | Median `ai_usage_events.duration_ms` for purpose `template_assist` |
  | Cost | Estimated cost from `ai_usage_events` × `ai_model_prices`, **per currency**, never summed across (invariants 48, 30) |

- Breakdown by `prompt_version` and model, so a prompt change is visible as a step.
- Bounded live queries (harvest principle); no rollup table.

## API surface

Through the existing native report endpoint pattern.

## Panel surface

Report renders in Insights with KPI cards, a weekly trend (turns vs undo rate) and a
failure-reason table. i18n en/es/fr.

## Invariants

- **48** — cost is an estimate, labelled as such, per currency.

## Acceptance criteria

- [ ] Fixture with known turn statuses reproduces each rate exactly.
- [ ] Cost never sums two currencies.
- [ ] `insights:sync` adds the report on an existing database without a refresh.

## Tests required

| Test | Asserts |
|---|---|
| `TemplateAssistantReportTest::rates_from_fixture` | Definitions |
| `TemplateAssistantReportTest::cost_per_currency` | No cross-currency sum |
| `InsightsSyncTest` (existing) | New key inserted once |
