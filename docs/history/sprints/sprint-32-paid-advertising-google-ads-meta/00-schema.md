# S32-00 — Schema, enums & permissions

## Tables

```sql
CREATE TABLE ad_platform_accounts (
    id BIGSERIAL PRIMARY KEY,
    provider VARCHAR(16) NOT NULL,             -- google_ads | meta   (openai reserved for S33)
    name VARCHAR(128) NOT NULL,                -- operator label
    external_account_id VARCHAR(64) NOT NULL,  -- Google customer id (digits only) | Meta act_ id (digits only)
    login_customer_id VARCHAR(32) NULL,        -- Google MCC id when accessed through a manager account
    currency CHAR(3) NULL,                     -- read from the platform on verify; never typed by the operator
    timezone VARCHAR(64) NULL,                 -- read from the platform on verify (spend dates are in this tz)
    credentials TEXT NOT NULL,                 -- encrypted cast; JSON per provider (see 01)
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    last_verified_at TIMESTAMPTZ NULL,
    last_error VARCHAR(255) NULL,
    archived_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ, updated_at TIMESTAMPTZ,
    UNIQUE (provider, external_account_id)
);

CREATE TABLE marketing_touches (           -- append-only fact table
    id BIGSERIAL PRIMARY KEY,
    contact_id BIGINT NULL REFERENCES contacts(id) ON DELETE CASCADE,
    visitor_key CHAR(32) NULL,               -- first-party random id from the tracking tag
    occurred_at TIMESTAMPTZ NOT NULL,        -- landing time reported by the tag, clamped to receipt time
    site_id BIGINT NULL REFERENCES sites(id),-- from the form, when known
    platform VARCHAR(16) NULL,               -- google_ads | meta | openai | NULL (organic / other) — derived on write
    utm_source VARCHAR(128) NULL,
    utm_medium VARCHAR(128) NULL,
    utm_campaign VARCHAR(255) NULL,
    utm_term VARCHAR(255) NULL,
    utm_content VARCHAR(255) NULL,
    click_ids JSONB NOT NULL DEFAULT '{}',   -- {gclid, gbraid, wbraid, fbc, fbp, oai_ref} — only keys present
    landing_url VARCHAR(1000) NULL,          -- query string stripped of click ids before storage
    referrer_host VARCHAR(255) NULL,         -- host only, never the full referrer
    signup_form_id BIGINT NULL REFERENCES signup_forms(id),
    consent_snapshot JSONB NOT NULL DEFAULT '{}',  -- {ad_storage, ad_user_data, ad_personalization} as reported by the site's CMP
    created_at TIMESTAMPTZ NOT NULL
);
CREATE INDEX mt_contact_idx ON marketing_touches (contact_id, occurred_at DESC);
CREATE INDEX mt_visitor_idx ON marketing_touches (visitor_key, occurred_at DESC) WHERE contact_id IS NULL;
CREATE INDEX mt_platform_campaign_idx ON marketing_touches (platform, utm_campaign);

CREATE TABLE ad_conversion_mappings (
    id BIGSERIAL PRIMARY KEY,
    ad_platform_account_id BIGINT NOT NULL REFERENCES ad_platform_accounts(id),
    event_key VARCHAR(24) NOT NULL,          -- lead | reservation | move_in
    external_ref VARCHAR(255) NOT NULL,      -- Google: conversion action resource name; Meta: event name (Lead, Schedule, Purchase)
    value_rule VARCHAR(24) NOT NULL DEFAULT 'none',  -- none | first_period_net
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ, updated_at TIMESTAMPTZ,
    UNIQUE (ad_platform_account_id, event_key),
    CONSTRAINT acm_value_only_move_in CHECK (value_rule = 'none' OR event_key = 'move_in')
);

CREATE TABLE ad_conversion_events (       -- outbox, append-only
    id BIGSERIAL PRIMARY KEY,
    event_key VARCHAR(24) NOT NULL,          -- lead | reservation | move_in
    dedupe_id VARCHAR(64) NOT NULL UNIQUE,   -- "deal:41:lead" | "reservation:77:created" | "contract:812:move_in"
    contact_id BIGINT NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
    subject_type VARCHAR(64) NOT NULL,
    subject_id BIGINT NOT NULL,
    site_id BIGINT NULL REFERENCES sites(id),
    value NUMERIC(10,2) NULL,
    currency CHAR(3) NULL,
    occurred_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    CONSTRAINT ace_value_has_currency CHECK ((value IS NULL) = (currency IS NULL))
);

CREATE TABLE ad_conversion_deliveries (
    id BIGSERIAL PRIMARY KEY,
    ad_conversion_event_id BIGINT NOT NULL REFERENCES ad_conversion_events(id) ON DELETE CASCADE,
    ad_platform_account_id BIGINT NOT NULL REFERENCES ad_platform_accounts(id),
    marketing_touch_id BIGINT NULL REFERENCES marketing_touches(id) ON DELETE SET NULL,  -- touch chosen at send time
    status VARCHAR(16) NOT NULL DEFAULT 'pending',  -- pending | claimed | sent | skipped | failed
    skip_reason VARCHAR(32) NULL,
        -- no_consent | no_identifier | click_too_old | mapping_inactive | account_inactive | event_too_old
    identifiers_used JSONB NOT NULL DEFAULT '[]',  -- ["gclid","email_sha256"] — names only, never values
    attempts SMALLINT NOT NULL DEFAULT 0,
    claimed_at TIMESTAMPTZ NULL,
    processed_at TIMESTAMPTZ NULL,
    error TEXT NULL,
    provider_response JSONB NULL,            -- trimmed: request id, partial-failure codes
    created_at TIMESTAMPTZ, updated_at TIMESTAMPTZ,
    UNIQUE (ad_platform_account_id, ad_conversion_event_id)
);
CREATE INDEX acd_status_idx ON ad_conversion_deliveries (status, ad_platform_account_id);

CREATE TABLE ad_campaigns (               -- synced catalogue + operator mapping
    id BIGSERIAL PRIMARY KEY,
    ad_platform_account_id BIGINT NOT NULL REFERENCES ad_platform_accounts(id),
    external_id VARCHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL,
    status VARCHAR(16) NOT NULL,             -- enabled | paused | removed (normalised)
    channel_type VARCHAR(32) NULL,           -- search | performance_max | display | video | social … (informational)
    site_id BIGINT NULL REFERENCES sites(id),        -- operator mapping
    unit_class_id BIGINT NULL REFERENCES unit_classes(id),
    utm_campaign_hint VARCHAR(255) NULL,     -- operator-set; joins touches whose utm_campaign matches
    mapped_by BIGINT NULL REFERENCES employees(id) ON DELETE SET NULL,
    mapped_at TIMESTAMPTZ NULL,
    first_seen_at TIMESTAMPTZ NOT NULL,
    last_synced_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ, updated_at TIMESTAMPTZ,
    UNIQUE (ad_platform_account_id, external_id)
);

CREATE TABLE ad_spend_daily (             -- external restated cache (A8)
    id BIGSERIAL PRIMARY KEY,
    ad_campaign_id BIGINT NOT NULL REFERENCES ad_campaigns(id),
    spend_date DATE NOT NULL,                -- civil date in ad_platform_accounts.timezone
    impressions BIGINT NOT NULL DEFAULT 0,
    clicks BIGINT NOT NULL DEFAULT 0,
    cost NUMERIC(12,2) NOT NULL DEFAULT 0,   -- converted from micros with bcmath, half-up
    currency CHAR(3) NOT NULL,
    synced_at TIMESTAMPTZ NOT NULL,
    UNIQUE (ad_campaign_id, spend_date)
);
```

Notes:
- `cost` is `NUMERIC(12,2)` and not `(10,2)`: a year of campaign-level spend can
  pass 99,999,999.99 in the smallest currencies. It is an external figure, never a
  ledger amount. Record this exception in 08.
- `signup_forms` gains `purpose VARCHAR(16) NOT NULL DEFAULT 'subscribe'` and
  `enquiry_fields JSONB NULL` (see 02).
- `subscription_lists.kind` gains `advertising` (see 03).

## Append-only guards

- `marketing_touches`, `ad_conversion_events`: model guard (`AppendOnly` concern as
  used by `consent_events`). Pgsql triggers reject `UPDATE` and `DELETE`, except
  `UPDATE` of `contact_id` from `NULL` (visitor → contact stitching, see 02) and
  the redaction command's allowlisted columns (`click_ids`, `landing_url`,
  `referrer_host`, `utm_term`, `utm_content`). Same pattern as the
  `consent_events` triggers.
- `ad_conversion_deliveries`: status moves forward only
  (`pending → claimed → sent|skipped|failed`; the manual retry in 04 is the one backward move, `failed → pending`). A
  trigger rejects any change to `ad_conversion_event_id`, `ad_platform_account_id`
  and `identifiers_used` after `sent`.

## Enums

`App\Support\Advertising\AdPlatform` (`GoogleAds`, `Meta`; `OpenAi` defined but
`isImplemented() = false`), `ConversionEventKey`, `ConversionValueRule`,
`AdDeliveryStatus`, `AdDeliverySkipReason`, `AdCampaignStatus`,
`SignupFormPurpose` (`Subscribe`, `Enquiry`).

`SubscriptionListKind` gains `Advertising`. `Channel` for consent purposes gains
`ads`. It is **not** a comms `Channel`: put it on the consent-channel enum, or give
`ConsentChannel` its own enum if `consent_events.channel` currently reuses the
comms one. Decide in this task and record the choice in 08.

`ContactSource` gains `EnquiryForm = 'enquiry_form'`.

## Permissions

`AdvertisingView` (`advertising.view`) covers the overview, campaigns and
conversion health. `AdvertisingManage` (`advertising.manage`) covers campaign
mapping, conversion mappings and retrying a failed delivery.
`AdvertisingConnect` (`advertising.connect`) covers Settings → Integrations →
Advertising, connect/rotate/remove.

Seed into `RbacSystemRoleSeeder` and cover in `RoutePermissions`. Site-scoped
grants: a site-scoped employee sees only ad campaigns mapped to granted sites. Unmapped
campaigns are company-wide only.

## Acceptance criteria

- [ ] Migrations run on SQLite (tests) and Postgres. Pgsql-only triggers are in
      `createPostgresTriggers()`, with model guards everywhere.
- [ ] `ad_conversion_events` rejects a value without a currency (CHECK).
- [ ] A mapping with `value_rule = first_period_net` on `lead` is rejected (CHECK).
- [ ] Updating a touch's `utm_source` fails on pgsql and via Eloquent. Setting a
      `NULL` `contact_id` succeeds once.
- [ ] Permission enum, seeder and `RoutePermissions` are covered by the existing
      RBAC coverage test.
