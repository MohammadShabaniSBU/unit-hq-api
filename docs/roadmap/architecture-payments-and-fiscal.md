# Architecture — Payments & Fiscal Identity

> **Status: authoritative.** This document is the single source for payments, fiscal identity
> and invoice issuance. Where `05-billing-ledger.md`, `10-open-decisions.md` or the roadmap
> README disagree, this document wins. Last reconciled: 2026-07-31, S01-05.
>
> Implemented across S03–S07. **Read before writing any invoice, payment or tax code.**

---

## 1. `legal_entities` — the invoice issuer

### Why it exists

Stripe Connect was dropped because there is no platform distributing money to connected
accounts — sites are not platforms, and the operator is not a money transmitter. Dropping
Connect removes the PSD2 money-transmitter question outright rather than mitigating it.
The underlying reason sites cannot share one Stripe account is that they may be **separate
legal entities** with separate tax IDs. That fact propagates well beyond payments:

| Concern | Scoped to |
|---|---|
| Verifactu hash chain | Issuer NIF — one chain per entity |
| Invoice series and numbering | Entity (an entity may have several series) |
| VAT registration and rates | Entity's country |
| Stripe credentials | Entity |
| SEPA creditor identifier | Entity |
| Bank account for SDD collection | Entity |

Nothing in the current schema represents this. Sites carry currency and country but not
fiscal identity.

### Schema

```sql
CREATE TABLE legal_entities (
    id                  BIGSERIAL PRIMARY KEY,
    legal_name          VARCHAR(255) NOT NULL,
    trading_name        VARCHAR(255) NULL,
    tax_id              VARCHAR(64) NOT NULL,      -- NIF / SIREN / VAT no.
    tax_id_type         VARCHAR(16) NOT NULL,      -- nif | siren | uk_crn | vat
    vat_number          VARCHAR(64) NULL,
    country_code        CHAR(2) NOT NULL,
    address_line1       VARCHAR(255) NOT NULL,
    address_line2       VARCHAR(255) NULL,
    city                VARCHAR(128) NOT NULL,
    postal_code         VARCHAR(32) NOT NULL,
    fiscal_regime       VARCHAR(24) NOT NULL DEFAULT 'none',
    sepa_creditor_id    VARCHAR(64) NULL,          -- ES + FR SDD
    created_at          TIMESTAMP,
    updated_at          TIMESTAMP
);

ALTER TABLE sites ADD COLUMN legal_entity_id BIGINT NULL REFERENCES legal_entities(id);
-- becomes NOT NULL after backfill
```

Backfill creates exactly one entity from existing org settings and points every site at it.
Installs with a single entity are unaffected in practice.

### This is not multi-tenancy — and must not become it

`legal_entity_id` looks like the `company_id` that invariant 1 forbids. The distinction:

| Multi-tenancy (forbidden) | Legal entity (this) |
|---|---|
| Scopes every query | Scopes nothing |
| Isolates data between customers | All data visible to all staff |
| Global scope on models | Plain FK, read explicitly |
| Routes connections / jobs | No routing |

**New invariant to add to `09-conventions-and-invariants.md`:**

> **`legal_entities` is a fiscal domain concept, not a tenancy boundary.** It identifies the
> issuer of an invoice and the holder of payment credentials. It must never appear in a
> global scope, a middleware-applied filter, a queue payload context, or a default query
> constraint. Any code that filters a list by `legal_entity_id` for isolation purposes is a
> defect. Filtering an invoice *series* by entity is correct; filtering *contacts* by entity
> is not.

Review this invariant at every sprint retro. It is the one that will erode quietly.

### Open question — blocking S03

**Is the Spanish client one legal entity with several sites, or several entities?**
If one, the table has a single row and costs nothing but future-proofing. If several,
invoice series and Stripe credentials must hang off the entity from day one and the panel
needs an entity management screen in Settings. Confirm before S03 begins.

---

## 2. Payments — two rails, different confirmation semantics

### Rail A — Stripe, per legal entity

Each entity holds its own Stripe account and its own credentials. There is no platform
account and no transfer between accounts.

```sql
CREATE TABLE payment_provider_accounts (
    id                    BIGSERIAL PRIMARY KEY,
    legal_entity_id       BIGINT NOT NULL REFERENCES legal_entities(id),
    provider              VARCHAR(32) NOT NULL,      -- stripe
    display_name          VARCHAR(128) NOT NULL,
    credentials_encrypted TEXT NOT NULL,             -- secret key
    webhook_secret_encrypted TEXT NOT NULL,
    publishable_key       VARCHAR(255) NULL,
    is_active             BOOLEAN NOT NULL DEFAULT true,
    created_at            TIMESTAMP,
    updated_at            TIMESTAMP
);

CREATE UNIQUE INDEX ppa_entity_provider_idx
    ON payment_provider_accounts (legal_entity_id, provider) WHERE is_active;
```

Credentials use Laravel's encrypted casts. They are never returned by the API — the resource
exposes a masked suffix only.

**Webhook routing is the tricky part.** Each Stripe account signs with its own secret, so a
single shared endpoint cannot verify a signature without first knowing which account sent
the event. Use a per-account endpoint path carrying an opaque, non-guessable token:

```
POST /api/webhooks/stripe/{account_token}
```

`account_token` is a crypto-random column on `payment_provider_accounts`, generated on
create, used to look up the signing secret before verification. This mirrors the existing
`offers.token` pattern (invariant 6 — never expose the PK).

`stripe_webhook_events` gains `payment_provider_account_id`. Idempotency keys are unique
**per account**, not globally — two accounts can legitimately emit the same event id.

### Rail B — bank-native SEPA Direct Debit

This is how the Spanish client works today: the software produces a file, a human hands it
to the bank. There is no processor and no webhook.

**Flow:**

1. **Mandate.** The tenant signs an SDD mandate. Stored with mandate reference, signature
   date, scheme (`CORE`), sequence type (`FRST` then `RCUR`), debtor IBAN, and status.
2. **Pre-notification.** Statutory 14 days before collection, contractually reducible —
   Spanish operators typically set 1–2 days in the contract terms. The billing run must emit
   this notice, and the contract must record the agreed notice period. **This sets the floor
   on how far ahead the recurring job runs.**
3. **Run.** Instructions are batched into a `direct_debit_run` and exported as
   Cuaderno 19-14 / `pain.008.001.02`. Generating a direct-debit collection file is **not**
   a payment — **no payment rows are written.**
4. **Settlement.** On the settlement date the run is marked collected and payment rows are
   written against the ledger.
5. **Return.** Days later, a returns file (Cuaderno 19-44 / `pain.002`) is imported. Each
   return writes a **reversal payment** via `reversal_of_payment_id`, plus an optional
   `returned_fee` charge where the contract permits one.

```sql
CREATE TABLE sepa_mandates (
    id                BIGSERIAL PRIMARY KEY,
    contact_id        BIGINT NOT NULL REFERENCES contacts(id),
    legal_entity_id   BIGINT NOT NULL REFERENCES legal_entities(id),
    mandate_reference VARCHAR(35) NOT NULL,     -- SEPA max length
    debtor_name       VARCHAR(255) NOT NULL,
    debtor_iban       VARCHAR(34) NOT NULL,     -- encrypted at rest
    debtor_bic        VARCHAR(11) NULL,
    scheme            VARCHAR(8) NOT NULL DEFAULT 'CORE',
    sequence_type     VARCHAR(4) NOT NULL DEFAULT 'FRST',
    signed_on         DATE NOT NULL,
    signature_method  VARCHAR(24) NOT NULL,     -- paper | esign | recorded
    revoked_at        TIMESTAMP NULL,
    created_at        TIMESTAMP,
    updated_at        TIMESTAMP
);

CREATE UNIQUE INDEX sepa_mandate_ref_idx
    ON sepa_mandates (legal_entity_id, mandate_reference);

CREATE TABLE direct_debit_runs (
    id               BIGSERIAL PRIMARY KEY,
    legal_entity_id  BIGINT NOT NULL REFERENCES legal_entities(id),
    collection_date  DATE NOT NULL,
    status           VARCHAR(24) NOT NULL,  -- draft | exported | collected | reconciled
    file_generated_at TIMESTAMP NULL,
    file_reference   VARCHAR(64) NULL,
    total_amount     NUMERIC(10,2) NOT NULL,
    instruction_count INTEGER NOT NULL,
    created_by       BIGINT NULL REFERENCES employees(id),
    created_at       TIMESTAMP,
    updated_at       TIMESTAMP
);

CREATE TABLE direct_debit_instructions (
    id                    BIGSERIAL PRIMARY KEY,
    direct_debit_run_id   BIGINT NOT NULL REFERENCES direct_debit_runs(id),
    contract_id           BIGINT NOT NULL REFERENCES contracts(id),
    sepa_mandate_id       BIGINT NOT NULL REFERENCES sepa_mandates(id),
    amount                NUMERIC(10,2) NOT NULL,
    end_to_end_id         VARCHAR(35) NOT NULL,
    payment_id            BIGINT NULL REFERENCES payments(id),  -- set at settlement
    status                VARCHAR(24) NOT NULL,  -- pending | collected | returned
    return_code           VARCHAR(8) NULL,       -- AC04, MS03, …
    returned_at           TIMESTAMP NULL,
    created_at            TIMESTAMP,
    updated_at            TIMESTAMP
);
```

Runs and instructions are append-only in spirit: status advances, rows are never deleted.
A cancelled run is marked, not removed.

### Invariant 11 (rail-specific)

> **11. Payment confirmation is rail-specific, and never optimistic from the client.**
> - **Stripe:** payments are written only on receipt of a verified webhook, with per-account
>   idempotency keys. Never from a client-side success callback.
> - **Bank SEPA DD:** generating a direct-debit collection file is **not** a payment. A
>   payment is written on the run's settlement date. A return, imported from the bank's
>   returns file, writes a reversal payment via `reversal_of_payment_id` — never an edit or
>   delete.
> - **Manual (cash, transfer):** written by an authenticated employee with a recorded causer.
>
> In all cases the ledger is the system of record; provider events are reconciled inputs.

### Payment methods

```sql
CREATE TABLE payment_methods (
    id               BIGSERIAL PRIMARY KEY,
    contact_id       BIGINT NOT NULL REFERENCES contacts(id),
    type             VARCHAR(24) NOT NULL,  -- stripe_card | stripe_sepa | bank_sdd | manual
    sepa_mandate_id  BIGINT NULL REFERENCES sepa_mandates(id),
    stripe_pm_id     VARCHAR(64) NULL,
    payment_provider_account_id BIGINT NULL REFERENCES payment_provider_accounts(id),
    display_label    VARCHAR(64) NOT NULL,   -- "Visa ···4242", "ES·· ···· 1234"
    is_default       BOOLEAN NOT NULL DEFAULT false,
    archived_at      TIMESTAMP NULL,
    created_at       TIMESTAMP,
    updated_at       TIMESTAMP
);
```

Autopay is configured **per contract**, not per contact — a tenant may pay one unit by card
and another by direct debit. Add `contracts.payment_method_id` (nullable) and
`contracts.autopay_enabled`.

---

## 3. Verifactu — regime flag, not a boolean

### The states

`legal_entities.fiscal_regime`:

| Value | Chain required | AEAT submission | Notes |
|---|---|---|---|
| `none` | No | No | UK, France, and any non-ES entity |
| `verifactu` | **Yes** | **Yes, real time** | Full compliance |
| `no_verificable` | **Yes** | No — held locally, produced on inspection | Still fully regulated |
| `ticketbai` | — | — | Basque Country. **Not implemented.** Reject at validation. |
| `sii` | — | — | Large filers. **Not implemented.** Reject at validation. |

### The trap to avoid

`no_verificable` is **not** "Verifactu off". A Spanish issuer in that mode still requires the
chained records, the immutability, the event log, and the integrity alarm. Only the
real-time AEAT call is optional.

Therefore:

- Build the chain **unconditionally** for any entity whose `country_code = 'ES'` and whose
  regime is `verifactu` or `no_verificable`.
- Gate **only the submission call** on `regime = 'verifactu'`.
- `none` is valid for an ES entity only as a pre-go-live state; the panel must warn loudly.

### Chain genesis and migration from SpaceManager

A hash chain cannot be backfilled over invoices issued by another system — the prior records
were produced under a different SIF and their fingerprints are not reproducible.

**Decision:** the chain begins at a documented genesis on go-live date. The first record has
no predecessor and is marked as such. Historical SpaceManager invoices are imported as
**non-fiscal reference records** in a separate table, clearly flagged, excluded from the
chain, excluded from any AEAT submission, and visibly labelled in the panel.

**Action before S04:** get written confirmation from the client's gestor on (a) the go-live
cutover date, (b) whether SpaceManager has been producing valid Verifactu records to date,
and (c) the treatment of the invoice number sequence across the cutover — a series may not
restart or collide.

If SpaceManager has *not* been compliant since 2026-01-01, the client has been issuing
invoices without fiscal validity. That is their exposure, not yours, but it makes the
cutover urgent and it is worth saying to them plainly and in writing.

### Toggling

Enabling a regime starts a chain. Disabling must never break one:

- Switching `verifactu` → `no_verificable` keeps the chain and stops submitting. Legal.
- Switching either → `none` **is blocked** once any record exists for that entity. The panel
  offers only `no_verificable` as a de-escalation, with a confirmation explaining that
  records are still generated.
- Records already submitted to the AEAT can never be un-submitted. Correction is by
  *registro de anulación* only.

---

## 4. Sprint mapping

| Decision | Lands in |
|---|---|
| `legal_entities`, sites backfill, entity settings UI | S03 |
| Invoice series scoped to entity, fiscal invoice model | S03 |
| Verifactu chain, QR, event log, integrity alarm, regime flag | S04 |
| AEAT submission + test-environment integration | S04 |
| Recurring billing job + SDD pre-notification timing | S05 |
| `payment_provider_accounts`, per-account webhook routing, cards | S06 |
| `sepa_mandates`, runs, instructions, `pain.008` export, returns import | S07 |

## 5. Confirmations needed before S03 starts

1. **One legal entity or several?** Blocks the S03 schema.
2. **Gestor sign-off on cutover** — genesis date, series continuity, SpaceManager status.
3. **Contractual SDD pre-notification period** in the client's current tenancy terms —
   determines how many days ahead the recurring job must run.
4. **Which bank, and which file format version** they accept (Cuaderno 19-14 is standard,
   but banks differ on optional fields and some still take 19-43/19-44 variants).
