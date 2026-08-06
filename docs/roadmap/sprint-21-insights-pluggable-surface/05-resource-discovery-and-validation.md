# S18-05 — Resource discovery and save-time validation

## Context

Without this task, defining a report means an operator typing a numeric resource id and a
param slug from memory into two free-text boxes, and discovering the typo as a blank iframe
three screens later. That is the difference between a feature that ships and a feature that
gets a support ticket per use.

There is also a correctness problem that only the provider can answer. Metabase will not
honour a locked parameter unless **that dashboard has been published for embedding and that
specific parameter is set to Locked on the Metabase side.** A param we lock in our token but
which Metabase has marked editable or disabled is either ignored or an error. We cannot fix it
from here; we can only detect it and tell the operator exactly what to change.

Catching that at save time rather than at view time is most of the difference between this
feature feeling finished and feeling broken.

## Scope

**In:**
- `ListsResources` implemented for Metabase (dashboards + questions)
- `DescribesResourceParams` implemented for Metabase (slugs, types, embedding mode)
- Discovery endpoints, cached briefly
- Save-time validation of a report definition against the live provider
- A re-check command and a stale-definition indicator

**Out:**
- Panel pickers (task 06 consumes these endpoints)
- Auto-fixing the provider side — we never mutate a customer's Metabase

## Schema changes

```sql
ALTER TABLE insight_reports
    ADD COLUMN last_validated_at TIMESTAMP NULL,
    ADD COLUMN validation_status VARCHAR(24) NOT NULL DEFAULT 'unknown',
        -- unknown | valid | resource_missing | param_mismatch | unreachable
    ADD COLUMN validation_detail JSONB NULL;
```

This is a cache of an external system's state, not derived state of our own — the distinction
matters for invariant 5 and is worth a comment on the migration. It exists so the settings
list can show a warning triangle without hitting the provider for every row on page load.

## Implementation notes

### Discovery

`MetabaseProvider::resources(string $kind)` returns
`Array<{ ref, name, collection?, enabled_for_embedding: bool }>` for `dashboard` and
`question`. Read from the instance's dashboard and card listing endpoints using the stored
API key. Confirm endpoint paths and the API-key header name against the target instance
before writing the calls — do not trust this file for the exact strings.

`MetabaseProvider::resourceParams(string $kind, string $ref)` returns
`Array<{ slug, name, type, embedding_mode }>` where `embedding_mode` is one of
`disabled | enabled | locked`, read from the resource's declared parameters and its
embedding-params map. `disabled` means the parameter exists in the dashboard but is not
exposed to embeds at all.

Cache both for 60 seconds keyed by account id, with an explicit `?refresh=1` bypass. Long
caching here is a trap: the operator is usually editing the dashboard in another tab, and a
stale list is exactly what makes them distrust the picker.

Both methods must degrade: an unreachable instance or an unreadable credential returns a
typed failure the endpoint maps to 409, never a 500 and never an empty list. An empty list and
a failed call look identical in the UI and mean opposite things.

### Validation

`App\Support\Insights\ReportValidator::validate(InsightReport $r): ValidationResult`, run:

- on `POST` and `PATCH` of a report, **before** the transaction commits
- on demand via `POST /api/settings/insight-reports/{id}/validate`
- for every non-archived embedded report by `php artisan insights:validate` (schedule daily)

Checks, in order, stopping at the first failure:

1. **Account usable** — not archived, credentials readable, status not `error`.
2. **Resource exists** and is published for embedding → else `resource_missing`.
3. **Every configured param slug exists** on the resource → else `param_mismatch`, listing the
   unknown slugs.
4. **Binding agrees with the provider's embedding mode:**
   - our `locked` requires provider `locked`
   - our `default` requires provider `enabled`
   - provider `disabled` is always a failure
   → else `param_mismatch`, listing each slug with both modes and the one-line instruction
   ("set `site_id` to Locked in the dashboard's embed settings").
5. **Type agreement** — a `DynamicParams` key declaring `array<int>` may not bind to a scalar
   param, and vice versa.
6. **Required params covered** — every provider param marked required has a binding.

A failure on save is a **422 that blocks the write**, with `validation_detail` in the body.
Do not save a knowingly broken definition with a warning; the operator will not come back to
it, and the failure will surface to a different person a week later with no context.

The one exception: `unreachable`. If the provider is down at save time, allow the save with
`validation_status = 'unreachable'` and a visible warning. Blocking configuration on a
third-party's uptime is worse than a warning triangle.

### Stale definitions

The daily `insights:validate` run updates `validation_status`. When a report that was `valid`
becomes anything else, write one Tier-2 activity row (`insight.report.validation_failed`,
channel `facility` unless task 08 adds an `insights` channel) so there is a trail of when the
customer's dashboard changed under us. Do not spam: only log transitions, not every run.

## API surface

```
GET  /api/settings/analytics-accounts/{id}/resources?kind=dashboard[&refresh=1]
       → Array<{ ref, name, collection, enabled_for_embedding }>
GET  /api/settings/analytics-accounts/{id}/resources/{kind}/{ref}/params
       → Array<{ slug, name, type, embedding_mode }>
POST /api/settings/insight-reports/{id}/validate
       → { status, detail, validated_at }
```

Both discovery endpoints return 409 (`provider_unreachable`, `credentials_unreadable`,
`provider_not_discoverable`) rather than an empty array on failure. The last of those is what
a provider lacking `ListsResources` returns — the panel uses it to fall back to free-text
entry.

`GET /api/settings/insight-reports` gains `validation_status` and `last_validated_at` per row.

## Panel surface

Nothing in this task; task 06 builds the pickers on top of these endpoints. The one thing to
specify now so task 06 has it: the params response is what drives the param editor's rows —
the operator picks a binding for a **known** slug, they never type a slug.

## Invariants

> **26 / 27** — discovery calls decrypt credentials to make an outbound request; nothing about
> that credential enters the response, the cache key, or the failure message. Assert the
> failure body for a bad API key does not echo the key.

> Advanced-filter convention (`09`, API section) — never trust a client-supplied identifier
> that reaches data access. `kind` is validated against the adapter's `resourceKinds()`;
> `ref` is passed through to the provider only, never into a query on our side.

Provider state is not ours: **we never write to the customer's analytics instance.** No
auto-publishing a dashboard, no flipping a param to locked, no creating collections. Detect
and instruct only.

## Acceptance criteria

- [ ] The dashboard picker endpoint returns real dashboards from a live Metabase, with
      `enabled_for_embedding` correct for both a published and an unpublished dashboard.
- [ ] The params endpoint returns each param's `embedding_mode`, distinguishing `locked`,
      `enabled` and `disabled`.
- [ ] Saving a report that locks a param Metabase reports as `enabled` is **refused with 422**
      and a message naming the param and the required change.
- [ ] Saving a report against an unpublished dashboard is refused with `resource_missing`.
- [ ] Saving while the provider is unreachable succeeds with `validation_status = unreachable`
      and a warning, not a block.
- [ ] Binding an `array<int>` dynamic key to a scalar param is refused.
- [ ] A provider without `ListsResources` returns `provider_not_discoverable`, and the panel
      contract for free-text fallback is documented.
- [ ] Discovery failure returns 409, never an empty 200.
- [ ] `insights:validate` updates all embedded reports and logs only status transitions.
- [ ] A bad API key's error body contains no part of the key.

## Tests required

| Test | Asserts |
|---|---|
| `ResourceDiscoveryTest::lists_dashboards_and_questions` | Against a faked HTTP client |
| `ResourceDiscoveryTest::failure_returns_conflict_not_empty` | 409, not `[]` |
| `ResourceDiscoveryTest::error_body_omits_credentials` | No key echo |
| `ReportValidatorTest::locked_binding_requires_provider_locked` | The core mismatch case |
| `ReportValidatorTest::default_binding_requires_provider_enabled` | Symmetric case |
| `ReportValidatorTest::disabled_param_always_fails` | Third mode |
| `ReportValidatorTest::unknown_slug_is_param_mismatch` | Typo path |
| `ReportValidatorTest::type_mismatch_rejected` | `array` into scalar |
| `ReportValidatorTest::unreachable_provider_allows_save_with_warning` | Documented exception |
| `ReportValidatorTest::save_is_blocked_on_mismatch` | 422, nothing written |
| `InsightsValidateCommandTest::logs_only_transitions` | No log spam |
