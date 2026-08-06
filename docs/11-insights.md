# Insights — registry, embeds, and the analytics contract

Insights is a **registry**, not a fixed set of Nuxt pages. Reports come from two
sources — native app pages and dashboards/questions embedded from an analytics
provider — and both are rows in the same table, reorderable and hideable from
Settings → Insights.

Metabase is the default provider; the adapter interfaces exist so a customer who
already runs Superset, Looker Studio, or Power BI can point Insights at their
own instance. The shape deliberately mirrors `communication_accounts` (see
`06-communications.md`): provider-agnostic credential rows, capability-by-interface
adapters, encrypted credentials, masked responses, Tier-3 audit on
create / rotate / remove.

**How to add a report**

1. **Native** — add an entry to `App\Support\Insights\NativeReports`, ship the
   panel component keyed by `native_key`, re-run the seeder
   (`php artisan insights:check` catches mismatches). Do not invent a new
   hard-coded Insights route.
2. **Embedded** — Settings → Insights → Reports → create against a connected
   analytics account; bind params; save. The nav picks it up from
   `GET /api/insights`.

Native formula vocabulary lives in `report-definitions.md`. Column-level
`analytics.*` catalogues live in `analytics-schema.md`.

---

## The registry

One table, two sources, operator-ordered.

| Concept | Notes |
|---|---|
| `insight_reports.source` | `native` \| `embedded` |
| Nav | `GET /api/insights` — non-archived, visibility-filtered, ordered by `sort_order` |
| Labels | Operator-created: per-locale JSONB on `labels`. Native: i18n via `native_key` (I4) |
| Site scope | `site_scope_mode`: `inherit` (default) \| `ignore` (I6) |
| Visibility | Enum stub `all` \| `company_only` \| `site_staff`; only `all` honoured until RBAC (I5) |
| System rows | Seeded natives (`is_system`): reorder, hide, relabel OK; never archive or repoint |

There is no second “integrations” nav and no hard-coded Insights page list in the
panel. The route is `/insights/[key]`: native keys resolve through
`app/insights/registry.ts`; embedded keys mint via `POST /api/insights/{key}/embed`.

---

## `analytics_accounts` and credentials

Company-scoped (not per-site). Providers today: `metabase` \| `iframe`. One
default account (partial unique where `is_default AND archived_at IS NULL`).
Archive-only — `DELETE` aliases archive.

| Field | Notes |
|---|---|
| `provider` | Adapter key |
| `display_name`, `base_url` | Not secrets; `base_url` is used for API calls without decrypting |
| `credentials` | `encrypted:array` — Metabase holds `embedding_secret_key` + `api_key`; iframe may be `{}` |
| `connection_status` | `pending` \| `connected` \| `error` |

**Credential rules are shared** with communications and payments — invariants 26
and 27 apply unchanged. Masking, blank-means-unchanged, Tier-3
`RecordsActivity::core` on create / rotate / remove, and
`DecryptException` → `credentials_unreadable` all live in
`App\Support\Credentials\`. Do not reimplement them here; see
`06-communications.md`.

---

## Capability-by-interface

Adapters do **not** expose a `capabilities()` boolean map. Capability is
`instanceof` on interfaces under `App\Support\Insights\Contracts\`:

| Interface | Methods | Purpose |
|---|---|---|
| `AnalyticsProvider` | `make`, `verify`, `credentialFields`, `resourceKinds` | Every adapter |
| `SignsEmbedTokens` | `embedUrl(InsightReport, array $resolvedParams): string` | Token minting / URL construction |
| `ListsResources` | `resources(string $kind): array` | Discovery picker |
| `DescribesResourceParams` | `resourceParams(string $kind, string $ref): array` | Param schema at save time |

| Provider | Interfaces |
|---|---|
| Metabase | All four |
| iframe | `AnalyticsProvider` + `SignsEmbedTokens` only (free-text `resource_ref`; no discovery) |

The panel shows a browse picker only when the account’s adapter implements
`ListsResources`. `lists_resources` / `describes_params` are derived per request
on `GET /api/settings/analytics-providers`.

---

## Params

Each `insight_report_params` row binds a provider-side slug to a value source:

| Field | Values |
|---|---|
| `value_source` | `static` \| `dynamic` |
| `binding` | `locked` \| `default` |
| `static_value` / `dynamic_key` | Exactly one, matching `value_source` |

**A dynamic param is always locked.** Enforced by a database `CHECK`:
`value_source <> 'dynamic' OR binding = 'locked'`. Only static params may be
editable defaults (filter widgets). Dynamic values are site scope and viewer
identity — an editable dynamic param is a URL-editing privilege escalation (I2).

Dynamic keys resolve from the `App\Support\Insights\DynamicParams` whitelist
only — never from a client-supplied key, never from an expression language (I3):

| Key | Type | Resolves to |
|---|---|---|
| `current_site_id` | `int` | Global site selector (null on “All sites”) |
| `visible_site_ids` | `array<int>` | Sites the employee may see for reports |
| `current_employee_id` | `int` | Authenticated employee |
| `site_currency` | `string` | Selected site currency |
| `site_timezone` | `string` | Selected site IANA timezone |
| `today` | `date` | Today in the **site** timezone |
| `month_start` / `month_end` | `date` | Current month bounds, site TZ |
| `year_start` | `date` | Year start, site TZ |
| `locale` | `string` | Request locale |

Types live in code, not the database. Save-time validation
(`ReportValidator`) refuses type mismatches, unpublished / unlocked provider
params, and missing required bindings — blocked with 422, not discovered as a
blank iframe. `validation_status` on the report caches that **external** check;
it is not derived business state (see invariant 5).

---

## Embed flow

`POST /api/insights/{key}/embed` — seven steps:

1. Resolve the report by key; 404 if archived or invisible; authorize with the
   same helper as `GET /api/insights`.
2. Native source → 400 (`report_is_native`). Native reports do not mint tokens.
3. Account archived or `credentials_unreadable` → 409.
4. Resolve every param: static → `static_value`; dynamic →
   `DynamicParams::resolve`. Required null → 422 with the param name
   (fail closed on “All sites” when `current_site_id` is required).
5. Split by binding: locked → signed into the token; default → query params.
6. `adapter->embedUrl(report, resolved)` → URL.
7. Return `{ url, expires_at }`.

TTL ≤ 10 minutes (configurable downward only). The provider’s signing secret
never appears in an API response, queue payload, activity log, system event,
exception message, or the panel bundle. The panel receives a URL and renders
it; it never constructs one. Remint on expiry−60s and on site change when
`site_scope_mode = inherit`.

---

## The `analytics` read schema as a contract

A small Postgres schema of views that encode reporting invariants once, plus a
DB role that can see nothing else. **Nothing in `app/` may query `analytics`.**
Application code owns the DDL and `php artisan analytics:refresh` only.
Consumers are external BI tools via `metabase_ro` (operator runbook in
`docs/ops/`). Column catalogues: `analytics-schema.md`.

| Invariant | What breaks against raw tables |
|---|---|
| 19 + D3 — `deposit` and `write_off` are not revenue | `SUM(charges.amount)` overstates revenue |
| Exclusive tax | `charges.amount` is gross; revenue is `net_amount` |
| D1 — currency resolves per site | `SUM(amount)` adds euros to pounds silently |
| 5 — availability is derived | Occupancy scanned off `contract_items` instead of `unit_occupancies` |
| Per-site `timezone` | “Revenue in July” resolves against the wrong day boundary |
| 18 — billing snapshots | Cadence read from current `BillingSettings` instead of the contract |
| 3 — ledger is append-only | Reversal rows double-counted |

Objects that ship:

| Object | Role |
|---|---|
| `analytics.v_revenue` | Revenue-bearing charges, net of reversals; exposes `currency` |
| `analytics.v_payments` | Payments / allocations, same reversal treatment |
| `analytics.v_rent_roll` | Open occupancies as of today |
| `analytics.mv_unit_state_daily` | Materialized date spine × unit state |
| `analytics.v_pipeline_events` | Pipeline transitions for funnel / conversion |

Every monetary view exposes `currency`. Never sum across currencies. There is
no conversion layer.

---

## What stays native

Embedded analytics does not replace everything. These stay in the app:

1. **Fiscal documents** — Verifactu registros, invoice books, AEAT submissions,
   SDD run and returns reconciliation. A gestor asking where a number came from
   must get a `registro`, not a dashboard someone edited last Tuesday.
2. **Tenant-facing figures** — Statements are computed views in the app. A BI
   export must never become the document a customer receives.
3. **Operational reads inside transactional screens** — contract balance banners,
   the unit class occupancy matrix, `?available=1`. These are permission-scoped
   and sub-100ms; an iframe is the wrong tool and the wrong latency.

---

## Table index

| Table / object | Role |
|---|---|
| `analytics_accounts` | Provider credentials + connection status (company-scoped) |
| `insight_reports` | Registry row — native or embedded; nav order, labels, scope |
| `insight_report_params` | Param bindings per report (static/dynamic × locked/default) |
| `analytics.v_revenue` | Reporting contract — revenue charges |
| `analytics.v_payments` | Reporting contract — payments |
| `analytics.v_rent_roll` | Reporting contract — open rent roll |
| `analytics.mv_unit_state_daily` | Reporting contract — daily unit state (refreshed) |
| `analytics.v_pipeline_events` | Reporting contract — pipeline funnel events |
