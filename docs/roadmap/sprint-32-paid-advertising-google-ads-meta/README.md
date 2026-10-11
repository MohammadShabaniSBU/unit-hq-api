# Sprint 32 — Paid advertising (Google Ads & Meta)

## Goal

Close the loop between paid ads and the leasing pipeline. A click on a Google or
Meta ad becomes an attributed enquiry, the enquiry becomes a deal, and when the
deal signs a contract, Keevaris tells the ad platform: "that click became a
move-in worth €X". The operator sees **cost per lead and cost per move-in by site**
in Insights.

Built on S30 (consent ledger, signup forms, segments) and S31 (campaigns).
Paid ads are **not** S31 campaigns: we mirror them and their spend. We never
send them.

| Capability | Google Ads | Meta | OpenAI |
|---|---|---|---|
| Account connection | OAuth (Keevaris Google Cloud app + developer token) | System-user token + pixel id + ad account id | S33 |
| Attribution capture | `gclid` / `gbraid` / `wbraid` | `fbclid` → `fbc`, `_fbp` → `fbp` | S33 (capture only this sprint, see 02) |
| Conversions out | Offline click conversions + enhanced conversions for leads | Conversions API (`action_source = system_generated`) | S33 |
| Campaigns + daily spend in | GAQL `campaign` report | Insights API `level=campaign` | S33 |

## Design decisions (lock at kickoff)

| # | Decision | Rationale |
|---|---|---|
| A1 | Ad platforms are their own adapter family, `App\Support\Advertising`. Capability is interface presence (`ReportsConversions`, `ReportsSpend`, `ListsCampaigns`), not a capabilities map. They are **not** comms `Provider`s. | Same pattern as `Support\Communications` and `Support\Insights`. Different lifecycle and credentials. |
| A2 | `ad_platform_accounts` are company-scoped. Several accounts per provider are allowed. **Site attribution happens on the ad campaign mapping**, not on the account. | Most operators run one ad account for every site. Mapping per campaign handles both shapes. |
| A3 | **Touches are append-only facts. Attribution is computed.** No `deals.attribution_*` and no stored "winning touch". Default model: last non-direct paid touch within 90 days of the deal. | Invariant 5. Changing the attribution model must not require a backfill. |
| A4 | Signup forms gain `purpose = subscribe \| enquiry`. **An enquiry form creates or matches a contact and opens at most one deal.** This reverses the S30-04 "never a deal" decision for the enquiry purpose only. | Ad traffic needs a storage enquiry, not a newsletter signup. Needs product sign-off at kickoff. |
| A5 | Sharing data with an ad platform needs **granted consent on channel `ads`** for the system list `advertising`, re-checked at delivery time. | Same ledger, same evidence, same redaction path as S30. EU (GDPR / Google EU user consent policy). |
| A6 | Conversions use a **transactional outbox**. `ad_conversion_events` rows are written inside the business transaction. `ad_conversion_deliveries` (one per account × event) are claimed before send. Every skip records a reason. | Same backbone as campaign recipients (invariant 81). "Why didn't Google get this move-in?" must be answerable. |
| A7 | Identifiers are hashed (SHA-256 of normalised email / E.164 phone) **in process at send time**. Delivery rows store **which** identifiers were used, never their values. | Nothing to redact on deliveries. Raw PII never sits in an outbox. |
| A8 | Daily spend is an **external, restated cache**: upsert a trailing 7-day window. Money keeps the ad account's currency and is never summed across currencies. | Platforms revise recent spend. Same category as `insight_reports.validation_status`. Invariant 30. |
| A9 | Reporting is a native Insights report, `paid-acquisition`. The Advertising Overview renders that report. No second reporting system. | Decision I1. |
| A10 | **Read and conversions only in v1.** Keevaris never creates, edits, pauses or re-budgets anything inside an ad account. | Writes into the customer's ad spend need confirmations, audit and a kill switch. That is S33 work. |

## Prerequisites (start before day 1)

- **Google Ads developer token** with Basic access, applied for from the Keevaris
  manager account. Approval takes days to weeks. Test-account access is enough
  for development.
- **Google Cloud OAuth client** (Keevaris-owned, scope
  `https://www.googleapis.com/auth/adwords`), redirect URI per deployment.
- **Meta**: the operator creates a system user in their Business Manager with
  `ads_read` on the ad account. They generate the pixel's Conversions API token
  in Events Manager. No Keevaris Meta app review is needed in v1.
- Product sign-off on **A4**. It reverses an S30 decision.

## Exit criteria

- [ ] An operator connects a Google Ads account (OAuth) and a Meta ad account
      (token) in Settings → Integrations → Advertising. Secrets are masked, and
      every connect, rotate or remove writes a Tier-3 audit row.
- [ ] A visitor lands on the operator's site from a Google ad, browses, and
      submits an embedded enquiry form two pages later. A contact and a deal
      exist, and a touch with the `gclid` and UTMs is linked to the contact.
- [ ] A staff member converts that deal to a signed contract at the desk. Within
      5 minutes Google Ads receives a click conversion valued at the first-period
      net rent in the contract's currency. Meta receives a CAPI `Purchase` event
      with the same dedupe id.
- [ ] A contact without `ads` consent produces delivery rows with
      `skip_reason = no_consent`, and nothing leaves the server.
- [ ] Ad campaigns and 7 days of spend sync. An operator maps each campaign to a
      site. **Paid acquisition** shows spend, leads, move-ins, cost per lead and
      cost per move-in per site and platform, grouped by currency.
- [ ] `contacts:redact` removes click ids and landing URLs from that contact's
      touches. Delivery rows hold nothing to redact.

## Task order

| # | Task | Est. |
|---|---|---|
| 00 | [Schema, enums & permissions](./00-schema.md) | 1 day |
| 01 | [Ad platform accounts & adapters](./01-accounts-and-adapters.md) | 1.5 days |
| 02 | [Attribution capture & enquiry forms](./02-attribution-and-enquiry-forms.md) | 2 days |
| 03 | [Advertising consent](./03-advertising-consent.md) | 0.5 day |
| 04 | [Conversion outbox & delivery](./04-conversion-outbox.md) | 2 days |
| 05 | [Campaign & spend sync, site mapping](./05-campaign-and-spend-sync.md) | 1.5 days |
| 06 | [Paid acquisition report & analytics views](./06-reporting.md) | 1 day |
| 07 | [Panel](./07-panel.md) | 3 days |
| 08 | [Invariants, docs & tests](./08-invariants-and-docs.md) | 0.5 day |

**Total ≈ 13 days.** 00 → 01 first. 02 and 03 can run in parallel after 00. 04
needs 01 + 02 + 03. 05 needs 01. 06 needs 04 + 05. The panel can start on
Settings and Forms once 01 and 02 freeze their APIs.

## Dependencies on other sprints

- **Tracked links** (pencilled as "S32" in the S31 README, now moved after this
  sprint) owns UTM-stamping of links in our own email/SMS campaigns. This sprint
  does not depend on it. Once both ship, owned and paid traffic share the same
  `marketing_touches` model. Update the "Next" line in the S31 README to point at
  the new sprint number.

## Next (S33, not in this sprint)

Meta Lead Ads and Google lead form intake into **Forms → Ad lead forms**, segment →
Customer Match / Custom Audience sync (tenant exclusion, lookalike seeds), the
OpenAI Ads adapter (pixel + Conversions API + insights), call tracking numbers per
campaign via Aircall, occupancy-aware campaign control (automation actions to
pause or resume ad groups when a unit class fills or frees up), and projected-LTV
conversion values.
