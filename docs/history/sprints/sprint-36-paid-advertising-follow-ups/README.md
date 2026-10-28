# Sprint 36 — Paid advertising follow-ups

## Goal

Finish the paid-acquisition loop that S32 opened:

1. **Keep Google conversions working.** S32 uploads Google conversions through
   `ConversionUploadService.UploadClickConversions` (`GoogleAdsAccount.php`). Since
   15 June 2026 Google rejects that method for developer tokens that hadn't used it
   between December 2025 and May 2026 (`CUSTOMER_NOT_ALLOWLISTED_FOR_THIS_FEATURE`).
   A newly approved Keevaris token is outside that allowlist, so Google move-in
   conversions will fail in production. Move delivery to the **Data Manager API**.
2. **Bring platform lead forms in.** Google lead form assets and Meta Lead Ads become
   contacts and deals through the same enquiry rules as our own forms.
3. **Add ChatGPT Ads (OpenAI).** Live in Spain since 24 August 2026, with a Pixel and a
   Conversions API. Capture its click reference correctly and send conversions.
4. **Sync audiences.** Segments → Meta Custom Audiences and Google Customer Match,
   including a current-tenants exclusion list, behind the `ads` consent gate.
5. **Better move-in values.** Optionally send a projected stay value instead of first
   period rent, so bidding optimises for tenants who stay.

S32 built: accounts and adapters, touches and attribution, enquiry forms, `ads`
consent, the conversion outbox, spend sync, the `paid-acquisition` report and the
panel. This sprint extends those pieces; it adds no parallel system.

## Design decisions (lock at kickoff)

| # | Decision | Rationale |
|---|---|---|
| F1 | Google conversion delivery and Customer Match uploads go through the **Data Manager API** (`datamanager.googleapis.com`, scope `…/auth/datamanager`). The Google Ads API stays for account verify, campaign/spend sync, conversion-target listing and user-list creation. | Google closed both upload paths in the Ads API to new adopters (Customer Match on 1 April 2026, offline conversions on 15 June 2026). |
| F2 | Existing Google accounts must **reconnect** once to grant the added scope. Until they do, deliveries skip with `reauth_required`; nothing is lost (rows stay `pending`). | OAuth scopes can't be added silently. Skips must be visible, never silent failures. |
| F3 | Platform lead forms enter through **one intake path** shared with S32 enquiry forms (`EnquiryIntake`), so invariant 85 (one open deal per contact and site) holds for every source. | No second deal-creation path. |
| F4 | Lead-form leads get **no `ads` consent by default.** Consent is written only when the platform form carries an explicit consent question that the operator maps to `ads`. | Invariant 87. A platform lead form is not ad-measurement consent for our CRM. Needs legal confirmation (Prerequisites). |
| F5 | **Meta Lead Ads are polled**, not webhooked. Google lead forms use Google's webhook (URL + key per form). | Meta webhooks need a Keevaris Meta app with app review; polling works with the operator's system-user token, same as S32. |
| F6 | OpenAI is a full adapter for **conversions only**: Pixel ID + Conversions API key, click reference `oppref`, browser reference `__obref`. Spend sync and audiences for OpenAI wait for Advertiser API access (S37). | Conversions API is public and documented; the Advertiser API is partner-gated. |
| F7 | S32's reserved click param `oai_ref` was a placeholder. The real reference is **`oppref`**. Fix the tag and `TouchRecorder`; no data migration (no production touches carry it). | Correctness. |
| F8 | Audiences are **derived sets, synced as diffs.** `ad_audience_members` stores which contacts are in a remote audience (ids and an identifier fingerprint), never identifier values. Membership requires granted `ads` consent at every sync. | Invariants 5, 87. Withdrawal or redaction must remove the person from every remote audience. |
| F9 | A system **"Current tenants"** source (contacts with an active contract) is available for exclusion audiences. Lookalikes are created by the operator in Meta / Google from our audience; we don't create them. | Most valuable audience for self storage is "don't pay to reach people already renting". Lookalike creation is a campaign-side write (S37). |
| F10 | Projected value is an opt-in **value rule** `projected_stay`. Expected stay comes from a dated, append-only **stay projection** snapshot (site × unit class), refreshed nightly; each move-in event records which snapshot it used. | Explainable values; no in-transaction heavy query; same effective-dated catalogue pattern as prices. |

## Prerequisites (start before day 1)

- **Google Cloud:** enable the Data Manager API on the Keevaris project and add the
  `https://www.googleapis.com/auth/datamanager` scope to the OAuth consent screen
  (sensitive scope → verification may take days).
- **Meta:** the operator's system user needs `ads_management` (audiences),
  `leads_retrieval`, `pages_show_list`, `pages_read_engagement`, `pages_manage_ads`
  on the Page that owns the lead forms, and the Custom Audience terms accepted in
  Business Manager.
- **OpenAI:** the operator creates a Pixel and a Conversions API key in ChatGPT Ads
  Manager → Conversions. Confirm in the Pixel docs which landing-URL parameter
  carries `oppref` (task 03 assumes `oppref`).
- **Legal (blocks F4 wording only, not the build):** confirm that platform lead forms
  need an explicit consent question before we send conversions about those leads,
  and the wording for es/en/fr.

## Exit criteria

- [ ] On a token with no Ads-API upload history, a signed contract reaches Google Ads
      as a conversion through the Data Manager API with `transactionId = dedupe_id`.
- [ ] A Google account connected before this sprint shows **Reconnect required**;
      its pending deliveries resume after reconnecting.
- [ ] A Google lead form submission and a Meta Lead Ads submission each create one
      contact and at most one open deal at the mapped site, with a touch carrying the
      campaign, and appear in the Paid acquisition report as paid leads.
- [ ] A visitor from a ChatGPT ad with `oppref` in the URL who enquires and later signs
      produces an OpenAI `lead_created` and a move-in event, both delivered.
- [ ] A segment synced to Meta and Google contains only contacts with granted `ads`
      consent; withdrawing consent removes the contact from both within one sync
      cycle; `contacts:redact` removes them before identifiers are nulled.
- [ ] With `projected_stay` on, a move-in conversion carries first-period rent ×
      projected months for its site and unit class, and the delivery row names the
      projection snapshot used.

## Task order

| # | Task | Est. |
|---|---|---|
| 00 | [Schema, enums & permissions](./00-schema.md) | 1 day |
| 01 | [Google: Data Manager API migration](./01-google-data-manager.md) | 1.5 days |
| 02 | [Lead-form intake (Google & Meta)](./02-lead-form-intake.md) | 2.5 days |
| 03 | [OpenAI adapter](./03-openai-adapter.md) | 1.5 days |
| 04 | [Audience sync](./04-audience-sync.md) | 3 days |
| 05 | [Projected move-in value](./05-projected-value.md) | 1 day |
| 06 | [Panel](./06-panel.md) | 3 days |
| 07 | [Invariants, docs & tests](./07-invariants-and-docs.md) | 0.5 day |

**Total ≈ 14 days.** 00 first. **01 is the priority** — it fixes a production failure
and should merge on its own as soon as it's green. 02, 03 and 05 can run in parallel
after 00. 04 needs 01 (Customer Match uploads use the Data Manager client). The
panel starts once 00 freezes the API shapes.

## Out of scope

- Writes to campaigns, ad groups or budgets, and occupancy-aware pause/resume
  (invariant 90 still holds) → S37.
- Call tracking numbers per campaign (Aircall) → S37.
- OpenAI spend sync, campaign list and custom audiences (Advertiser API) → S37.
- Creating lookalike / similar audiences from Keevaris.
- Lead-form intake from other platforms (LinkedIn, TikTok).

## Dependencies on other sprints

- Builds on S30 (consent ledger, segments), S32 (everything advertising).
- Invariant numbers assume S33–S35 added 91–95. Renumber in 07 if they haven't merged.

## Next (S37)

Campaign control behind a write capability with confirmation, Tier-3 audit and a
kill switch, plus automation actions to pause or resume campaigns when a unit class
fills or frees up; call tracking numbers via Aircall with dynamic number insertion in
the tag; OpenAI Advertiser API (spend, campaigns, audiences) once partner access exists.
