# S32-07 — Panel

All strings go through i18n (`en` / `es` / `fr`). Arrays are typed `Array<T>`.
HTTP goes through `useApi()`, and types live in `app/types/advertising.ts`.
Composables: `useAdPlatformAccountList`, `useAdPlatformAccount`,
`useAdCampaignList`, `useAdConversionMappings`, `useAdConversionDeliveryList`,
`usePaidAcquisitionReport`. Frontend `Permission` gains the three
`advertising.*` values.

## Navigation (`app/config/navigation.ts`)

```
MARKETING
  Campaigns
  Newsletters
  Announcements
  Advertising                    ← new group, Permission.AdvertisingView
    Overview      /marketing/advertising
    Ad campaigns  /marketing/advertising/campaigns
    Conversions   /marketing/advertising/conversions
  Templates …
  Audience
    Forms
    Segments

SETTINGS
  … Advertising  /settings/advertising   ← next to E-signature, Permission.AdvertisingConnect
```

Name the sub-page **"Ad campaigns"** (`nav.ad_campaigns`), never "Campaigns".
That label belongs to S31 broadcasts.

## Settings → Advertising (`pages/settings/advertising.vue`) — 0.75 day

- **Accounts card list.** Each card shows provider logo, name, external account
  id, currency, timezone, status chip (active / inactive / error with
  `last_error`), `last_verified_at`, and the last sync time from SystemEvents.
  Actions: Verify, Rotate credentials (Meta), Reconnect (Google OAuth), Deactivate,
  Archive.
- **Connect Google Ads** → OAuth popup → account picker modal (accessible
  customers, MCC shown as a group) → save.
- **Connect Meta** → form: ad account id, system-user token, pixel id, CAPI token.
  Masked fields follow the existing credential form behaviour: blank = unchanged,
  `••••••ab12` display. Reuse the comms credential field component.
- **Website tag card.** Copy-to-clipboard `<script>` snippet, the consent hook
  example, and a "Test my site" helper. The helper takes a URL and a test `gclid`,
  then polls for a touch with that `gclid` for 2 minutes.
- **Tracking templates card.** Recommended URL suffixes to paste into each platform:
  - Google: `utm_source=google&utm_medium=cpc&utm_campaign={campaignid}`
  - Meta: `utm_source=facebook&utm_medium=paid_social&utm_campaign={{campaign.id}}`

## Marketing → Advertising → Overview (`pages/marketing/advertising/index.vue`) — 0.75 day

Renders the native `paid-acquisition` report component (06). No separate
queries. Additions specific to this page:

- **Empty state** when no account is connected, with a CTA to Settings →
  Advertising.
- **Setup checklist** until complete: account connected · website tag seen
  (any touch in the last 7 days) · an enquiry form exists · conversion mappings
  set · every enabled campaign mapped. Each item links to where it's fixed. It
  disappears when all are done.
- A "View in Insights" link to the same report in the Insights nav.

## Ad campaigns (`pages/marketing/advertising/campaigns.vue`) — 0.5 day

- Table: name, platform, status, site, unit class, spend (period, per currency),
  paid leads, paid move-ins, cost per move-in.
- **Mapping status chip:** `Unmapped` (amber, like the contracts index attention
  chips) or `Suggested: {site}` with one-click accept.
- Row action **Map…** → drawer: site select, unit class (filtered by site),
  `utm_campaign` hint. Multi-select + **Map selected to site**.
- Filters: platform, status, mapped / unmapped, site. Period picker shared with
  Overview (query string).
- Read-only campaigns: no pause or budget controls in v1 (A10). Don't render
  disabled placeholders either.

## Conversions (`pages/marketing/advertising/conversions.vue`) — 0.5 day

Two tabs.

**Setup.** One block per account. Three rows (Lead, Reservation, Move-in), each
with a target select (from `conversion-targets`), a value rule select (Move-in
only: None / First-period rent), and an active toggle. **Send test event** button:
Google validate-only; Meta asks for the Events Manager test code. Shows the
result inline.

**Delivery log.**
- Summary bar: sent / skipped by reason / failed for the period, per account.
  Skip reasons show translated labels and help text, e.g. `no_consent` →
  "Contact hasn't agreed to ad measurement."
- Table: time, event, contact (link), account, status, reason, identifiers used
  (chips, names only), attempts. Failed rows get **Retry** (`advertising.manage`).
- Filters: account, status, reason, event, period.

## Audience → Forms (existing page) — 0.5 day

- Form editor gets a **Purpose** segmented control: Newsletter signup | Enquiry.
  It is disabled after the first submission, with a tooltip explaining why.
- Enquiry purpose shows: required **Site** select, enquiry field toggles (size,
  unit class with option picker filtered by site, move-in date, message), and the
  phone-required toggle. Subscription lists become optional, labelled "Offer
  marketing opt-in".
- Hosted page `pages/f/[token].vue` renders enquiry fields, reads the `t` param
  and posts it with the submission.
- Forms list: purpose column, plus a submissions count for enquiries (deals
  created + matched).

## Contact & deal surfaces — 0.25 day

- Contact detail: a **Marketing touches** card listing time, platform icon,
  utm_campaign and landing path. Click ids are never shown. The consent card shows
  the new `ads` row with operator grant/withdraw (03).
- Deal detail: an "Acquired via" chip (platform + campaign name when joinable),
  computed by the API from the attributed touch.

## Acceptance criteria

- [ ] `bun run lint` and `bun run typecheck` pass.
- [ ] A site-scoped employee sees only their sites' campaigns and no Settings →
      Advertising entry.
- [ ] Every new string exists in en/es/fr. No literal currency symbols
      (`Intl.NumberFormat` with the row's currency).
- [ ] Overview, Ad campaigns and the report share the period through the URL query.
- [ ] No click id value is rendered anywhere in the panel.
