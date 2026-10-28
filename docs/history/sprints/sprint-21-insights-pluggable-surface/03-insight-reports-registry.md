# S18-03 — `insight_reports` registry and params

## Context

The Insights nav is currently a hardcoded list of routes. Twelve reports, each a Nuxt page
and an API endpoint, ordered by whoever wrote the last one. An operator cannot reorder them,
cannot hide the ones they never use, and cannot add anything.

This task turns the nav into data. Native reports become rows pointing at registered
components; embedded reports become rows pointing at a provider resource. One table, one
ordering, one visibility rule (I1).

The params model is the part to get right. It is small and it is the authorization boundary.

## Scope

**In:**
- `insight_reports` + `insight_report_params` tables
- `NativeReports` registry mapping `native_key` → route/component, with a seeder for the
  twelve existing reports
- Label resolution across native i18n keys and operator JSONB (I4)
- Reorder, archive, unarchive
- `GET /api/insights` — the nav feed

**Out:**
- Param resolution and token minting (task 04)
- Provider-side validation of param bindings (task 05)
- Panel (tasks 06–07)

## Schema changes

```sql
CREATE TABLE insight_reports (
    id                   BIGSERIAL PRIMARY KEY,
    key                  VARCHAR(64) NOT NULL,          -- 'rent-roll'; URL segment
    source               VARCHAR(16) NOT NULL,          -- native | embedded
    native_key           VARCHAR(64) NULL,              -- registry key when native
    analytics_account_id BIGINT NULL REFERENCES analytics_accounts(id),
    resource_kind        VARCHAR(16) NULL,              -- dashboard | question
    resource_ref         VARCHAR(64) NULL,              -- opaque provider-side id
    labels               JSONB NULL,                    -- { "en": "...", "es": "..." }
    description          JSONB NULL,
    icon                 VARCHAR(48) NULL,
    section              VARCHAR(48) NULL,              -- optional nav grouping
    sort_order           INTEGER NOT NULL DEFAULT 0,
    visibility           VARCHAR(16) NOT NULL DEFAULT 'all',
    site_scope_mode      VARCHAR(16) NOT NULL DEFAULT 'inherit',  -- inherit | ignore
    options              JSONB NOT NULL DEFAULT '{}',   -- bordered, titled, theme, downloads
    is_system            BOOLEAN NOT NULL DEFAULT false,
    archived_at          TIMESTAMP NULL,
    created_by           BIGINT NULL REFERENCES employees(id),
    created_at           TIMESTAMP,
    updated_at           TIMESTAMP,

    CHECK (source <> 'native'   OR native_key IS NOT NULL),
    CHECK (source <> 'embedded' OR (analytics_account_id IS NOT NULL
                                    AND resource_kind IS NOT NULL
                                    AND resource_ref  IS NOT NULL))
);

CREATE UNIQUE INDEX insight_reports_key_idx ON insight_reports (key)
    WHERE archived_at IS NULL;
CREATE INDEX insight_reports_order_idx ON insight_reports (sort_order, id);

CREATE TABLE insight_report_params (
    id                BIGSERIAL PRIMARY KEY,
    insight_report_id BIGINT NOT NULL REFERENCES insight_reports(id) ON DELETE CASCADE,
    name              VARCHAR(64) NOT NULL,     -- provider-side param slug
    value_source      VARCHAR(16) NOT NULL,     -- static | dynamic
    static_value      JSONB NULL,
    dynamic_key       VARCHAR(64) NULL,         -- whitelisted resolver key
    binding           VARCHAR(16) NOT NULL DEFAULT 'locked',  -- locked | default
    is_required       BOOLEAN NOT NULL DEFAULT true,
    sort_order        INTEGER NOT NULL DEFAULT 0,
    created_at        TIMESTAMP,
    updated_at        TIMESTAMP,

    UNIQUE (insight_report_id, name),
    CHECK (value_source <> 'dynamic' OR binding = 'locked'),
    CHECK (value_source <> 'dynamic' OR dynamic_key IS NOT NULL),
    CHECK (value_source <> 'static'  OR static_value IS NOT NULL)
);
```

**The `value_source <> 'dynamic' OR binding = 'locked'` CHECK is the security invariant**
(I2). It is in the database and not only in a form request because the form request is one
refactor away from being bypassed and this constraint is not.

`ON DELETE CASCADE` on params is deliberate and is not a violation of the archive-only rule —
reports are archived, never deleted, so the cascade only fires in tests and in a hard cleanup.

`is_system` marks the twelve seeded native rows. System rows can be reordered, hidden and
relabelled but not archived and not repointed; that keeps `native_key` from being edited into
a dangling reference.

## Implementation notes

### Native registry

`App\Support\Insights\NativeReports` — a static map, no state:

```php
'rent-roll' => [
    'label_key' => 'insights.reports.rent_roll.label',
    'icon'      => 'i-lucide-scroll-text',
    'section'   => 'operations',
],
// dashboard, occupancy, ageing, collections, deposit-liability,
// daily-close, movement, funnel, demo-report, …
```

`InsightReportSeeder` inserts one `source = 'native'`, `is_system = true` row per registry
entry, in the current nav order, with `labels = NULL`. The seeder is idempotent — keyed on
`native_key`, it inserts what is missing and never overwrites operator edits to
`sort_order`, `labels` or `visibility`. A future native report ships by adding a registry
entry and re-running the seeder.

A registry entry that has no row and a row whose `native_key` is not in the registry are both
detectable; expose the mismatch through `php artisan insights:check` rather than failing a
boot.

### Label resolution (I4)

One resolver, `InsightReport::resolveLabel(string $locale): array`, returning
`{ label, source: 'operator'|'i18n' }`:

```
operator: labels->>locale ?? labels->>'en'          (when labels is not null)
i18n:     t("insights.reports.{native_key}.label")  (resolved panel-side)
```

The API returns either a resolved string or an i18n key plus a flag, never a half-resolved
mixture. The panel must not guess which it received.

Operator labels are validated: at least one locale present, each value ≤ 120 chars, keys
restricted to configured locales (`en`, `es`, `fr`). An operator who fills only `es` gets `es`
everywhere — falling back to a missing `en` and rendering blank is worse than rendering
Spanish to an English user.

### Ordering

`POST /api/insights/reports/reorder` takes the full ordered array of ids and rewrites
`sort_order` in one transaction. Do not accept individual up/down deltas — concurrent
reorders produce duplicate positions and the list stops being deterministic.

### `GET /api/insights` is the nav feed

Returns non-archived reports the caller may see, ordered, each with resolved label, icon,
section, `source`, `site_scope_mode`, and for embedded rows the account's
`connection_status`. It does **not** return `resource_ref`, `analytics_account_id`, or
anything about params — the nav has no need for them and they are settings-surface data.

Filter by `visibility` here (I5: only `all` is honoured this sprint, but the filter call goes
through the existing `SiteAccess` / role helper so there is exactly one place to change when
RBAC lands — do not extend the `canEdit` stopgap).

## API surface

```
GET    /api/insights                              nav feed (see above)
GET    /api/settings/insight-reports              ?status=active|archived|all
POST   /api/settings/insight-reports              full definition incl. params
GET    /api/settings/insight-reports/{id}
PATCH  /api/settings/insight-reports/{id}         params replaced wholesale, not merged
POST   /api/settings/insight-reports/reorder      { ids: Array<int> }
POST   /api/settings/insight-reports/{id}/archive
POST   /api/settings/insight-reports/{id}/unarchive
DELETE /api/settings/insight-reports/{id}         aliases archive
```

Params are replaced wholesale on `PATCH`, inside one transaction. Merging param sets by name
produces orphans when an operator renames a provider-side slug, and there is no version of
that behaviour anyone can reason about.

## Panel surface

Nothing in this task. Tasks 06 and 07.

## Invariants

> **32. Insight reports and analytics accounts are archive-only** (S18-00 draft) —
> `archived_at`, `POST …/archive` / `…/unarchive`, `DELETE` aliases archive. Same as sites
> (28), automations (23) and attribute definitions (21).

> **30. A dynamic insight param is always locked** (S18-00 draft) — enforced by the `CHECK`
> constraint above, not by the form request alone.

Panel convention: all shipped strings through i18n. Operator-authored labels are data and are
the deliberate, documented exception (I4) — note it next to the i18n rule so it does not read
as a violation.

## Acceptance criteria

- [ ] Migrations run on SQLite and Postgres; both `CHECK` constraints are enforced on both
      drivers (SQLite supports `CHECK` — do not skip them behind a driver guard).
- [ ] Inserting a `dynamic` param with `binding = 'default'` fails at the database level.
- [ ] The seeder creates one row per native registry entry in the current nav order.
- [ ] Re-running the seeder after an operator reorders and relabels does not overwrite either.
- [ ] `GET /api/insights` returns the twelve native reports and any embedded reports in
      `sort_order`, with labels resolved per the request locale.
- [ ] An operator label in `es` only is returned for an `en` request rather than blank.
- [ ] Reorder rewrites all positions in one transaction; concurrent reorders leave no
      duplicate `sort_order`.
- [ ] A system row cannot be archived and cannot have `native_key` changed — 422 with a
      translatable key.
- [ ] `PATCH` replacing params leaves no orphaned `insight_report_params` rows.
- [ ] `GET /api/insights` response contains no `resource_ref` and no account id.
- [ ] `php artisan insights:check` reports registry/row mismatches in both directions.

## Tests required

| Test | Asserts |
|---|---|
| `InsightReportTest::dynamic_param_must_be_locked` | DB constraint, not just validation |
| `InsightReportTest::native_requires_native_key` | Source `CHECK` |
| `InsightReportTest::embedded_requires_account_and_resource` | Source `CHECK` |
| `InsightReportSeederTest::seeds_all_native_reports` | Twelve rows, correct order |
| `InsightReportSeederTest::rerun_preserves_operator_edits` | Idempotency |
| `InsightReportTest::label_falls_back_across_locales` | I4 resolution order |
| `InsightReportTest::nav_feed_excludes_resource_identifiers` | No leakage into the nav |
| `InsightReportTest::reorder_is_atomic` | No duplicate positions |
| `InsightReportTest::system_report_cannot_be_archived` | 422 |
| `InsightReportTest::patch_replaces_params_wholesale` | No orphans |
| `InsightReportTest::delete_archives_not_deletes` | Row survives |
