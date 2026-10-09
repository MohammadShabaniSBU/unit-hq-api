# S30-05 — Segments & marketing filter fields

## Context

Advanced filters exist (`FilterBuilder`, `FilterTreeValidator`) but trees aren't
persisted, and the contact filter fields (`FilterableFields::contact()`) only cover
contact columns. Marketing needs relationship fields — "tenants at Madrid Centro",
"lost leads who wanted 10 m²".

## Schema

```sql
CREATE TABLE segments (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    description TEXT NULL,
    entity_type VARCHAR(24) NOT NULL DEFAULT 'contact',   -- contact only for now
    filter JSONB NOT NULL,                                -- FilterTreeValidator shape
    is_shared BOOLEAN NOT NULL DEFAULT TRUE,              -- false = personal saved view
    created_by BIGINT NULL REFERENCES employees(id) ON DELETE SET NULL,
    archived_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ, updated_at TIMESTAMPTZ
);
```

`filter` validated by `FilterTreeValidator` on **write and on read** (a field may be
removed later — same reasoning as the analytics I3 rule). Invalid stored trees surface
as `segment.invalid` with the failing field, never as a silent empty result.

Segments are evaluated live. Campaigns (S31) snapshot the tree at scheduling.

## New contact filter fields

Implemented as `FilterableField`s whose `apply` uses `whereExists` subqueries:

| Key | Type | Meaning |
|---|---|---|
| `site_id` | select (sites) | Contact's business site (reuse `SubjectSite::contactBusinessSite` logic as a query) |
| `has_active_contract` | boolean | Contract in `active` or `notice_given` at any / selected site |
| `contract_unit_class_id` | select | Active contract's unit class |
| `contract_started_at` / `contract_ended_at` | date | For win-back ("ended in the last 12 months") |
| `is_delinquent` | boolean | Has an open `delinquencies` row (default campaign exclusion in S31) |
| `deal_status` | select | Any deal in status (open/won/lost) |
| `deal_lost_at` | date | |
| `deal_desired_unit_class_id` | select | |
| `has_channel` | select (email/sms/whatsapp) | Has a usable address on that channel |
| `consent` | composite `{list_id, channel}` | Currently granted (via `ConsentState`) |

The contact list page gets them too — this lands "saved views" for contacts.

## API

| Method | Path | Permission |
|---|---|---|
| `GET/POST/PUT` | `segments[/{id}]` | `MarketingView` / `MarketingManage` (personal views: `ContactView`, own only) |
| `POST` | `segments/{id}/archive` | `MarketingManage` |
| `POST` | `segments/preview` | `MarketingView` — `{filter}` or `{segment_id}` → `{count, sample: first 20 contacts}` |

All counts/samples apply `Contact::visibleTo($employee, Permission::ContactView)`
(D-RBAC-1): a site manager's segment of "all tenants" counts only their sites.

## Panel

- Contacts page: "Save view" / saved views dropdown (personal + shared).
- Marketing → Audience → Segments: list with live counts, builder reusing the advanced
  filter UI, preview sample.

## Acceptance criteria

- [ ] Each new field table-tested against seeded demo data.
- [ ] Stored tree with a removed field → 422 `segment.invalid` on use.
- [ ] Preview count differs correctly between a company-wide and a site-scoped employee.
- [ ] Preview on 50k contacts < 1 s on Postgres (EXPLAIN in PR).
