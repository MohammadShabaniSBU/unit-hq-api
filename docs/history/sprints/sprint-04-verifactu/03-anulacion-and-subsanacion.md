# S04-03 — Anulación & subsanación

## Context

Two repair mechanisms the regulation provides, easily confused, never interchangeable:

- **Registro de anulación** cancels a *registration* — the invoice should never have
  fiscally existed (duplicate issue, wrong customer entirely). It is **not** a commercial
  correction: amounts owed change via **rectificativa** (S03-04), which produces a normal
  *alta* record.
- **Subsanación** repairs a *record* the AEAT rejected or accepted-with-errors — the
  invoice is fine; its registro needs fixing and resubmitting with the subsanación flag.

Getting operators to pick correctly matters more than the code; the UI carries that weight.

## Scope

**In:** anulación flow end-to-end, subsanación resubmission for `rejected` /
`accepted_with_errors`, invoice `annulled_at`, panel actions with hard guidance.
**Out:** anulación of *submitted-long-ago* records beyond what the service accepts
(surfaced as AEAT errors, handled case-by-case), bulk repair tooling.

## Behaviour

### Anulación

`POST /api/invoices/{id}/annul` `{ reason }` — one transaction:

1. Guards: invoice has an alta record; not already annulled; **and it has no allocated
   payments and no rectificatives** — money moved or corrections issued mean this is a
   rectificativa situation, refuse with a message saying exactly that.
2. `Chain::append($invoice, 'anulacion')` — a **new chain link** (next position, huella per
   the anulación field-set: `IDEmisorFacturaAnulada`, `NumSerieFacturaAnulada`,
   `FechaExpedicionFacturaAnulada`, `Huella`, `FechaHoraHusoGenRegistro` — verify order per
   README rule). The alta record is untouched; the chain records history, it doesn't erase.
3. `invoices.annulled_at = now()` (add column + `annulment_reason`; the **one** post-issue
   invoice write, added to the S03 immutability invariant as an explicit carve-out — set
   once, never cleared).
4. Un-stamp the charges (`invoice_id → NULL`) so they can be re-invoiced correctly — the
   ledger rows themselves are untouched.
5. `submission_status` per regime as in 00; core activity `invoice.annulled`.

Annulled invoices render with a diagonal **ANULADA** watermark and stay listed (audit
visibility), excluded from revenue/collection surfaces.

### Subsanación

`POST /api/verifactu-records/{id}/subsanate` (and a "repair" panel action on
rejected/with-errors records):

1. Only from `rejected` / `accepted_with_errors`.
2. Rebuild the record's XML from **current invoice snapshots** — covering the class of
   errors where the *record* was malformed. If the AEAT error indicates the *invoice data*
   is wrong (bad NIF etc.), the fix is: correct the contact → annul → re-issue; the drawer
   says so next to the error message rather than letting subsanación fail repeatedly.
3. Mark the original `superseded`; insert the corrected record **at a new chain position**
   with `is_subsanacion = true` and, in its XML, `Subsanacion=S` +
   `RechazoPrevio` per spec; it enters the normal 02 pending queue.

Note the asymmetry: subsanación creates a new chain link *referencing the same invoice* —
one invoice may legitimately map to alta + superseded + subsanación records. The 00
uniqueness is on chain position, not invoice, precisely for this.

## Panel surface

Invoice detail: **Annul** action behind a two-step confirm that states the distinction in
one sentence each (*"Amounts wrong? → issue a rectificativa instead"*) — the confirm text
is the safety mechanism, write it carefully in es first. Records with errors: red/amber
banner on the invoice + a **Repair** drawer showing the AEAT code/message and the
subsanación-vs-reissue guidance. Entity Verifactu card (02) links to a filtered
needs-attention list. i18n `verifactu.annul.*`, `verifactu.repair.*`.

## Invariants

- Chain append-only: anulación and subsanación are new links; nothing rewrites.
- Ledger untouched: annulment un-stamps, never edits charges (invariant 3).
- S03 immutability amended: `annulled_at`/`annulment_reason` set-once carve-out.

## Acceptance criteria

- [ ] Annul writes the anulación link, watermarks, un-stamps charges; re-issuing those
      charges later produces a fresh invoice + alta record.
- [ ] Annul refused (422, explanatory) with payments allocated or rectificatives present.
- [ ] Subsanación supersedes, appends flagged record at new position, queues; golden XML
      carries `Subsanacion=S`.
- [ ] Only rejected/with-errors are subsanatable.
- [ ] Chain verify (04) passes across anulación + subsanación sequences — fixture chain
      committed.

## Tests required

| Test | Asserts |
|---|---|
| `AnulacionTest::appends_link_and_unstamps` | Full transaction |
| `AnulacionTest::refused_with_payments_or_rectificatives` | Guard + message key |
| `AnulacionTest::reissue_after_annul` | Charges re-invoiceable |
| `SubsanacionTest::supersedes_and_requeues` | Status + flag + position |
| `SubsanacionTest::only_from_error_states` | Guard |
| `ChainFixtureTest::mixed_sequence_verifies` | alta/anulación/subsanación chain intact |
