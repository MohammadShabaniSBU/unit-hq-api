# S30-04 — Signup forms & double opt-in

## Schema

```sql
CREATE TABLE signup_forms (
    id BIGSERIAL PRIMARY KEY,
    public_token VARCHAR(48) NOT NULL UNIQUE,
    name VARCHAR(128) NOT NULL,
    site_id BIGINT NULL REFERENCES sites(id),         -- attributes new contacts / sender identity
    subscription_list_ids JSONB NOT NULL,             -- lists offered (checkboxes) or auto-joined
    channel VARCHAR(16) NOT NULL DEFAULT 'email',
    fields JSONB NOT NULL,                            -- ["first_name","last_name","phone"] + required flags
    consent_text_key VARCHAR(64) NOT NULL,            -- resolves latest consent_texts version in visitor locale
    double_opt_in BOOLEAN NOT NULL DEFAULT TRUE,
    confirmation_template_family_id BIGINT NULL REFERENCES template_families(id),
    success_redirect_url VARCHAR(500) NULL,
    allowed_origins JSONB NULL,                       -- CORS allowlist for embeds
    archived_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ, updated_at TIMESTAMPTZ
);

CREATE TABLE signup_confirmations (
    id BIGSERIAL PRIMARY KEY,
    signup_form_id BIGINT NOT NULL REFERENCES signup_forms(id),
    contact_id BIGINT NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
    token_hash CHAR(64) NOT NULL UNIQUE,
    consent_event_ids JSONB NOT NULL,                 -- the pending events this confirms
    expires_at TIMESTAMPTZ NOT NULL,                  -- 7 days
    confirmed_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL
);
```

## Public endpoints (allowlisted, invariant 42)

| Method | Path | Behaviour |
|---|---|---|
| `GET` | `public/forms/{token}` | Form definition + consent text in requested locale (for the embed script / hosted page). |
| `POST` | `public/forms/{token}/submit` | Validate → honeypot + time-to-submit check → `throttle:10,1` per IP → match contact by email (`ContactChannelMatcher`) or create one (`source = signup_form`, site from form) → write `pending` (double opt-in) or `granted` events with consent text + evidence → send confirmation. Always returns the same success shape (no enumeration of existing emails). |
| `GET` | `public/forms/confirm/{token}` | Panel page calls it: marks confirmation, writes `granted / source = double_opt_in` per pending event. Idempotent; expired → friendly message. |

Contact creation from a form is an explicit, documented exception to "inbound never
silently creates contacts" (invariant 40 covers inbound *messages*). Record it in 07.
New contacts get `ContactLifecycleStatus::Prospect`,
**no deal** (decision; deals stay a leasing action).

Confirmation email: **transactional** class (it's requested by the person), template
purpose `system`, token `{{confirm_url}}`. Fallback built-in template if the form
has none.

## Hosted page & embed

- Hosted: panel `app/pages/f/[token].vue` (`layout: 'blank'`, branded).
- Embed: a small `<script>` snippet that renders the form in an iframe pointing at the
  hosted page — no CORS work, no third-party JS in their site's DOM.

## Admin API & panel

CRUD `signup-forms` (`MarketingManage`), copy-embed-code button, submissions count
(derived from consent events with `evidence.form_id`). Panel: Marketing → Audience →
Forms.

## Acceptance criteria

- [ ] Double opt-in: event `pending` until confirmed; never a list member before.
- [ ] Re-submitting with an existing email doesn't duplicate the contact and returns
      the same response as a new email.
- [ ] Honeypot / too-fast submits are dropped silently; rate limit enforced.
- [ ] Expired confirmation link shows "please sign up again".
- [ ] Evidence records ip, user agent, form id, consent text version.
