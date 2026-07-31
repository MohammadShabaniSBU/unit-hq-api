# Sprint 03 — Legal Entities & Fiscal Invoices

## Goal

Invoices become **numbered fiscal documents**: issued by a legal entity, belonging to a
series, gaplessly numbered, immutable once issued, corrected only by rectificative invoice.
This is the foundation Verifactu (S04) chains and hashes — every field the *registro de
facturación* needs must exist and be snapshot-stable by the end of this sprint.

`billing_periods` (the renamed display grouping) is untouched: it remains an internal
grouping of charges. The fiscal `invoices` table built here is a different thing with a
different job, per decision D5.

## The blocker, dissolved

`10-open-decisions.md` says S03 cannot begin until the client answers "one legal entity or
several?". That was over-cautious: the schema, the settings UI and the issuance path cost
the same either way. This sprint **builds for several and seeds one**. The client's answer
determines data entry at go-live, not code. Task 00 downgrades the open decision
accordingly.

What genuinely still needs external answers moves to the confirmations table below — none
of it blocks code, all of it blocks **go-live**.

## Gestor confirmations (needed before S04 ends, not before S03 starts)

| # | Question | Affects |
|---|---|---|
| 1 | One entity or several? NIF(s), registered addresses, SEPA creditor IDs | Go-live data entry |
| 2 | May self-storage rents be invoiced as **facturas simplificadas**, or do amounts/activity require ordinary invoices with tenant NIF? | Whether tenant tax IDs must be collected at signing (task 01) |
| 3 | Are **deposit** charges excluded from VAT invoices (guarantee, not supply)? Default here: excluded | Task 03 issuance filter |
| 4 | Rectificative method to state on credit invoices (por diferencias assumed) | Task 04 wording |
| 5 | Series numbering across the SpaceManager cutover — continue or fresh series at genesis? | Go-live series setup |

Get these in writing. #2 changes onboarding UX and is the one to chase first.

## Exit criteria

- [ ] Every site belongs to a legal entity; entities are managed in Settings and archive-only.
- [ ] Contacts can carry fiscal identity (billing name, tax ID, billing address), surfaced
      on the contact page and validated for ES formats.
- [ ] Issuing an invoice allocates a **gapless, per-series** number under concurrency, in
      one transaction with full issuer/buyer snapshots.
- [ ] An issued invoice is immutable — no endpoint can change or delete it.
- [ ] Contract signing issues the first-period invoice; vacate/transfer credits produce
      rectificative invoices referencing the originals.
- [ ] Tax resolution honours jurisdiction (D2): ES sites resolve ES rates.
- [ ] A rendered invoice (HTML/PDF) exists for every issued document, from snapshots only.

## Task order

| # | Task | Est. |
|---|---|---|
| 00 | [Legal entities](./00-legal-entities.md) | 1 day |
| 01 | [Contact fiscal identity](./01-contact-fiscal-identity.md) | 0.5 day |
| 02 | [Invoice series & gapless numbering](./02-invoice-series.md) | 1 day |
| 03 | [Fiscal invoice model & issuance](./03-invoice-model-and-issuance.md) | 2 days |
| 04 | [Rectificative invoices](./04-rectificative-invoices.md) | 1 day |
| 05 | [Jurisdiction-scoped tax resolution (D2)](./05-jurisdiction-tax-resolution.md) | 0.5 day |
| 06 | [Manual payment recording](./06-manual-payments.md) | 1 day |

Strictly sequential through 05; task 06 depends only on 03 and may run any time after it.
03 is the heart and the biggest.

## Ratified decisions (owner, this planning round)

Issue **at signing** (digital-first; pending-contract cancellations → rectificative). **One
invoice per contract** — hard FK, one-way door. **`contacts.company` ignored**; B2B invoicing
deferred to `10-open-decisions.md`. **Invoice language** interim = site-country via i18n;
replaced by the document builder in the comms phase. **PDF engine pending** — render behind
`InvoiceRenderer` so it swaps in one class. **Manual payments** (walk-in cash) pulled into
scope as task 06.

## Risks

**Gapless numbering under concurrency.** Two simultaneous signings on the same series must
get consecutive numbers with no gap and no duplicate. The allocation is `SELECT … FOR UPDATE`
on the series row inside the issue transaction — number consumed only if the transaction
commits. SQLite serialises writes so tests pass trivially; add a Postgres-targeted test that
actually races two transactions.

**Snapshots, not joins.** Verifactu records must be reconstructible from the invoice's own
data years later. Every issuer/buyer/tax field is copied onto the invoice at issue. If a
resource "helpfully" reads the live entity or contact instead of the snapshot, S04 will hash
mutable data — a defect that looks fine until an entity edits its address.

**Do not reopen ledger immutability.** Invoices reference charges; they never modify them.
Balance stays computed from charges/payments (invariant 5); the invoice adds fiscal identity
to amounts that already exist.

**Signing transaction grows again.** First-period issuance joins the signing transaction
(invariant 20 family). Watch test runtime and deadlock surface; the series lock is the new
contention point.
