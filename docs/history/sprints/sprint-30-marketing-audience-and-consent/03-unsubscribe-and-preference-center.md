# S30-03 — Unsubscribe & preference center

## Problem

`UnsubscribeToken` encodes only an email address and
`GET /comms/unsubscribe/{token}` writes a marketing suppression on GET. Link scanners
(Microsoft Defender, Mimecast…) prefetch links, so people get unsubscribed without
clicking. There's also no way to leave one list but keep another.

## Token

`PreferenceToken` (new, HMAC like `UnsubscribeToken`):
payload `{c: contact_id, ch: channel, a: normalized_address, l: list_id|null, m: message_id|null, v: 1}`,
signed with a **dedicated key** (`config('comms.preference_token_key')`, falling back to
`app.key`). No expiry (unsubscribe links must work forever), but `v` allows rotation.

`UnsubscribeToken` stays valid for already-sent emails: old tokens resolve to
"all lists, this address".

## Endpoints (public — add to the public-route allowlist with comments, invariant 42)

| Method | Path | Behaviour |
|---|---|---|
| `GET` | `comms/preferences/{token}` | JSON for the panel page: contact first name, lists × channels with current state, list labels in contact locale. **No writes.** |
| `POST` | `comms/preferences/{token}` | Body `{changes: [{list_id, channel, subscribed}], unsubscribe_all?: bool}` → consent events `source = preference_center`; `unsubscribe_all` also writes the marketing suppression. |
| `POST` | `comms/unsubscribe/{token}` | RFC 8058 one-click: withdraw the token's list (or all if none) + suppression when all. Returns `200 OK`. |
| `GET` | `comms/unsubscribe/{token}` | **Changed:** no write; 302 to the panel preference page. |

Rate-limit both (`throttle:30,1` per IP). Evidence on events: ip, user agent,
`message_id` from the token.

`EmailSender` (marketing class): `List-Unsubscribe` header points to the new
`POST comms/unsubscribe/{PreferenceToken}`; keep `List-Unsubscribe-Post`.

## Panel public page

`app/pages/preferences/[token].vue`, `layout: 'blank'` (same pattern as
`pay/[token].vue`), branded via `GET branding`. Shows toggles per list/channel and an
"Unsubscribe from all marketing" button. i18n en/es/fr. Works without login.

## Tokens available to templates (used by S31 footer)

`{{preferences_url}}`, `{{unsubscribe_url}}` — resolved per recipient at render time.
Add to `SubjectTokenBag::sample()` (S30-00).

## Acceptance criteria

- [ ] GET on either URL never changes consent or suppression (test).
- [ ] One-click POST from a Gmail-style request returns 200 and withdraws.
- [ ] Leaving "Newsletter" keeps "Promotions" granted.
- [ ] Old `UnsubscribeToken` links still work (redirect → page → unsubscribe all).
- [ ] Tampered token → 404; `RouteAuthCoverageTest` passes.
