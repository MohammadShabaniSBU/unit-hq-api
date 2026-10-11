# S32-02 — Attribution capture & enquiry forms

## Problem

S30-04 embeds forms as an **iframe** pointing at our hosted page. The iframe can't
see the parent page's URL or cookies, so the `gclid` in the browser bar never
reaches us. A visitor also often lands on the homepage and only opens the form two
pages later. We need a small first-party tag on the operator's site.

## Tracking tag — `keevaris-tag.js`

Served by the API (`GET /public/tag.js`, long cache, versioned query string).
The operator pastes it on every page of their site, once, from Settings →
Integrations → Advertising (07).

Behaviour:

1. **Consent first.** The tag stores nothing until the site's CMP signals ad
   storage consent, by either route:
   - a Google Consent Mode v2 `ad_storage = granted` update on `dataLayer`, or
   - an explicit `window.keevaris('consent', { ad_storage: true, ad_user_data: true, ad_personalization: true })`.
   Without consent it does nothing (no cookie, no request).
2. **On landing**, it reads `gclid`, `gbraid`, `wbraid`, `fbclid`, `utm_*` from
   `location.search`, plus the `_fbp` and `_fbc` cookies when present. Meta's
   `fbc` is built as `fb.1.{ms}.{fbclid}` when only `fbclid` exists. It keeps
   the latest paid touch in a first-party cookie `_kv_touch` (90 days,
   `SameSite=Lax`) and a random `_kv_vid` visitor key.
3. **On form embed**, the existing embed snippet asks the tag for the stored
   touch and appends it to the iframe `src` as one signed-free, size-capped
   `t=` base64url JSON param. The hosted page posts it with the submission.

Keep it small, with no dependencies. Ship it as a static file built from
`resources/js/tag/`.

The tag also reserves `oai_ref` for OpenAI. It captures the param when present,
so S33 has data from day one.

## Touch write path

`App\Support\Advertising\TouchRecorder::fromSubmission(SignupForm, Contact, array $touch, array $consent)`

- Validates and caps every field (lengths per 00). Unknown keys are dropped.
- Derives `platform` from which click id is present (`CapturesClickIds` per
  adapter). Falls back to `utm_source` for `google` / `facebook` / `instagram` /
  `chatgpt` when no click id exists.
- Strips click ids from `landing_url` before storing. They live in `click_ids` only.
- Stores `referrer_host` only.
- `occurred_at` = landing time from the tag, clamped to `[now − 90 days, now]`.
- **Visitor stitching:** if earlier touches exist with the same `visitor_key` and
  `contact_id IS NULL`, set their `contact_id` once (the only permitted update,
  see 00).

Touches are written **only** when the payload's consent snapshot has
`ad_storage = true`. Otherwise nothing is written: no row, no partial row.

## Enquiry forms

`signup_forms.purpose`: `subscribe` (S30 behaviour, unchanged) | `enquiry`.

`enquiry_fields` JSONB configures what the enquiry asks for:

```json
{ "desired_size": {"enabled": true, "required": false},
  "unit_class_id": {"enabled": true, "required": false, "options": [3, 5, 9]},
  "expected_move_in": {"enabled": true, "required": true},
  "message": {"enabled": true, "required": false} }
```

Enquiry rules:

- `site_id` is **required** on an enquiry form. A deal needs a site for D-RBAC-1
  list visibility and for cost-per-move-in.
- Phone is required by default on enquiry (operators can make it optional).
- `subscription_list_ids` becomes **optional** for enquiry. Marketing opt-in is
  an unticked checkbox, never a precondition of enquiring. The consent text still
  covers processing the enquiry. Its key is configured as for subscribe forms.
- Submission, in one transaction (`EnquirySubmission`, sibling of
  `SignupSubmission`, sharing honeypot/timing/throttle helpers):
  1. Match the contact by email (`ContactChannelMatcher`), then by phone. If neither
     matches, create one with `source = enquiry_form` and lifecycle `Prospect`.
  2. **Open-deal check:** if the contact already has an open deal for this site,
     do not create another. Add a Note on the existing deal ("New web enquiry",
     machine key per invariant 14) and refresh `expected_*` fields only when they
     were empty.
  3. Otherwise create a Deal (first pipeline stage, `site_id` from the form,
     `expected_move_in` / `desired_size` / `desired_unit_class_id` from the
     submission).
  4. Write consent events for any ticked lists (S30 path), plus the `ads` consent
     from 03 when the site's CMP granted `ad_user_data`.
  5. `TouchRecorder::fromSubmission` (above).
  6. Write the `lead` conversion event (04) when a deal was **created** in step 3.
- The response shape matches subscribe forms. No enumeration of existing emails
  or deals.

This creates a deal from public input. Add invariant 85 (08) extending 79:
*enquiry forms may create a contact and at most one open deal per contact and
site*.

## API changes

- `signup-forms` CRUD accepts `purpose` and `enquiry_fields`. `purpose` is
  immutable after the first submission (409 `purpose_locked`), because
  submissions counts must keep one meaning.
- `GET public/forms/{token}` returns `purpose` and the enquiry field config
  (unit class options resolved to localized labels + `site` name).
- `POST public/forms/{token}/submit` accepts the optional `t` touch payload and
  enquiry fields.

## Acceptance criteria

- [ ] With no CMP consent, the tag sets no cookie and the submission writes no touch.
- [ ] Landing on `/` with `?gclid=X&utm_campaign=spring` and submitting from
      `/contact` two pages later stores a touch with `click_ids.gclid = X`,
      `platform = google_ads`, `utm_campaign = spring`, and a landing URL without
      `gclid`.
- [ ] An enquiry from a new email creates a contact (`source = enquiry_form`)
      and one deal on the form's site.
- [ ] A second enquiry by the same person for the same site adds a note and no
      second deal. A different site creates a second deal.
- [ ] An enquiry form without `site_id` cannot be saved.
- [ ] Subscribe forms behave exactly as before. The S30 `SignupFormTest` passes
      unchanged.
- [ ] Changing `purpose` after a submission returns 409.
