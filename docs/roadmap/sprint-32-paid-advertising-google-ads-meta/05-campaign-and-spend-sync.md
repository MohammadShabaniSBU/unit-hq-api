# S32-05 — Campaign & spend sync, site mapping

## Jobs

| Command | Schedule | Does |
|---|---|---|
| `ads:sync-campaigns` | hourly | `ListsCampaigns` per active account → upsert `ad_campaigns` (name, status, channel_type, `last_synced_at`; `first_seen_at` on insert). Never touches mapping columns. |
| `ads:sync-spend {--days=7}` | every 6 hours | `ReportsSpend` for `[today − days, today]` in the **account's timezone** → upsert `ad_spend_daily` by `(ad_campaign_id, spend_date)` |
| `ads:sync-spend --days=90` | manual / on account connect | Backfill after connecting |

Both jobs: one account at a time, `WithoutOverlapping` per account, Tier-1
`ads.sync.started` / `.committed` / `.failed` SystemEvents (counts only). A failed
account doesn't block the others. `last_error` on the account shows the latest failure.

## Provider queries

**Google:** `searchStream` with

```sql
SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type,
       segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks
FROM campaign
WHERE segments.date BETWEEN '{from}' AND '{to}'
```

Campaign list uses the same resource without `segments`/`metrics`, including
`REMOVED` (normalised to `removed`).

**Meta:** `GET /act_{id}/campaigns?fields=id,name,effective_status,objective` (paginate)
and `GET /act_{id}/insights?level=campaign&time_increment=1&time_range={...}&fields=campaign_id,spend,impressions,clicks` (paginate; async report job when the
range exceeds 30 days).

## Money

- Google `cost_micros` → `cost` with `BillingMath`: `micros / 1_000_000`, half-up to 2
  decimals. Never floats.
- Meta `spend` arrives as a decimal string. Parse it as a string, never `(float)`.
- `currency` = the account currency captured at verify. If the platform reports a
  different currency than stored (account currency changed), fail the sync with
  `currency_mismatch` and require a re-verify. Never mix currencies on one account.

## Restatement (A8)

Upsert inside the trailing window is the **documented exception** to append-only
facts. `ad_spend_daily` caches an external system's numbers, like
`insight_reports.validation_status`. Rows older than the window are never
rewritten by the scheduled job. Record this in invariant 89 (08).

## Site mapping

| Method | Path | Permission |
|---|---|---|
| `GET` | `ad-campaigns` | `advertising.view`. Filters: account, status, `mapped=true|false`, site. Includes spend for `?from&to` (summed per currency, never across). |
| `PUT` | `ad-campaigns/{id}/mapping` | `advertising.manage`. `{site_id, unit_class_id?, utm_campaign_hint?}`. `unit_class_id` must belong to `site_id`. Writes `mapped_by/at`. Tier-2 `ads.campaign.mapped`. |
| `POST` | `ad-campaigns/mapping/bulk` | Same, for a list of ids → one site |

**Suggested mapping:** the list endpoint returns `suggested_site_id` when the
campaign name contains exactly one active site's name or code
(case/accents-insensitive). It is a suggestion, never auto-applied.

Site-scoped employees (D-RBAC-1 style): they see campaigns mapped to granted sites
only, and can't map or see unmapped campaigns.

## Touch ↔ campaign join (for 06)

A touch belongs to an ad campaign when either:
- `touch.utm_campaign = ad_campaigns.utm_campaign_hint` (operator-set), or
- Google: `utm_campaign` equals the campaign **id** (`{campaignid}` ValueTrack
  param). Meta: `utm_campaign` equals the campaign id (`{{campaign.id}}` dynamic
  param).

Settings (07) shows the recommended tracking templates, so operators set
`utm_campaign={campaignid}` / `{{campaign.id}}` once and mapping becomes automatic.

## Acceptance criteria

- [ ] A sync never overwrites `site_id` / `unit_class_id` / `utm_campaign_hint`.
- [ ] Re-running spend sync for the same window is idempotent (row count stable,
      values updated).
- [ ] `cost_micros = 1234567` stores `1.23`. `1235000` stores `1.24` (half-up).
- [ ] A currency change on the platform fails the sync with `currency_mismatch`,
      and nothing is written.
- [ ] One failing account doesn't stop the other accounts' sync.
- [ ] Removed campaigns stay listed (history) with status `removed`.
