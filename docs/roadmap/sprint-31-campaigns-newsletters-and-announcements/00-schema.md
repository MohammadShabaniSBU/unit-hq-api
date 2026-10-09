# S31-00 — Schema, enums & permissions

## Tables

```sql
CREATE TABLE campaigns (
    id BIGSERIAL PRIMARY KEY,
    type VARCHAR(16) NOT NULL,                 -- campaign | newsletter | announcement
    name VARCHAR(160) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
        -- draft | pending_approval | scheduled | sending | paused | sent | cancelled
    subscription_list_id BIGINT NULL REFERENCES subscription_lists(id),  -- required for campaign/newsletter
    segment_id BIGINT NULL REFERENCES segments(id),                      -- optional narrowing
    site_ids JSONB NOT NULL DEFAULT '[]',      -- announcement: required; others: optional narrowing
    audience_snapshot JSONB NULL,              -- frozen at schedule: {list_id, segment_filter, site_ids, exclusions, settings}
    estimated_recipients INT NULL,             -- informational, captured at schedule (not a stat)
    newsletter_issue_number INT NULL,          -- newsletter only (03)
    ignore_send_window BOOLEAN NOT NULL DEFAULT FALSE,  -- announcement only (04)
    approval_required BOOLEAN NOT NULL DEFAULT FALSE,
    submitted_by BIGINT NULL REFERENCES employees(id) ON DELETE SET NULL,
    submitted_at TIMESTAMPTZ NULL,
    approved_by BIGINT NULL REFERENCES employees(id) ON DELETE SET NULL,
    approved_at TIMESTAMPTZ NULL,
    rejected_reason TEXT NULL,
    cancelled_by BIGINT NULL REFERENCES employees(id) ON DELETE SET NULL,
    cancelled_at TIMESTAMPTZ NULL,
    duplicated_from_id BIGINT NULL REFERENCES campaigns(id),
    created_by BIGINT NULL REFERENCES employees(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ, updated_at TIMESTAMPTZ,
    CONSTRAINT campaigns_list_required CHECK (type = 'announcement' OR subscription_list_id IS NOT NULL),
    CONSTRAINT campaigns_announcement_shape CHECK (
        type <> 'announcement' OR (subscription_list_id IS NULL AND jsonb_array_length(site_ids) > 0)),
    CONSTRAINT campaigns_window_only_announcement CHECK (type = 'announcement' OR ignore_send_window = FALSE)
);
CREATE INDEX campaigns_status_idx ON campaigns (status, type);

CREATE TABLE campaign_sends (
    id BIGSERIAL PRIMARY KEY,
    campaign_id BIGINT NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
    channel VARCHAR(16) NOT NULL,              -- email | sms
    template_family_id BIGINT NOT NULL REFERENCES template_families(id),
    template_version_id BIGINT NULL REFERENCES template_versions(id),  -- pinned at schedule; NULL while draft
    subject_override VARCHAR(500) NULL,        -- email only; tokens allowed
    scheduled_at TIMESTAMPTZ NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
        -- draft | scheduled | materialising | sending | paused | completed | cancelled
    materialised_at TIMESTAMPTZ NULL,
    completed_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ, updated_at TIMESTAMPTZ,
    UNIQUE (campaign_id, channel)              -- one send per channel this sprint (A/B in S32 relaxes this)
);

CREATE TABLE campaign_recipients (
    id BIGSERIAL PRIMARY KEY,
    campaign_send_id BIGINT NOT NULL REFERENCES campaign_sends(id) ON DELETE CASCADE,
    contact_id BIGINT NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
    address VARCHAR(255) NULL,                 -- resolved at materialisation
    site_id BIGINT NULL REFERENCES sites(id),  -- contact business site → sender identity, send window
    status VARCHAR(16) NOT NULL DEFAULT 'pending',   -- pending | claimed | sent | skipped | failed
    skip_reason VARCHAR(32) NULL,
        -- no_consent | suppressed | frequency_capped | excluded_delinquent | excluded_playbook
        -- | no_address | out_of_scope | channel_not_configured | template_not_resolvable
    message_id BIGINT NULL REFERENCES messages(id) ON DELETE SET NULL,
    claimed_at TIMESTAMPTZ NULL,
    processed_at TIMESTAMPTZ NULL,
    error TEXT NULL,
    attempts SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ, updated_at TIMESTAMPTZ,
    UNIQUE (campaign_send_id, contact_id)
);
CREATE INDEX cr_send_status_idx ON campaign_recipients (campaign_send_id, status);
CREATE INDEX cr_contact_sent_idx ON campaign_recipients (contact_id, processed_at) WHERE status = 'sent';  -- frequency cap
```

## Immutability triggers (pgsql; model guards everywhere)

- `campaigns`: once `status` ∉ {draft, pending_approval}, reject changes to
  `type, subscription_list_id, segment_id, site_ids, audience_snapshot,
  ignore_send_window`.
- `campaign_sends`: once `template_version_id` is set, reject changes to it,
  `template_family_id`, `channel`, `subject_override`.

## Enums

`CampaignType`, `CampaignStatus`, `CampaignSendStatus`, `CampaignRecipientStatus`,
`CampaignSkipReason`.

`TemplatePurpose` gains `Marketing = 'marketing'`, `Newsletter = 'newsletter'`,
`Announcement = 'announcement'` with picker allowlists:
campaign → marketing|general, newsletter → newsletter|marketing,
announcement → announcement only.

## Permissions

`CampaignView`, `CampaignManage` (draft/edit/test-send/submit), `CampaignApprove`,
`AnnouncementSend` (create + send announcements; no approval step by default).
Seed into `RbacSystemRoleSeeder`, cover in `RoutePermissions`. Site-scoped grants:
a site-scoped employee may only target `site_ids` within their grants and their
audience is intersected with `Contact::visibleTo` (D-RBAC-1).

## Acceptance criteria

- [ ] CHECK constraints reject an announcement with a list, or a campaign without one.
- [ ] Pinned version and scheduled audience can't be changed via SQL (pgsql) or Eloquent.
- [ ] `TemplatePurpose` picker allowlists table-tested.
