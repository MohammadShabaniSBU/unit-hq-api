# S03-01 — Contact fiscal identity

## Context

`contacts` has names, email, company — and nothing an invoice can legally name a buyer with.
An ordinary Spanish invoice requires the recipient's NIF and address. Whether self-storage
rents may instead use *facturas simplificadas* (no buyer identification) is gestor
question #2 in the sprint README; this task makes both outcomes work.

## Scope

**In:** fiscal columns on `contacts`, ES tax-ID validation helper, panel card, completeness
check consumed by issuance (task 03).
**Out:** collecting the data during signing/onboarding flows (worth adding to the contract
form once gestor #2 answers — leave a TODO referencing this task), B2B French SIREN/SIRET
validation beyond format.

## Schema changes

```sql
ALTER TABLE contacts ADD COLUMN billing_name        VARCHAR(255) NULL; -- defaults to full name at issue when null
ALTER TABLE contacts ADD COLUMN tax_id              VARCHAR(64)  NULL;
ALTER TABLE contacts ADD COLUMN tax_id_type         VARCHAR(16)  NULL; -- nif | nie | vat | siren | siret | uk_crn | other
ALTER TABLE contacts ADD COLUMN billing_address_line1 VARCHAR(255) NULL;
ALTER TABLE contacts ADD COLUMN billing_address_line2 VARCHAR(255) NULL;
ALTER TABLE contacts ADD COLUMN billing_city        VARCHAR(128) NULL;
ALTER TABLE contacts ADD COLUMN billing_postal_code VARCHAR(32)  NULL;
ALTER TABLE contacts ADD COLUMN billing_country_code CHAR(2)     NULL;
```

All nullable — a prospect has none of this, and simplified invoices never need it.

## Implementation notes

`App\Support\Fiscal\TaxId` (support tier, no state):

```php
public static function validate(string $value, string $type): bool
public static function normalize(string $value): string   // trim, upper, strip separators
```

- `nif` / `nie`: real ES checksum (letter table for DNI/NIE, control character for CIF-style
  organisation NIFs). These are deployed-market IDs; validate properly.
- `siren`/`siret`: Luhn. `vat`, `uk_crn`, `other`: length/charset only. No remote VIES calls.

`Contact::fiscalComplete(): bool` — billing name (or names), tax_id + type, line1, city,
postal, country. Task 03 calls this to decide ordinary vs simplified issuance and to build
the buyer snapshot. Diffs of these columns log through the existing `LogsDirtyActivity`
(crm channel) — fiscal data changes must be visible in the timeline.

Add these fields to `FilterableFields` for contacts (advanced filters) so "tenants missing
tax ID" is a saveable operator query — that list is the onboarding worklist if gestor #2
comes back "ordinary invoices required".

## API / Panel surface

Contact resource + update endpoint gain the fields (standard validation; checksum via
`TaxId::validate`, returning a translatable `invalid_tax_id` 422). Contact detail gains a
**Fiscal data** card (edit drawer): billing name placeholder "same as contact name", type
select driving per-type input hints, address block with country. A subtle completeness hint
("required for ordinary invoices") — no red alarms; most B2C tenants may legitimately never
fill it. i18n `contacts.fiscal.*`; es: *Datos fiscales*, *NIF/NIE*.

## Invariants

- These are **live contact fields**, not the invoice record. Issued invoices snapshot them
  (task 03); editing a contact never changes an issued invoice — state this in the card's
  helper text and in a test.
- GDPR: add `tax_id`, `billing_*` to the redaction allowlist in `config/redaction.php` so
  `contacts:redact` clears them, while issued-invoice snapshots are retained (legal
  obligation basis — note it in the config comment).

## Acceptance criteria

- [ ] Fields persist and render; ES NIF/NIE checksums reject invalid values, accept valid.
- [ ] `fiscalComplete()` true only with the full set.
- [ ] Editing fiscal data logs a crm-channel diff and never touches issued snapshots (test
      lands with task 03, referenced here).
- [ ] Fields filterable via advanced filters; redaction covers them.
- [ ] `en/es/fr` complete.

## Tests required

| Test | Asserts |
|---|---|
| `TaxIdTest::valid_dni_nie_cif_accepted` | Table-driven real examples |
| `TaxIdTest::invalid_checksums_rejected` | Off-by-one letters fail |
| `TaxIdTest::normalize_strips_and_uppercases` | `es-b12345678` → `ESB12345678`-style |
| `ContactFiscalTest::fiscal_complete_logic` | Each missing field flips it |
| `ContactFiscalTest::updates_log_crm_activity` | Timeline visibility |
| `ContactFiscalTest::redaction_clears_fiscal_fields` | GDPR path |
