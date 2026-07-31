# S03-02 — Invoice series & gapless numbering

## Context

Spanish invoices belong to a **series** and are numbered **correlatively without gaps**
within it. Series are per issuer; rectificative invoices go in their own series (standard
AEAT practice); the Verifactu registro carries series + number of both the current and the
*previous* invoice, which is what makes gapless allocation a hard requirement rather than
bookkeeping hygiene.

## Scope

**In:** `invoice_series` table, allocation primitive with row-lock, defaults per entity,
Settings UI.
**Out:** the invoices that consume numbers (task 03), year rollover automation (series are
data; the operator creates `2027`'s series when they choose — note as a candidate playbook).

## Schema changes

```sql
CREATE TABLE invoice_series (
    id               BIGSERIAL PRIMARY KEY,
    legal_entity_id  BIGINT NOT NULL REFERENCES legal_entities(id),
    code             VARCHAR(20) NOT NULL,       -- e.g. 'F2026', 'R2026'
    kind             VARCHAR(16) NOT NULL,       -- ordinary | simplified | rectificative
    next_number      BIGINT NOT NULL DEFAULT 1,
    is_default       BOOLEAN NOT NULL DEFAULT false,
    archived_at      TIMESTAMP NULL,
    created_at TIMESTAMP, updated_at TIMESTAMP
);
CREATE UNIQUE INDEX invoice_series_code_idx
    ON invoice_series (legal_entity_id, code) WHERE archived_at IS NULL;
CREATE UNIQUE INDEX invoice_series_default_idx
    ON invoice_series (legal_entity_id, kind) WHERE is_default AND archived_at IS NULL;
```

Seeder: per entity, `F2026` (ordinary, default), `S2026` (simplified, default), `R2026`
(rectificative, default).

## Implementation notes

`App\Support\Fiscal\InvoiceNumbering::allocate(InvoiceSeries $series): int` — **must be
called inside the caller's transaction**:

```php
$row = DB::table('invoice_series')->where('id', $series->id)->lockForUpdate()->first();
DB::table('invoice_series')->where('id', $series->id)->increment('next_number');
return $row->next_number;
```

Gaplessness is a property of the transaction, not the counter: the increment only survives
if the *invoice insert commits*. Never allocate outside the issue transaction, never
"reserve" numbers, never fill a gap by hand — a rolled-back issue leaves no trace.
`lockForUpdate` serialises concurrent issuers on Postgres; SQLite serialises writes anyway.

Guardrails:
- `code` and `legal_entity_id` immutable once any invoice references the series (same
  freeze idiom as entity `tax_id`).
- `next_number` never editable via API. Starting offset may be set **only at creation**
  (SpaceManager continuity — gestor #5): `starting_number` accepted on POST, mapped to
  `next_number`, refused after.
- Archive refused for a `is_default` series while an active alternative of the same kind
  doesn't exist.
- `kind` mismatch (issuing a rectificativa into an ordinary series) is a guard error in
  task 03/04, but the check lives here: `assertKind(InvoiceSeries, string $kind)`.

## API / Panel surface

```
GET/POST /api/legal-entities/{id}/invoice-series
PATCH    /api/invoice-series/{id}          -- is_default, cosmetic only
POST     /api/invoice-series/{id}/archive | /unarchive
```

Settings → Legal entities → entity detail gains a **Series** section: table (code, kind,
next number, default badge, issued count), create drawer with `starting_number` field and
helper text about cutover continuity. i18n `settings.invoiceSeries.*`; es: *Serie de
facturación*.

## Invariants

New, add to `09`:

> **Invoice numbers are gapless per series.** Numbers are allocated by row-locking the
> series inside the issuing transaction. Numbers are never reserved, reused, edited, or
> back-filled; a rolled-back issuance consumes nothing.

Plus the freeze idiom: series `code`/entity immutable once referenced.

## Acceptance criteria

- [ ] Allocation inside a committed transaction yields 1,2,3…; a forced rollback between
      two issues yields no gap.
- [ ] Postgres race test: two parallel transactions issuing on one series produce
      consecutive distinct numbers (skip on SQLite).
- [ ] `starting_number` honoured at create, refused on PATCH; `next_number` untouchable.
- [ ] One default per kind per entity enforced by the partial index.
- [ ] Kind assertion helper rejects mismatches.
- [ ] Seeder creates the three default series per entity.

## Tests required

| Test | Asserts |
|---|---|
| `InvoiceNumberingTest::sequential_allocation` | 1..n in order |
| `InvoiceNumberingTest::rollback_leaves_no_gap` | Failed issue consumes nothing |
| `Pgsql/InvoiceNumberingRaceTest::concurrent_allocations_distinct_consecutive` | Real race |
| `InvoiceSeriesTest::code_frozen_after_first_invoice` | Freeze idiom (finalised in 03) |
| `InvoiceSeriesTest::starting_number_create_only` | Cutover path |
| `InvoiceSeriesTest::single_default_per_kind` | Partial unique |
