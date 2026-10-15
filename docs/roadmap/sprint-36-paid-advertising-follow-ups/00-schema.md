# S36-00 — Schema, enums & permissions

## Context

S32 created `ad_platform_accounts`, `marketing_touches`, `ad_conversion_mappings`,
`ad_conversion_events`, `ad_conversion_deliveries`, `ad_campaigns`, `ad_spend_daily`
(`2026_10_11_120000_create_advertising_tables.php`). This task adds what the follow-ups
need, in new migrations only.

## Scope

**In:** new tables, new columns, enum cases, permissions.
**Out:** behaviour (01–05).

## Schema changes

```sql
-- 01: track which OAuth scopes a Google account has granted
ALTER TABLE ad_platform_accounts ADD COLUMN granted_scopes JSONB NOT NULL DEFAULT '[]';

-- 02: platform lead forms
CREATE TABLE ad_lead_forms (
    id BIGSERIAL PRIMARY KEY,
    ad_platform_account_id BIGINT NOT NULL REFERENCES ad_platform_accounts(id),
    external_form_id VARCHAR(64) NOT NULL,        -- Google form/asset id | Meta leadgen form id
    external_page_id VARCHAR(64) NULL,            -- Meta Page id (polling)
    name VARCHAR(255) NOT NULL,
    site_id BIGINT NULL REFERENCES sites(id),     -- required before intake is enabled
    field_map JSONB NOT NULL DEFAULT '{}',        -- platform field key → our field (see 02)
    consent_map JSONB NOT NULL DEFAULT '{}',      -- platform question key → {list_id} or "ads"
    webhook_key_hash CHAR(64) NULL,               -- Google: sha256 of the key the operator pasted
    webhook_token CHAR(40) NULL UNIQUE,           -- Google: crypto-random URL token
    is_active BOOLEAN NOT NULL DEFAULT FALSE,
    last_polled_at TIMESTAMPTZ NULL,              -- Meta cursor (time_created high-water mark)
    last_error VARCHAR(255) NULL,
    archived_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ, updated_at TIMESTAMPTZ,
    UNIQUE (ad_platform_account_id, external_form_id),
    CONSTRAINT alf_active_needs_site CHECK (is_active = FALSE OR site_id IS NOT NULL)
);

CREATE TABLE ad_lead_receipts (                   -- idempotency + audit for each platform lead
    id BIGSERIAL PRIMARY KEY,
    ad_lead_form_id BIGINT NOT NULL REFERENCES ad_lead_forms(id),
    external_lead_id VARCHAR(128) NOT NULL,
    received_at TIMESTAMPTZ NOT NULL,
    is_test BOOLEAN NOT NULL DEFAULT FALSE,
    outcome VARCHAR(24) NOT NULL,                 -- deal_created | note_added | invalid | test
    contact_id BIGINT NULL REFERENCES contacts(id) ON DELETE SET NULL,
    deal_id BIGINT NULL REFERENCES deals(id) ON DELETE SET NULL,
    error_key VARCHAR(64) NULL,
    created_at TIMESTAMPTZ NOT NULL,
    UNIQUE (ad_lead_form_id, external_lead_id)
);

-- 04: audiences
CREATE TABLE ad_audiences (
    id BIGSERIAL PRIMARY KEY,
    ad_platform_account_id BIGINT NOT NULL REFERENCES ad_platform_accounts(id),
    name VARCHAR(255) NOT NULL,
    source VARCHAR(24) NOT NULL,                  -- segment | current_tenants
    segment_id BIGINT NULL REFERENCES segments(id),
    external_audience_id VARCHAR(128) NULL,       -- Meta custom audience id | Google user list resource
    status VARCHAR(16) NOT NULL DEFAULT 'draft',  -- draft | active | paused | error | archived
    last_synced_at TIMESTAMPTZ NULL,
    last_error VARCHAR(255) NULL,
    created_by BIGINT NULL REFERENCES employees(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ, updated_at TIMESTAMPTZ,
    CONSTRAINT aa_segment_source CHECK ((source = 'segment') = (segment_id IS NOT NULL))
);

CREATE TABLE ad_audience_members (
    id BIGSERIAL PRIMARY KEY,
    ad_audience_id BIGINT NOT NULL REFERENCES ad_audiences(id) ON DELETE CASCADE,
    contact_id BIGINT NULL REFERENCES contacts(id) ON DELETE SET NULL,
    identifier_fingerprint CHAR(64) NOT NULL,     -- sha256 over the sorted identifier hashes sent; never the hashes
    identifiers_used JSONB NOT NULL DEFAULT '[]', -- ["email_sha256","phone_sha256"] — names only
    state VARCHAR(16) NOT NULL,                   -- added | removing | removed
    pending_removal_hashes TEXT NULL,             -- encrypted cast; set only while old hashes must still be removed (redaction or identifier change); cleared on success
    added_at TIMESTAMPTZ NULL,
    removed_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ, updated_at TIMESTAMPTZ,
    UNIQUE (ad_audience_id, contact_id)
);
CREATE INDEX aam_state_idx ON ad_audience_members (ad_audience_id, state);

-- 05: stay projections (append-only, effective-dated)
CREATE TABLE ad_stay_projections (
    id BIGSERIAL PRIMARY KEY,
    site_id BIGINT NOT NULL REFERENCES sites(id),
    unit_class_id BIGINT NULL REFERENCES unit_classes(id),   -- NULL = site-wide fallback
    computed_on DATE NOT NULL,
    projected_months NUMERIC(5,2) NOT NULL,
    sample_size INTEGER NOT NULL,
    method VARCHAR(32) NOT NULL,                  -- median_closed_24m | site_fallback | config_default
    created_at TIMESTAMPTZ NOT NULL,
    UNIQUE (site_id, unit_class_id, computed_on)
);
ALTER TABLE ad_conversion_events ADD COLUMN ad_stay_projection_id BIGINT NULL REFERENCES ad_stay_projections(id);
ALTER TABLE ad_conversion_events ADD COLUMN value_basis JSONB NULL;
    -- {"rule":"projected_stay","first_period_net":"84.00","months":"11.50"} — strings (invariant 10)
```

Notes:
- `ad_lead_receipts`, `ad_stay_projections`: append-only (model guard + pgsql trigger,
  same `AppendOnly` concern as `marketing_touches`).
- `ad_conversion_events` is append-only already; the two new columns are written at
  insert only.
- `ad_audience_members.state` moves forward only: `added → removing → removed`, and
  `removed → added` when the contact re-qualifies (new `added_at`).
- `pending_removal_hashes` is the only place identifier hashes are ever stored, and
  only for the redaction and identifier-change paths in 04. Encrypted cast; nulled on successful removal.
- `ad_conversion_mappings.value_rule` CHECK from S32 (`value_rule = 'none' OR event_key
  = 'move_in'`) stays; `projected_stay` is a move-in-only rule too.

## Enums

- `AdPlatform::OpenAi->isImplemented()` → `true` (03).
- `ConversionValueRule::ProjectedStay = 'projected_stay'`.
- `AdDeliverySkipReason` gains `ReauthRequired = 'reauth_required'`,
  `NoClickReference = 'no_click_reference'` (OpenAI, see 03) — keep existing values.
- New: `AdLeadOutcome`, `AdAudienceSource`, `AdAudienceStatus`,
  `AdAudienceMemberState`, `StayProjectionMethod`.
- `ContactSource` gains `AdLeadForm = 'ad_lead_form'`.
- `TouchRecorder::URL_CLICK_PARAMS`: replace `oai_ref` with `oppref` (03 owns the
  change; listed here so the enum/const review happens once).

## Permissions

Reuse S32's: `AdvertisingView`, `AdvertisingManage` (lead-form mapping, audiences,
value rules), `AdvertisingConnect` (accounts, reconnect). Add
`AdvertisingAudienceSync = 'advertising.audience_sync'` — required **in addition to**
`AdvertisingManage` to activate an audience, because it sends personal data (hashed)
to a third party. Company-wide grants only (an audience is not site-scoped).

## Acceptance criteria

- [ ] Migrations run on SQLite and Postgres; append-only triggers on pgsql only, model
      guards everywhere.
- [ ] An active lead form without a site is rejected by the CHECK.
- [ ] A `segment` audience without `segment_id` (and the reverse) is rejected.
- [ ] Member state transitions are enforced in the model.
- [ ] `PermissionCoverageTest` passes with the new permission.

## Tests required

| Test | Asserts |
|---|---|
| `AdvertisingFollowUpSchemaTest::checks` | Lead-form site, audience source |
| `AdvertisingFollowUpSchemaTest::append_only` | Receipts, projections |
| `AdvertisingFollowUpSchemaTest::member_state_forward_only` | Transition table |
| `PermissionCoverageTest` (existing) | New permission |
