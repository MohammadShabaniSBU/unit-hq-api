# AR-03 — Usage metering

## Context

With turns running in a worker (task 02), usage capture no longer depends on a browser
staying connected. This task records token consumption per employee, reliably enough that a
missing measurement is visible rather than silent.

The design principle: **do not depend on the happy path returning a value to the call site.**
A row is written before the provider call and settled after, by an event listener rather than
a closure — so it works identically for `prompt`, `stream`, `queue` and `broadcastOnQueue`,
and for every future feature that prompts an agent without remembering to instrument it.

## Scope

**In:**
- `ai_usage_events` with reserve/settle lifecycle
- `ai_model_prices` — immutable, effective-dated
- Metering middleware and terminal event listener
- Orphan sweeper
- Usage reporting endpoints

**Out:**
- Provider credential settings and model bindings (separate sprint)
- Per-employee token caps and enforcement (follow-up; the schema supports it)
- Provider-side reconciliation (follow-up)
- The Insights panel page (follow-up; this task ships the API)

## Schema changes

```sql
CREATE TABLE ai_usage_events (
    id                  BIGSERIAL PRIMARY KEY,
    call_id             UUID NOT NULL,
    employee_id         BIGINT NULL REFERENCES employees(id),
    conversation_id     VARCHAR(64) NULL,
    purpose             VARCHAR(32) NOT NULL,       -- copilot | summarize | title
    provider            VARCHAR(24) NULL,
    model               VARCHAR(128) NULL,
    status              VARCHAR(16) NOT NULL,       -- started|ok|failed|failed_over|orphaned
    input_tokens        INTEGER NOT NULL DEFAULT 0,
    cached_input_tokens INTEGER NOT NULL DEFAULT 0,
    output_tokens       INTEGER NOT NULL DEFAULT 0,
    reasoning_tokens    INTEGER NOT NULL DEFAULT 0,
    tokens_estimated    BOOLEAN NOT NULL DEFAULT false,
    tool_calls          SMALLINT NOT NULL DEFAULT 0,
    duration_ms         INTEGER NULL,
    request_id          VARCHAR(64) NULL,
    raw_usage           JSONB NULL,
    started_at          TIMESTAMP NOT NULL,
    settled_at          TIMESTAMP NULL,
    created_at          TIMESTAMP,
    updated_at          TIMESTAMP
);

CREATE UNIQUE INDEX ai_usage_call_idx ON ai_usage_events (call_id);
CREATE INDEX ai_usage_employee_idx ON ai_usage_events (employee_id, started_at);
CREATE INDEX ai_usage_model_idx ON ai_usage_events (model, started_at);
CREATE INDEX ai_usage_open_idx ON ai_usage_events (started_at) WHERE settled_at IS NULL;

CREATE TABLE ai_model_prices (
    id                    BIGSERIAL PRIMARY KEY,
    provider              VARCHAR(24) NOT NULL,
    model                 VARCHAR(128) NOT NULL,
    input_per_mtok        NUMERIC(10,4) NOT NULL,
    cached_input_per_mtok NUMERIC(10,4) NULL,
    output_per_mtok       NUMERIC(10,4) NOT NULL,
    currency              CHAR(3) NOT NULL DEFAULT 'USD',
    effective_from        DATE NOT NULL,
    effective_to          DATE NULL,
    created_at            TIMESTAMP,
    updated_at            TIMESTAMP
);

CREATE INDEX ai_model_prices_lookup_idx
    ON ai_model_prices (provider, model, effective_from);
```

**Cost is not a column.** It is `tokens × rate`, derived at read time from the price version
in effect on `started_at`. Never `UPDATE` a rate — insert a new row and close the previous
`effective_to`, exactly as `prices` and `tax_rates` work. Historical reports then stay correct
after a provider repricing.

Two rules to write into `09-conventions-and-invariants.md` alongside the table:

> **`ai_usage_events` is operational telemetry, not the ledger.** It never produces a charge,
> a payment, or a revenue figure, and never uses the `NUMERIC(10,2)` money path. Its `status`
> is deliberately mutable — the reserve/settle lifecycle is not an append-only ledger and
> invariant 3 does not apply to it.

> **Estimated cost never reconciles to the provider invoice.** Retries, failovers, cached
> tokens and rounding all diverge. The figure attributes spend between employees; it is not
> an accounting record and must not be presented as one.

## Implementation notes

### Reserve — agent middleware

```php
namespace App\Ai\Middleware;

class MetersUsage
{
    public function handle(AgentPrompt $prompt, Closure $next)
    {
        AiUsageEvent::reserve(
            callId: Context::get('ai_call_id'),
            employeeId: Context::get('employee_id'),
            purpose: Context::get('ai_purpose', 'copilot'),
            requestId: Context::get('request_id'),
        );

        return $next($prompt);
    }
}
```

Registered via `HasMiddleware` on the agent. Middleware runs in-process in the worker, so it
sees the `Context` propagated at dispatch (task 02) and never needs a session.

### Settle — terminal event listener

Subscribe to the SDK's terminal events — `AgentPrompted` and `AgentStreamed` — rather than a
`then` closure. Two reasons: a queued `then` closure must survive serialisation onto the
queue, and it does not fire on the failure path. A listener fires regardless of which call
style produced the turn.

```php
class RecordAgentUsage
{
    public function handle(AgentPrompted|AgentStreamed $event): void
    {
        AiUsageEvent::settle(
            Context::get('ai_call_id'),
            $event->response->usage,   // promptTokens, completionTokens
            raw: $event->response->usage,
        );
    }
}
```

Store the whole usage object in `raw_usage` as well as the normalised columns. The SDK
normalises to prompt/completion, but provider payloads carry more — cache reads versus cache
writes, reasoning tokens, tiered rates — and the shapes keep changing. This mirrors
`DeliveryEvent::$rawStatus` in the comms domain: normalised field drives logic, raw payload
survives for later. Anything not stored cannot be backfilled.

**Verify the event property names against the installed version** before writing the
listener; dump one event in dev rather than trusting the shape above.

### Failure and orphan paths

- The queued job's `failed()` hook (task 02) settles the row as `failed`.
- `AgentFailedOver` writes a terminal `failed_over` row for the abandoned leg — a
  rate-limited call often still consumed input tokens — and the retry reserves a new
  `call_id`.
- `ai-usage:sweep` (scheduled every 15 minutes) marks rows still `started` after 30 minutes
  as `orphaned`.

The orphan rate is the point of the whole design. It does not recover lost tokens; it makes
undercounting **countable**, so degrading capture is visible on a dashboard before someone
disputes a report.

### Tool call counting

Increment `tool_calls` from a `ToolInvoked` listener keyed on the same `call_id`. Useful for
spotting a model that loops through the filter engine ten times per question — the leading
indicator that a weak model was bound to the copilot purpose.

### Volume

Roughly 50 employees × 50 turns/day ≈ 2,500 rows/day. **Do not build a rollup table or an
incrementing counter.** A `GROUP BY` over an indexed column is fine for years, and a cached
aggregate is exactly the class of thing invariant 5 exists to prevent. If it ever matters,
add a nightly rebuilt derived table with a rebuild command — never an increment.

Retention: 24 months via `ai-usage:prune`, scheduled daily alongside `activitylog:prune-tiers`.

### Privacy

No prompts, completions, tool arguments or tool results go in this table — the conversation
store already holds them. Note the open gap: agent conversations will contain contact names,
emails and balances, and `config/redaction.php` currently covers `activity_log` and
`system_events` only. Extending `contacts:redact` to the conversation message tables is
out of scope here but should be raised in `10-open-decisions.md` as part of this sprint.

## API surface

```
GET /api/insights/ai-usage      ?from&to&group_by=employee|model|purpose|day
GET /api/insights/ai-usage/me   ?from&to
```

Grouped totals include `estimated_cost` and `currency`, joined against the price version in
effect. **Never return a single summed cost across currencies.** Include `orphaned_count` and
`estimated_token_share` in the response meta so a reader can judge how complete the numbers
are.

Authorization: the aggregate endpoint requires a company-level role (`owner`, `admin`,
`finance` per the RBAC slice); `/me` is available to any authenticated employee.

## Invariants

- Invariant 5 — cost is derived at read time, never stored.
- Invariant 2 / 17 pattern — `ai_model_prices` is immutable and effective-dated; new version
  plus `effective_to` closure, never an in-place `UPDATE`.
- Money in this domain does **not** use `NUMERIC(10,2)`; per-call cost is fractions of a cent
  and this is not ledger money. The exemption is explicit and documented above.
- No `app/Services/` — helpers under `App\Support\Ai\`.

## Acceptance criteria

- [x] Every completed turn produces exactly one `ok` row attributed to the correct employee.
- [x] A turn that throws mid-generation produces a `failed` row, not a missing one.
- [x] A turn killed by worker timeout is `orphaned` by the sweeper within 30 minutes.
- [x] A failover produces a `failed_over` row for the first leg and an `ok` row for the second.
- [x] An approval pause and resume produce two rows sharing one `conversation_id`.
- [x] `raw_usage` is populated on every settled row.
- [x] Cost is computed from the price version in effect on `started_at`; editing a price does
      not change historical figures.
- [x] A summarisation call made from anywhere else in the codebase is metered without that
      call site being instrumented.
- [x] `/api/insights/ai-usage` returns per-currency totals and never a mixed-currency sum.
- [x] A non-privileged employee receives 403 on the aggregate endpoint and 200 on `/me`.

## Tests required

| Test | Asserts |
|---|---|
| `AiUsageTest::successful_turn_settles_row` | One `ok` row, correct employee and tokens |
| `AiUsageTest::failed_turn_settles_terminal_row` | `failed`, not missing |
| `AiUsageTest::worker_timeout_leaves_orphan` | Sweeper marks `orphaned` |
| `AiUsageTest::failover_records_both_legs` | Two rows, correct statuses |
| `AiUsageTest::approval_pause_shares_conversation_id` | Two rows, one conversation |
| `AiUsageTest::raw_usage_persisted` | JSON payload stored |
| `AiUsageTest::attribution_survives_queue_boundary` | Employee id resolved without a session |
| `AiUsagePriceTest::cost_uses_price_in_effect_at_started_at` | Historical correctness |
| `AiUsagePriceTest::new_version_closes_previous` | No in-place update |
| `AiUsageReportTest::no_cross_currency_sum` | Grouped per currency |
| `AiUsageReportTest::aggregate_requires_privileged_role` | 403 / 200 split |

The five lifecycle cases — success, mid-stream exception, worker timeout, failover, approval
pause and resume — are the acceptance bar. If all five produce a terminal row, the remaining
gap is provider-side drift, which reconciliation measures later rather than guesses at.
