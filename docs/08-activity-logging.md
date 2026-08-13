# Activity & Event Logging

Three logging tiers on two tables:

| Tier | Name | Storage | Audience | Retention | Configurable |
|---|---|---|---|---|---|
| 1 | Trace log | `system_events` | Developers | 60–90 days (partition drop) | No |
| 2 | Optional business channels | `activity_log` (`log_name` ≠ `core`) | Operators | Default 12 months (3–60) | Yes — Settings → Activity log |
| 3 | Core audit events | `activity_log` (`log_name = core`) | Operators / compliance | Indefinite | No — always on |

## Architecture rules

- **Two tables, three tiers.** Tiers 2+3 share `activity_log`, distinguished by `log_name` (`LogChannel` enum).
- **Write everything, filter at read time.** Tier-2 toggles affect `GET /api/activities` and pruning only — never the write path.
- **Correlation id.** `AssignRequestId` middleware + queue payload restore. Stamped on every tier-1 row and into `properties.request_id` on every activity row.
- **Append-only.** No update/delete API for logs. Carve-out: GDPR redaction may null JSON keys (`contacts:redact`).
- **Tier-3 events are logged explicitly** inside the same DB transaction as the business op (`RecordsActivity::core`). Never via model observers for core events.
- **Tier-2 attribute diffs** use Spatie `LogsActivity` via `LogsDirtyActivity` on Contact, ContactChannel, Deal (fillable diffs; `status` excluded — stage changes are Tier-3), Unit, UnitClass, Insurance, Discount, TaxRate.
- **Tier-1 writes never fail business code.** `SystemEvent::record` uses `DB::afterCommit` + try/catch + `report()`.

## Channels (`LogChannel`)

| Value | Tier | Notes |
|---|---|---|
| `core` | 3 | Always on |
| `crm` | 2 | Contact / ContactChannel / Deal diffs; attribute value upserts on CRM entities |
| `facility` | 2 | Unit / UnitClass / Insurance / Discount diffs; `rate.changed`; `rate.tax.versioned`; object-customization layout mutations; attribute value upserts on Unit |
| `comms` | 2 | `offer.sent` |
| `billing` | 2 | Billing run events (`billing.run.completed`); reserved for further billing attribute/events |

Tier-1 (system_events): `billing.contract.failed` — per-contract failure inside a billing run
(isolated; run continues).

## Event naming

- Tier 1: `domain.action.phase` — e.g. `offer.accept.started`, `offer.accept.committed`
- Tier 2/3 `description`: machine key — e.g. `deal.stage_changed`, `contract.signed`. Panel translates via i18n. Money in properties as strings.

### Contract discount / rate-change (Tier-3)

| Event | Subject | Properties (money as strings) |
|---|---|---|
| `contract.rate_scheduled` | Contract | `new_amount`, `effective_date`, …; when discounted also `list_amount`, `contract_amount`, optional `percent` |
| `contract.discount_removed` | Contract | `list_amount`, `previous_amount`, `effective_date` (next boundary), `reason`, `discount_id` |

### Custom attributes & object customization (Tier-2)

| Event | Channel | Subject | Notes |
|---|---|---|---|
| `attribute.value.updated` / `attribute.value.cleared` | `AttributeEntityType::activityChannel()` (`crm` except Unit → `facility`) | **Parent entity** (Contact/Deal/…) | Properties: `definition_id`, `key`, `old`, `new` |
| `attribute.definition.created` / `.updated` / `.archived` / `.unarchived` | entity channel | `AttributeDefinition` | Definitions are archive-only (never hard-deleted) |
| `layout.group.created` / `.updated` / `.reordered` / `.deleted` | `facility` | `AttributeGroup` (or null for reorder) | Org settings / config |
| `layout.field.added` / `.moved` / `.reordered` / `.removed` | `facility` | `LayoutField` or group | Properties include `entity_type` |

## API

- `GET /api/activities` — filters: `subject_type`+`subject_id`, `log_name[]`, causer, date range. Paginated. Hides disabled tier-2 channels unless `include_disabled=1` and caller is a `User` (superadmin).
- `GET/PATCH /api/settings/activity-log` — `{ channels, retention_months }`
- No POST/PUT/DELETE on activities.

## Pruning

- `system-events:maintain` (daily) — tier 1
- `activitylog:prune-tiers` (daily) — each optional channel by retention; **never** `core`

## Related surfaces (not these tables)

- **Interaction** — CRM comms timeline (`06-communications.md`)
- **Notes** — append-only operator comments
- **`stripe_webhook_events`** — Stripe idempotency only; new rows carry `site_id` (legacy may be null)
