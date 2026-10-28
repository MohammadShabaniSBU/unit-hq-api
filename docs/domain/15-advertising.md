# Paid advertising

Keevaris mirrors Google Ads and Meta. It records the click that became an
enquiry, and it tells the platform when that enquiry becomes a move-in.
It does not create, edit, pause, or re-budget anything inside an ad account
(invariant 90). Campaign writes, audiences, lead-form intake, and the OpenAI
adapter are S33.

Adapters live in `App\Support\Advertising`. Capability is interface presence
(`ListsCampaigns`, `ReportsSpend`, `ReportsConversions`), not a capabilities
map. They are not comms `Provider`s.

## Accounts

`ad_platform_accounts` are company-scoped. Several accounts per provider are
allowed. Secrets are encrypted, masked in responses, and a blank submitted
secret means "leave the stored value". Connect, rotate, and remove write a
Tier-3 audit row.

Google connects with OAuth. Meta connects with the operator's system-user
token, pixel id, and ad account id. Verify stores the account currency and
timezone. Those two fields stay on the account. Site attribution does not:
it lives on the campaign mapping.

## Touches and attribution

`marketing_touches` is an append-only fact (invariant 86). The only updates
are stitching `contact_id` from null to an id, once, and redaction of
`click_ids`, `landing_url`, `referrer_host`, `utm_term`, and `utm_content`.

A touch is written only when the form payload's consent snapshot has
`ad_storage`. Click ids (`gclid`, `gbraid`, `wbraid`, `fbclid`, and the
reserved `oai_ref`) are stripped from `landing_url` and stored in
`click_ids`. `occurred_at` is clamped to the last 90 days and never to the
future.

Nothing stores a winning touch. `AttributedTouch` computes the last paid
touch (non-null `platform` of `google_ads` or `meta`) inside the report
window, per query. Changing the model does not require a backfill.

The first-party tag (`GET /public/tag.js`) keeps the latest paid click until
the visitor submits a form. The embed passes that snapshot as `t=`.

## Enquiry forms

Signup forms have `purpose = subscribe | enquiry`.

A subscribe form may create a contact and never a deal (invariant 79).
An enquiry form requires a site. It matches an existing contact by email,
then phone, or creates one with `source = enquiry_form`. It opens a deal
only when that contact has no open deal at the form's site. A second
enquiry at the same site adds a note (invariant 85).

## Consent

Sharing data with an ad platform needs granted consent on channel `ads` for
the system list `advertising` (invariant 87). `ads` is
`App\Support\Marketing\ConsentChannel`. It is not a send channel and not
`App\Support\Communications\Channel`.

`ConsentState::isGranted` is checked again at delivery time. Soft opt-in is
rejected for the advertising list. Identifiers are SHA-256 of the normalised
email or E.164 phone, hashed in process at send time. Delivery rows store
which identifiers were used, never their values.

## Conversions

`ad_conversion_events` are written inside the business transaction that
produced them: deal insert, reservation insert, and
`ContractSigning::complete`. A rolled-back transaction leaves no event.

`ad_conversion_deliveries` is one row per account and event. The sender
claims the row before it sends. `dedupe_id` is stable and is sent as Google
`orderId` and Meta `event_id` (invariant 88). A skip stores `skip_reason`.
A contact without `ads` consent produces `skip_reason = no_consent` and
nothing leaves the server.

Move-in value is the first-period net rent in the contract currency. Lead
events do not carry a value.

## Spend and the report

`ad_spend_daily` is a restated cache. Sync upserts a trailing window. Cost
is bcmath from the platform's units, `NUMERIC(12,2)`, in the account
currency (invariant 89). Dates outside the window are not rewritten. A
currency mismatch writes nothing for that account. One account's failure
does not stop the others.

The operator maps each campaign to a site (`ad_campaigns.site_id`) and may
set `utm_campaign_hint`. Unmapped spend stays on its own row.

Reporting is the native Insights report `paid-acquisition`
(`PaidAcquisitionReport`). Definitions: `../reference/report-definitions.md` (Paid
acquisition). The Marketing → Advertising overview renders that report.
There is no second reporting system. Spend, leads, and move-ins are grouped
by currency. ROAS is shown only when spend and first-period revenue share
one currency.

`contacts:redact` clears click ids and landing URLs on that contact's
touches. Delivery rows hold nothing to redact.

History: S32, S36 — [sprint index](../history/README.md).
