# S31-05 — Reporting & analytics

All numbers derived (C7). No counters on `campaigns`.

## Campaign report endpoint

`GET campaigns/{c}/report` (`CampaignView`), per send and total:

| Metric | Source |
|---|---|
| targeted | count of `campaign_recipients` |
| skipped (by reason) | `campaign_recipients.skip_reason` |
| sent / failed | `campaign_recipients.status` |
| delivered / bounced / opened / clicked / complained | `messages.delivery_events` via `campaign_recipients.message_id` (existing `DeliveryEventApplier` reconciliation) |
| unsubscribed | `consent_events` with `evidence.message_id` ∈ campaign messages, or `source = one_click` from the campaign's tokens |
| rates | opened/delivered, clicked/delivered, unsubscribed/delivered |

Opens are flagged as approximate in the UI (Apple Mail Privacy Protection).

`GET campaigns/{c}/recipients?status=&skip_reason=&q=` — paginated, `visibleTo`
applied, links to contact and message.

Cache the report for 60 s while `sending`; no cache after `sent` is fine too.

## Analytics (additive views, invariant on `analytics.*` — new migration, never edit)

- `analytics.v_campaigns` — id, type, name, status, list, sites, approved_at,
  sent window, template family/version.
- `analytics.v_campaign_recipients` — campaign/send/contact ids, status, skip reason,
  processed_at, delivery flags derived from `messages.delivery_events`.

Update `analytics-schema.md` + `report-definitions.md`; add an Insights native report
"Campaign performance" (by month and type) and "List growth" (grants − withdrawals per
list, from `v_consent_state` events).

## Contact timeline

Campaign messages already create interactions via the sender. Add a timeline filter
"Marketing" and show the campaign name on the message card (from `source_ref`).

## Acceptance criteria

- [ ] Report totals reconcile: targeted = sent + skipped + failed (+ pending while sending).
- [ ] A bounce webhook arriving after completion updates the report.
- [ ] Analytics views created via new migration; `InsightsProvisionCommand` picks them up.
