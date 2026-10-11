# S32-03 — Advertising consent

## Model

Reuse the S30 consent ledger. Don't add a parallel store.

- New `SubscriptionListKind::Advertising`.
- A **system** list `advertising` (`is_system = true`, `is_public = false`,
  `requires_double_opt_in = false`, `channels = ["ads"]`), seeded by migration
  like the other system lists. It does not appear in the preference center as a
  "newsletter". The preference center gets a separate toggle "Use my data to
  measure and personalise ads" that writes/withdraws on this list.
- Consent channel `ads` (decision in 00).

`ConsentState::isGranted($contactId, 'ads', $advertisingListId)` is the **only**
gate used by 04, and later by S33 audience sync.

## Writers

| Writer | `ConsentSource` | When |
|---|---|---|
| Enquiry / signup submission | `form` | Site CMP reported `ad_user_data = granted` in the tag payload. Evidence: form id, consent snapshot, consent text version. |
| Operator | `operator` | Manual grant/withdraw on the contact's consent card, with a required note. (Covers desk enquiries where the person agreed on paper.) |
| Preference center | `preference_center` | Toggle above |
| Redaction | `redaction` | `contacts:redact` (existing path; nothing new to build) |

No soft opt-in for `ads`. `SoftOptIn` is rejected for this list (validation in
`ConsentWriter`), because EU ad-data sharing needs an affirmative signal.

## Consent text

New `consent_texts` key `advertising`. Seed an en/es/fr v1 body, editable in
Settings → Marketing → Consent texts (existing page).

## Acceptance criteria

- [ ] The system list exists after migration and can't be archived or edited
      (existing `is_system` guard).
- [ ] An enquiry with `ad_user_data = granted` writes a `granted` event on
      (`ads`, advertising list) with evidence. With `denied`, no event.
- [ ] A `SoftOptIn` write on the advertising list throws.
- [ ] A preference-center withdrawal makes `isGranted` false immediately (04 then
      skips `no_consent`).
- [ ] `analytics.v_consent_state` shows the new channel without a view change.
      Verify; if the view hard-codes channels, ship a `CREATE OR REPLACE` migration.
