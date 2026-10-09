# S30-01 — Consent ledger

## Schema

```sql
CREATE TABLE consent_texts (
    id BIGSERIAL PRIMARY KEY,
    key VARCHAR(64) NOT NULL,                -- e.g. 'newsletter_signup', 'contract_marketing_checkbox'
    version INT NOT NULL,
    locale VARCHAR(5) NOT NULL,
    body TEXT NOT NULL,                      -- exact wording shown to the person
    created_by BIGINT NULL REFERENCES employees(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL,
    UNIQUE (key, version, locale)
);
-- immutable: no updated_at; trigger rejects UPDATE/DELETE (same pattern as template_versions)

CREATE TABLE consent_events (
    id BIGSERIAL PRIMARY KEY,
    contact_id BIGINT NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
    channel VARCHAR(16) NOT NULL,            -- email | sms | whatsapp
    subscription_list_id BIGINT NOT NULL REFERENCES subscription_lists(id),  -- 02; M1
    action VARCHAR(16) NOT NULL,             -- granted | pending | withdrawn
    source VARCHAR(24) NOT NULL,             -- form | double_opt_in | operator | import
                                             -- | preference_center | unsubscribe_link
                                             -- | one_click | stop_keyword | complaint
                                             -- | soft_opt_in | redaction
    consent_text_id BIGINT NULL REFERENCES consent_texts(id),
    evidence JSONB NULL,                     -- ip, user_agent, form_id, message_id, import_batch …
    causer_type VARCHAR(64) NULL, causer_id BIGINT NULL,
    occurred_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL
);
CREATE INDEX ce_state_idx ON consent_events (contact_id, channel, subscription_list_id, occurred_at DESC, id DESC);
CREATE INDEX ce_list_idx  ON consent_events (subscription_list_id, channel, occurred_at DESC);
```

Append-only (trigger rejects UPDATE/DELETE except the redaction path below). Migration
ordering: `subscription_lists` (02) before `consent_events`.

**Rules enforced by `ConsentWriter`:**

- `granted` requires a `source` in {form, double_opt_in, operator, import,
  preference_center, soft_opt_in} **and** `consent_text_id` (except `operator`, which
  requires a causer and a free-text `evidence.note`).
- `import` grants require `evidence.import_batch` + `evidence.original_source`.
- `pending` is only written by signup forms awaiting double opt-in (04).
- Automations / AI tools can call `ConsentWriter::withdraw()` only (M4) — no grant API
  is exposed to `ToolRegistry` or node handlers.

## Read model

`ConsentState` (query service, no table):

```php
ConsentState::for(Contact $c): Collection          // [list, channel, action, occurred_at, source]
ConsentState::isGranted(int $contactId, Channel $ch, int $listId): bool
ConsentState::grantedQuery(Channel $ch, int $listId): Builder   // contact ids, for S31 recipient materialisation
```

Implement `grantedQuery` with `DISTINCT ON (contact_id) … ORDER BY contact_id,
occurred_at DESC, id DESC` and filter `action = 'granted'`. Add a test with 10k events
to keep the plan on `ce_list_idx`.

## Wiring existing signals

| Existing signal | New consent effect |
|---|---|
| `SuppressionWriter::fromUnsubscribe` (one-click POST) | `withdrawn` on **all lists** for that channel, contacts matched by address (M5). |
| STOP keyword (SMS inbound) | `withdrawn`, `source = stop_keyword`, all lists for SMS. |
| Complaint webhook | `withdrawn`, `source = complaint`, all lists for email. |
| Hard bounce | **No consent change** — suppression `all` already blocks sending. |

## GDPR redaction

Extend `RedactContactCommand`: for each consent event set `evidence = NULL`,
keep `action`, `source`, `occurred_at`, `consent_text_id`; append a
`withdrawn / source = redaction` event per (channel, list) still granted. The
suppression floor already keeps the address blocked.

## API

| Method | Path | Permission |
|---|---|---|
| `GET` | `contacts/{contact}/consents` | `ContactView` — current state grid + history |
| `POST` | `contacts/{contact}/consents` | new `ConsentManage` — `{channel, subscription_list_id, action, consent_text_id?, note}` |
| `GET/POST` | `consent-texts` | `ConsentManage` — create = new version |

Activity: Tier-2 `contact.consent.granted` / `contact.consent.withdrawn` on the
contact (`LogChannel::Comms`), keys only.

## Panel

Contact detail → **Consent** tab: list × channel grid with current state, source,
date; history drawer; "Record consent" modal requiring the consent text and a note.

## Acceptance criteria

- [ ] Grant without text/evidence rejected; operator grant requires note.
- [ ] Latest-event-wins table-tested incl. same-timestamp tie-break on id.
- [ ] One-click unsubscribe withdraws all lists for that address's contacts.
- [ ] Redaction nulls evidence and leaves the contact withdrawn everywhere.
- [ ] No automation node / AI tool can grant (grep test on `ConsentWriter::grant`).
