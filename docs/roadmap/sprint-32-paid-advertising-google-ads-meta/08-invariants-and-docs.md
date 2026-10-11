# S32-08 — Invariants, docs & tests

## New invariants (`docs/09-conventions-and-invariants.md`)

85. **Enquiry forms may create a contact and at most one open deal per contact and
    site.** Extends 79. `EnquirySubmission` matches by email, then phone, creates a
    contact with `source = enquiry_form` when none matches, and creates a deal
    only when no open deal exists for that contact and the form's site. Otherwise
    it adds a note. Enquiry forms require a site. Subscribe forms still never
    create deals. Tests: `EnquiryFormTest` (`second_enquiry_same_site_adds_note_not_deal`,
    `enquiry_form_requires_site`), and `SignupFormTest` unchanged.
86. **Marketing touches are append-only facts. Attribution is computed.** The only
    updates are `contact_id` null → id (visitor stitching) and redaction of
    allowlisted columns. No table stores a deal's or contact's winning touch.
    Touches are written only with site-reported ad-storage consent. Click ids live
    only in `marketing_touches.click_ids`, never in `landing_url`.
87. **Ad platforms receive data only with granted `ads` consent, checked at
    delivery time.** `ConsentState::isGranted(contact, 'ads', advertising list)`
    gates every delivery (and S33 audience sync). Identifiers are hashed in
    process. `ad_conversion_deliveries` stores identifier names, never values.
    Soft opt-in is rejected for the advertising list. `ads` is a `ConsentChannel`
    case, not a comms `Channel`.
88. **One delivery per (ad account, conversion event), claim-before-send.** Unique
    `(ad_platform_account_id, ad_conversion_event_id)`. `dedupe_id` is stable,
    unique, and sent as Google `orderId` / Meta `event_id`. Conversion events are
    written inside the business transaction that produced them (deal insert,
    reservation insert, `ContractSigning::complete`). A rolled-back transaction
    leaves no event.
89. **Ad spend is an external, restated cache.** `ad_spend_daily` is upserted
    inside the trailing sync window only. Cost is converted from the platform's
    units with bcmath and never floats. Each row carries its account's currency.
    No report sums spend across currencies, and ROAS shows only when spend and
    revenue share a currency. `cost` is `NUMERIC(12,2)`, the documented exception
    to invariant 10's `(10,2)`, because it is not a ledger amount.
90. **Keevaris does not write to ad accounts' campaigns in v1.** Adapters expose
    read and conversion upload only. Any future write capability (S33) needs its
    own interface, confirmation, and Tier-3 audit.

Each entry gets "Enforced by …" lines naming the tests below, in the style of 79–84.

## Docs

- **New `docs/14-advertising.md`**: domain doc covering accounts, the touch model
  and attribution, enquiry forms, the conversion outbox, spend sync and mapping,
  and the report. Add it to the doc set index in `00-overview.md` and to
  `AGENTS.md`.
- `00-overview.md`: product scope table row "Advertising".
- `01-stack.md`: panel page map (Marketing → Advertising, Settings → Advertising).
- `06-communications.md`: one paragraph noting that the `ads` consent channel is
  not a send channel.
- `10-open-decisions.md`:
  - **Decided:** A1–A10 (short form, linking to this sprint), and the S30-04
    "never a deal" decision amended for enquiry purpose.
  - **Undecided:** attribution model beyond last-paid-touch (data-driven / position
    based); projected-LTV values; whether desk staff should capture `ads` consent
    on the walk-in flow; Meta app review vs operator system-user tokens long term.
  - **Out of scope:** campaign writes, audiences, lead-form intake, OpenAI (→ S33).
- `11-insights.md` / `report-definitions.md`: paid-acquisition section (06).
- `config/redaction.php` + `contacts:redact`: null `click_ids` (→ `{}`),
  `landing_url`, `referrer_host`, `utm_term`, `utm_content` on the contact's
  touches. Add a test.
- `analytics-schema.md`: the three new views.
- Demo world (`demo:seed`): one Google and one Meta fake account, touches on a
  share of the crowd's contacts, and synced spend, so the Overview isn't empty in
  demos. Adapters are swapped for fakes under `DEMO_SEED`. No network.

## Test matrix

| Area | Test class | Key cases |
|---|---|---|
| Schema | `AdvertisingSchemaTest` (+ `Pgsql\…`) | CHECKs, append-only triggers, stitching update allowed once |
| Accounts | `AdPlatformAccountTest` | Masking, blank = unchanged, OAuth state replay, verify sets currency/timezone, audit rows |
| Tag & touches | `TouchRecorderTest` | Consent gate, click-id stripping, platform derivation, 90-day clamp, stitching |
| Enquiry | `EnquiryFormTest` | Invariant 85 cases, purpose lock, list optional |
| Consent | `AdvertisingConsentTest` | System list, soft opt-in rejected, preference center toggle |
| Outbox | `ConversionOutboxTest` | In-transaction write, rollback leaves nothing, dedupe, value rule |
| Delivery | `ConversionDeliveryTest` | Claim race, gate order, identifier rules per platform, retry, no PII in rows, request shapes |
| Sync | `AdSyncTest` | Mapping preserved, idempotent upsert, micros rounding, currency mismatch, failure isolation |
| Report | `PaidAcquisitionReportTest` | Currency grouping, window, unmapped row, ROAS guard |
| Redaction | `ContactRedactionTest` (extend) | Touch columns nulled; deliveries untouched and PII-free |
| RBAC | existing coverage test | New routes covered |

## Acceptance criteria

- [ ] Invariants 85–90 are in `09` with "Enforced by" test names that exist.
- [ ] `docs/14-advertising.md` is indexed in `00-overview.md` and `AGENTS.md`.
- [ ] `demo:seed --fresh` produces a non-empty Paid acquisition report.
- [ ] The full suite passes on SQLite, and the pgsql suite passes in CI.
