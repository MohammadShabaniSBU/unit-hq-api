# S03-03 — Fiscal invoice model & issuance

## Context

The heart of the sprint. An `Invoice` becomes what D5 promised: a numbered fiscal document,
immutable once issued, source of truth for what was billed. It sits **on top of** the
ledger: charges remain the atomic money facts; the invoice gives a set of them a number, an
issuer, a buyer, and a date. Balance stays computed from charges and payments exactly as
before (invariant 5) — the invoice adds fiscal identity, not arithmetic.

`billing_periods` (the renamed old table) is left alone this sprint; S05 decides whether the
recurring job still needs it or it retires.

## Scope

**In:** `invoices` + `invoice_lines`, the issue transaction, wiring into contract signing,
vacate gap-charges and manual issuance, ordinary/simplified determination, HTML/PDF render
from snapshots, panel list + detail.
**Out:** rectificatives (task 04 — but `kind` and reference columns land here), Verifactu
registro/QR/hash (S04 — column placeholders only), AEAT submission, delivery to the tenant
by email (comms phase), recurring issuance (S05 calls the same issuer).

## Schema changes

```sql
CREATE TABLE invoices (
    id                 BIGSERIAL PRIMARY KEY,
    legal_entity_id    BIGINT NOT NULL REFERENCES legal_entities(id),
    invoice_series_id  BIGINT NOT NULL REFERENCES invoice_series(id),
    number             BIGINT NOT NULL,
    full_number        VARCHAR(40) NOT NULL,      -- 'F2026-000123', rendered once at issue
    kind               VARCHAR(16) NOT NULL,      -- ordinary | simplified | rectificative
    status             VARCHAR(12) NOT NULL,      -- draft | issued
    issue_date         DATE NULL,                 -- set at issue
    contract_id        BIGINT NULL REFERENCES contracts(id),
    contact_id         BIGINT NOT NULL REFERENCES contacts(id),
    rectifies_invoice_id BIGINT NULL REFERENCES invoices(id),   -- task 04
    rectification_reason VARCHAR(64) NULL,                      -- task 04
    -- issuer snapshot (from legal_entities at issue)
    issuer_name        VARCHAR(255) NOT NULL,
    issuer_tax_id      VARCHAR(64)  NOT NULL,
    issuer_address     JSONB        NOT NULL,     -- line1/2, city, postal, country
    -- buyer snapshot (from contact at issue; minimal for simplified)
    buyer_name         VARCHAR(255) NULL,
    buyer_tax_id       VARCHAR(64)  NULL,
    buyer_address      JSONB        NULL,
    -- totals (snapshots; sum of lines, asserted equal in tests)
    currency           CHAR(3) NOT NULL,
    net_total          NUMERIC(10,2) NOT NULL DEFAULT 0,
    tax_total          NUMERIC(10,2) NOT NULL DEFAULT 0,
    gross_total        NUMERIC(10,2) NOT NULL DEFAULT 0,
    -- S04 placeholders, nullable until then
    verifactu_hash     VARCHAR(128) NULL,
    verifactu_prev_hash VARCHAR(128) NULL,
    verifactu_submitted_at TIMESTAMP NULL,
    created_by BIGINT NULL REFERENCES employees(id),
    created_at TIMESTAMP, updated_at TIMESTAMP
);
CREATE UNIQUE INDEX invoices_series_number_idx ON invoices (invoice_series_id, number);
CREATE INDEX invoices_contract_idx ON invoices (contract_id);
CREATE INDEX invoices_entity_issued_idx ON invoices (legal_entity_id, issue_date);

CREATE TABLE invoice_lines (
    id          BIGSERIAL PRIMARY KEY,
    invoice_id  BIGINT NOT NULL REFERENCES invoices(id),
    charge_id   BIGINT NOT NULL REFERENCES charges(id),
    description VARCHAR(255) NOT NULL,            -- rendered, e.g. 'Rent AL6-06 01–31 Jul'
    period_start DATE NULL, period_end DATE NULL,
    net_amount  NUMERIC(10,2) NOT NULL,
    tax_rate_snapshot NUMERIC(5,2) NOT NULL,
    tax_amount  NUMERIC(10,2) NOT NULL,
    gross_amount NUMERIC(10,2) NOT NULL,
    created_at TIMESTAMP
);
CREATE UNIQUE INDEX invoice_lines_charge_idx ON invoice_lines (charge_id);

ALTER TABLE charges ADD COLUMN invoice_id BIGINT NULL REFERENCES invoices(id);
```

`invoice_lines.charge_id` unique: a charge appears on **at most one** invoice, ever. The
line copies the charge's amounts (snapshot idiom, `architecture-pricing.md` §1) — the line
must survive even hypothetical charge-table surgery.

## Behaviour

### The issuer

`App\Support\Fiscal\InvoiceIssuer::issue(...)` — support tier, one transaction, and the
**only** code path that creates issued invoices. Signing, vacate, S04 recurring, S05
autopay, the manual endpoint — all call this.

1. Resolve entity from the contract's site (`invariant 34`: explicit read).
2. Determine kind: contact `fiscalComplete()` → `ordinary`; else `simplified`.
   **Simplified guardrail:** refuse when gross > €400 with `simplified_limit_exceeded`,
   telling the operator to complete the tenant's fiscal data. Threshold in config with the
   gestor-#2 note; do not hardcode a guess about the €3,000 sectoral allowance.
3. Pick the entity's default series for the kind (override param allowed);
   `InvoiceNumbering::allocate` under lock (task 02); render `full_number`.
4. Snapshot issuer + buyer; build lines from the given charges (descriptions from charge
   type + item + period, i18n-keyed with the **contact's** language in mind — render
   Spanish for es locale sites; simplest: site-country-driven template choice, note for
   comms phase).
5. Totals via bcmath sum of lines; assert equals sum of charge snapshots.
6. `status = issued`, `issue_date = today` (entity timezone via site), set
   `charges.invoice_id`.
7. `RecordsActivity::core('invoice.issued', …)` — money as strings, `full_number` in
   properties.

**Charge selection rules:** only charges with `invoice_id IS NULL`; `deposit` charges
**excluded by default** (guarantee, not supply — gestor #3; config flag
`fiscal.invoice_deposits = false`); negative `adjustment` charges are never invoiced here —
they become rectificatives (task 04); `refund` rows never.

### Ratified decisions (owner sign-off, this sprint)

- **Timing: issue at signing.** Digital flow is the priority; walk-ins sign and pay in the
  same moment so timing coincides. A cancelled `pending` contract is corrected by
  rectificative (task 04's `operator_correction` reason). Do not build a move-in-triggered
  issuance path.
- **One invoice per contract.** `invoices.contract_id` stays a hard FK; no cross-contract
  consolidation. Recorded as a one-way door in `10-open-decisions.md`.
- **`contacts.company` is ignored by issuance.** Buyer is always the person
  (`billing_name` override applies). B2B invoicing (company as buyer, its own CIF,
  possibly its own contact model) is deferred and recorded in `10-open-decisions.md` —
  do not "helpfully" fall back to the company field.
- **Language, interim:** the template renders in the site's country language (ES → es,
  FR → fr with en fallback, GB → en) via i18n keys. This is a stopgap: a document builder
  with multi-language templates replaces it in the comms phase. Keep every string keyed so
  the swap is template-level, not code-level.
- **PDF engine: decision pending** (next planning conversation). Structure the render as
  HTML-first with the PDF step behind one interface
  (`App\Support\Fiscal\InvoiceRenderer::html()` / `::pdf()`) so the engine choice is a
  single-class swap. If implementation reaches this point before the decision lands, stub
  `pdf()` with dompdf but treat it as replaceable.

### Wiring

- **Contract signing / reservation convert:** after first-period charges, the same
  transaction calls `InvoiceIssuer::issue` with them. The signing 422s if issuance fails
  (missing series is a seeded impossibility; simplified-limit is real and must surface at
  the preview step — extend `convert-preview` response with `invoice_kind` and any blocker).
- **Vacate gap-charges** (under-billed final period, S02-02): issued in the vacate
  transaction. Vacate *credits* wait for task 04.
- **Manual:** `POST /api/contracts/{id}/invoices` with explicit charge ids — the escape
  hatch for anything historical/uninvoiced. Same issuer, same rules.

### Immutability

No `PATCH`, no `DELETE`, no status transition off `issued`. `draft` exists only as an
in-transaction intermediate; nothing persists drafts this sprint (kept in the enum so a
future quoting flow can). Corrections are rectificatives. Add the endpoint-absence to
routes tests — the cheapest way to notice a helpful future CRUD generator breaking the
rule.

### Rendering

Blade template → HTML, `barryvdh/laravel-dompdf` → PDF, **from invoice snapshots only** —
the template receives the `Invoice` and touches no live entity/contact relation (lint this
by passing an array, not models). Layout: issuer block, buyer block (or *factura
simplificada* header), lines with period columns, totals with per-rate tax breakdown,
`full_number` + issue date prominent. Reserve a QR placeholder box (S04 fills it).
`GET /api/invoices/{id}/pdf` streams it; render-on-demand now, stored artifact when S04
hashes it.

## Panel surface

Billing → **Invoices** page repurposed to fiscal invoices: list (full number, date, contact,
contract, total, kind badge), filters by entity/series/date, detail drawer with lines and a
PDF button. Contract detail's Invoices tab switches to these. Contact detail Invoices tab
likewise. Empty states explain that invoices appear at signing. i18n `billing.invoices.*`;
es: *Factura*, *Factura simplificada*, *Base imponible* (net), *Cuota* (tax), *Total*.

## Invariants

- Invariant 5 — balance/overdue still computed from charges; invoices add no money state.
- Invariant 3 — charges untouched beyond `invoice_id` stamping (one-way, null → id, once).
- New, add to `09`: **Issued invoices are immutable and snapshot-complete.** Every field
  needed to re-render an issued invoice lives on the invoice/lines rows. Corrections are
  rectificative invoices. `charges.invoice_id` is written once.
- Invariant 20 family — signing issues the invoice in the same transaction; preview shows
  what will be issued.

## Acceptance criteria

- [ ] Signing produces an issued invoice covering exactly the first-period charges minus
      deposits; preview announces kind and blockers beforehand.
- [ ] Fiscal-complete contact → ordinary with full buyer snapshot; incomplete → simplified;
      simplified > €400 → 422 at preview and issue.
- [ ] Totals equal line sums equal charge sums, bcmath, to the cent, asserted.
- [ ] Editing the contact or entity afterwards changes nothing on the invoice or its PDF.
- [ ] A charge invoices at most once; manual issuance rejects already-invoiced charges.
- [ ] No update/delete route exists for invoices (route-list test).
- [ ] HTML renders from snapshots for both kinds in the site-country language, QR box
      reserved; `pdf()` sits behind `InvoiceRenderer` pending the engine decision.
- [ ] Entity/series freeze rules from tasks 00/02 now enforce (first-invoice tests).
- [ ] Seeder issues invoices for seeded signings so the panel has content.

## Tests required

| Test | Asserts |
|---|---|
| `InvoiceIssueTest::signing_issues_first_period_invoice` | In-transaction, correct lines |
| `InvoiceIssueTest::deposit_charges_excluded_by_default` | Config flag honoured |
| `InvoiceIssueTest::kind_by_fiscal_completeness` | ordinary vs simplified |
| `InvoiceIssueTest::simplified_over_limit_rejected` | 422 both at preview and issue |
| `InvoiceIssueTest::totals_match_lines_match_charges` | bcmath equality |
| `InvoiceIssueTest::snapshots_immune_to_later_edits` | Contact + entity mutation test |
| `InvoiceIssueTest::charge_invoiced_once` | Unique line + stamp |
| `InvoiceIssueTest::no_mutation_routes` | Route inventory |
| `InvoicePdfTest::renders_from_snapshot_arrays_only` | No model access in template |
| `Pgsql` race from task 02 re-run through the full issuer | End-to-end gaplessness |
