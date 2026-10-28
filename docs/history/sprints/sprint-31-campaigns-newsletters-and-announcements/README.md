# Sprint 31 — Campaigns, newsletters & announcements

## Goal

One broadcast engine with three types, built on S29 (template versions) and S30
(consent, lists, segments, marketing lane):

| Type | Audience | Send class | Content | Typical use |
|---|---|---|---|---|
| **Campaign** | List members (consent) ∩ segment | marketing | template purpose `marketing` | "20% off 10 m² at Madrid Centro for lost leads" |
| **Newsletter** | List of kind `newsletter` ∩ optional segment | marketing | purpose `newsletter` | Monthly tenant newsletter |
| **Announcement** | Current tenants of selected sites ∩ optional segment | transactional | purpose `announcement` | "Gate closed Saturday", new access hours |

Channels this sprint: **email and SMS**. WhatsApp (marketing/utility templates) is S32.

## Design decisions (lock at kickoff)

| # | Decision | Rationale |
|---|---|---|
| C1 | One schema (`campaigns`, `campaign_sends`, `campaign_recipients`) with a `type` column. | Same pipeline, stats, approval; type only changes audience rules and send class. |
| C2 | `campaign_sends` **pins `template_version_id`**; no content columns. | S29 makes versions immutable; content stays in the builder. |
| C3 | Audience is **frozen at scheduling** (`audience_snapshot`); recipients are **materialised at dispatch**. | Approved audience = sent audience definition; consent is re-checked at send time. |
| C4 | Recipient rows are the idempotency backbone: unique `(campaign_send_id, contact_id)`, claim-by-conditional-update. | Pause/resume/retry never double-send. |
| C5 | Gates run per recipient at send time: consent → exclusions → frequency cap → (sender's) suppression. Every skip is recorded with a reason. | "Why didn't Diana get it?" is answerable. |
| C6 | **Announcements are locked down**: audience must be current tenants of chosen sites, purpose `announcement` templates only, no discount, separate `AnnouncementSend` permission. | Transactional class bypasses marketing consent; must not become a promo backdoor. |
| C7 | No stored stats. Delivered/opened/bounced/unsubscribed are derived from `messages.delivery_events` and consent events. | Invariant 5. |
| C8 | Content and audience are immutable from `scheduled` on; editing = cancel + duplicate. | Same rule as issued invoices / published templates. |
| C9 | Drip sequences are **not** campaigns — they stay in the automation engine. | One flow engine. |

## Exit criteria

- [ ] A marketer builds an email campaign to "Promotions ∩ lost leads 90 days",
      test-sends, submits; a manager approves; it sends at 10:00 site time, throttled,
      and the report shows sent / skipped-by-reason / delivered / opened / bounced /
      unsubscribed.
- [ ] Pausing mid-send stops new sends within one batch; resuming finishes without
      duplicates.
- [ ] A newsletter issue goes only to granted newsletter members; "duplicate last
      issue" pre-fills the next one.
- [ ] A site manager sends an SMS announcement to tenants of their site only; a
      contact who unsubscribed from marketing still receives it; a hard-bounced
      address doesn't.
- [ ] Every sent message links back to its campaign, send and template version.

## Task order

| # | Task | Est. |
|---|---|---|
| 00 | [Schema, enums & permissions](./00-schema.md) | 1 day |
| 01 | [Campaign lifecycle API](./01-lifecycle-api.md) | 1.5 days |
| 02 | [Dispatch pipeline](./02-dispatch-pipeline.md) | 2 days |
| 03 | [Newsletters](./03-newsletters.md) | 0.5 day |
| 04 | [Announcements](./04-announcements.md) | 0.5 day |
| 05 | [Reporting & analytics](./05-reporting.md) | 1 day |
| 06 | [Panel](./06-panel.md) | 3 days |
| 07 | [Invariants, docs & tests](./07-invariants-and-docs.md) | 0.5 day |

**Total ≈ 10 days.** 00 → 01 → 02 sequential; 03/04/05 after 02; panel can start on
list/wizard screens once 01's API is frozen.

## Next (S32, not in this sprint)

Tracked links + click attribution, promo discounts (D-DISC) with conversion
attribution, WhatsApp campaigns, A/B subject tests, "resend to non-openers",
automation triggers (`subscription.granted`, `campaign.clicked`), vacancy-driven
audiences, public newsletter archive.
