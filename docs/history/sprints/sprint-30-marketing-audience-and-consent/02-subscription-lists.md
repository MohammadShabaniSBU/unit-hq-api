# S30-02 — Subscription lists

## Schema

```sql
CREATE TABLE subscription_lists (
    id BIGSERIAL PRIMARY KEY,
    key VARCHAR(64) NOT NULL UNIQUE,         -- 'promotions', 'newsletter', 'madrid_centro_offers'
    labels JSONB NOT NULL,                   -- {"en": "...", "es": "...", "fr": "..."} (I4 pattern)
    descriptions JSONB NULL,
    kind VARCHAR(16) NOT NULL,               -- promotions | newsletter
    channels JSONB NOT NULL,                 -- ["email","sms"] allowed for this list
    site_id BIGINT NULL REFERENCES sites(id),-- null = company-wide
    is_public BOOLEAN NOT NULL DEFAULT TRUE, -- shown in preference center
    requires_double_opt_in BOOLEAN NOT NULL DEFAULT TRUE,  -- for form signups (04)
    is_system BOOLEAN NOT NULL DEFAULT FALSE,
    archived_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ, updated_at TIMESTAMPTZ
);
```

Archive-only (no delete): consent events reference lists forever. `key` immutable.

**Seeder** (`SubscriptionListSeeder`, system, idempotent):
`promotions` (kind promotions, email+sms) and `newsletter` (kind newsletter, email).

## Membership

No membership table. Members of a list on a channel = `ConsentState::grantedQuery`.
Counts in the list index are computed (cache 60s per list if needed — cache, not
stored state).

## API

| Method | Path | Permission |
|---|---|---|
| `GET` | `subscription-lists` | `MarketingView` (new) — with member counts per channel |
| `POST/PUT` | `subscription-lists[/{id}]` | `MarketingManage` (new) |
| `POST` | `subscription-lists/{id}/archive` | `MarketingManage`; system lists not archivable |
| `GET` | `subscription-lists/{id}/members` | `MarketingView` — paginated, `scopeVisibleTo` applied to contacts |
| `POST` | `subscription-lists/{id}/import` | `MarketingManage` — CSV (email/phone, name, consent date, original source); writes `import` grants, report of matched / created / rejected rows |

Import matching uses `ContactChannelMatcher`; unmatched rows create contacts only
with an explicit `create_missing = true` flag (invariant 40 spirit: never silently).

## Permissions

Add `MarketingView`, `MarketingManage`, `ConsentManage` to `Permission`, seed into
system roles (`RbacSystemRoleSeeder`), cover in `RoutePermissions`.

## Acceptance criteria

- [ ] Seeded lists exist after `demo:seed --fresh`.
- [ ] Member count equals `grantedQuery` count; withdrawn contacts drop out.
- [ ] Import: dry-run report then confirm; rows without an original source rejected.
- [ ] Site-scoped employee sees only members visible to them.
