# S03-00 — Legal entities

## Context

Nothing in the schema says who *issues* an invoice. Sites carry currency and country but not
fiscal identity — yet the Verifactu chain, invoice series, VAT registration, Stripe
credentials (S06) and the SEPA creditor ID (S07) all hang off the issuer's NIF. Full
rationale and the non-tenancy guard: `architecture-payments-and-fiscal.md` §1, and
invariant 34, which already exists.

This task is **answer-agnostic** to the open client question. Several entities are supported;
one is seeded. Data entry at go-live resolves the rest.

## Scope

**In:** `legal_entities` table, `sites.legal_entity_id`, Settings UI, seeder, decision
downgrade in `10-open-decisions.md`.
**Out:** `fiscal_regime` *behaviour* (S04 — the column exists, only `none` is selectable),
payment credentials (S06), SEPA creditor usage (S07), entity guard on transfers (see below).

## Schema changes

Per `architecture-payments-and-fiscal.md` §1, with two additions learned since it was
written:

```sql
CREATE TABLE legal_entities (
    id               BIGSERIAL PRIMARY KEY,
    legal_name       VARCHAR(255) NOT NULL,
    trading_name     VARCHAR(255) NULL,
    tax_id           VARCHAR(64)  NOT NULL,
    tax_id_type      VARCHAR(16)  NOT NULL,   -- nif | siren | uk_crn | vat | other
    vat_number       VARCHAR(64)  NULL,
    country_code     CHAR(2)      NOT NULL,
    address_line1    VARCHAR(255) NOT NULL,
    address_line2    VARCHAR(255) NULL,
    city             VARCHAR(128) NOT NULL,
    postal_code      VARCHAR(32)  NOT NULL,
    fiscal_regime    VARCHAR(24)  NOT NULL DEFAULT 'none',
    sepa_creditor_id VARCHAR(64)  NULL,
    archived_at      TIMESTAMP    NULL,       -- archive-only, like sites (invariant 28 idiom)
    created_at       TIMESTAMP, updated_at TIMESTAMP
);
CREATE UNIQUE INDEX legal_entities_tax_id_idx ON legal_entities (tax_id) WHERE archived_at IS NULL;

ALTER TABLE sites ADD COLUMN legal_entity_id BIGINT NULL REFERENCES legal_entities(id);
-- seeder assigns; a follow-up statement in the same migration sets NOT NULL after backfill
```

Seeder: create one entity (placeholder NIF clearly marked `PENDING-GESTOR`, ES, address from
the first ES site), point every site at it. `migrate:fresh --seed` must leave no site
entity-less.

## Implementation notes

- Model `LegalEntity`: `active()` scope, archive/unarchive endpoints aliasing `DELETE`,
  exactly the `sites` idiom. Archive refused while the entity has non-archived sites or
  (from task 03) any issued invoice.
- **Invariant 34 discipline:** the FK is read explicitly at issuance time
  (`$site->legalEntity`). No global scope, no relationship auto-loading in list queries, no
  filter params on unrelated endpoints. Add the architecture doc's defect example to the PR
  description so the reviewer (you) re-reads it.
- `fiscal_regime` validation: only `none` accepted this sprint; `verifactu` /
  `no_verificable` rejected with a message naming S04; `ticketbai` / `sii` rejected as
  unimplemented. The enum ships complete so S04 is a validation change, not a migration.
- `tax_id` uniqueness is partial (active only) so a re-registered entity after archive is
  possible.

## API surface

```
GET/POST           /api/legal-entities            (?status=active|archived|all)
GET/PATCH          /api/legal-entities/{id}
POST               /api/legal-entities/{id}/archive | /unarchive
GET                /api/legal-entities/options     { value, label }
```

`PATCH` on an entity with issued invoices (task 03 onward) may change contact-style fields
but **not** `tax_id` / `country_code` — the identity an invoice was issued under is not
editable; that situation is a new entity. Site resource gains `legal_entity_id` +
embedded `legal_entity: { id, legal_name }`.

## Panel surface

Settings → **Legal entities** (new page beside Facility/sites): list with site counts,
create/edit drawer, archive with confirm. Site create/edit form gains a required entity
select (preselected when only one active entity exists — the common case stays one click).
i18n `settings.legalEntities.*`; es: *Entidades jurídicas*, tax ID → *NIF*.

## Invariants

- Invariant 34 (exists) — fiscal concept, never a tenancy boundary.
- Invariant 28 idiom — archive-only.
- New, add to `09`: **Issued fiscal identity is immutable.** `legal_entities.tax_id` and
  `country_code` are frozen once any invoice exists for the entity.

## Acceptance criteria

- [ ] Migration + seeder leave every site with an entity; `legal_entity_id` NOT NULL.
- [ ] Entity CRUD works; archive refused with active sites; `tax_id`/`country_code` frozen
      after first issued invoice (test added in task 03 revisits this).
- [ ] `fiscal_regime` accepts only `none`; the others reject with distinct messages.
- [ ] Grep shows no global scope / middleware / default constraint on `legal_entity_id`.
- [ ] `10-open-decisions.md`: blocker row replaced by the go-live confirmations table from
      the sprint README; D-entry recorded "S03 built answer-agnostic".
- [ ] `en/es/fr` complete.

## Tests required

| Test | Asserts |
|---|---|
| `LegalEntityTest::seed_assigns_every_site` | No orphan sites |
| `LegalEntityTest::archive_refused_with_active_sites` | 422 |
| `LegalEntityTest::fiscal_regime_only_none_this_sprint` | Each rejected value distinct |
| `LegalEntityTest::tax_id_unique_among_active` | Partial index semantics |
| `LegalEntityTest::sites_form_requires_entity` | Validation |
