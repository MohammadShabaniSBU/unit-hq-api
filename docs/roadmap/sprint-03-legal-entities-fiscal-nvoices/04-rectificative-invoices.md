# S03-04 — Rectificative invoices (credit notes)

## Context

An issued invoice is immutable; Spanish law corrects by **factura rectificativa** — a new
invoice, in its own series, referencing the one it corrects, carrying the corrective
amounts. S02 has been accumulating exactly the rows that need this: negative `adjustment`
charges from vacate settlements and transfer credits, which the sprint README promised S03
would pick up retroactively.

## Scope

**In:** rectificative issuance through the same `InvoiceIssuer`, automatic wiring from
vacate/transfer credits, manual rectification from the panel, retroactive sweep of existing
uninvoiced credits, PDF variant.
**Out:** "por sustitución" full-replacement rectificatives (only "por diferencias" —
difference-style — ships; the reason column carries the method for S04's registro), refunds
of money (S07), deposit settlement rows (never invoiced — deductions are compensation, per
gestor #3 framing; if the gestor reverses this, it is a config change on the issuer filter).

## Behaviour

### Issuance

`InvoiceIssuer::issueRectificative(Invoice $original, Collection $creditCharges, string $reason)`:

1. Validate: original is `issued`; every credit charge belongs to the same contract,
   is negative, uninvoiced; original's currency matches.
2. Series: entity default `rectificative` series; `assertKind` guard from task 02.
3. `kind = rectificative`, `rectifies_invoice_id`, `rectification_reason` (enum-ish string:
   `vacate_settlement | transfer_credit | operator_correction`), method fixed
   `por_diferencias` in a dedicated column? — no: encode method in `rectification_reason`
   metadata later if S04 needs it separately; keep one column now, values above.
4. Lines carry the **negative** net/tax/gross of each credit charge — signs preserved, the
   original charge's tax snapshot (already guaranteed by S02's credit mechanics).
   Totals are negative.
5. Buyer/issuer snapshots taken fresh (the tenant may have completed fiscal data since);
   kind follows the **original**: an ordinary original gets an ordinary-style rectificative
   with buyer identification, a simplified one may stay simplified.
6. `full_number` from the R-series; charges stamped; `invoice.rectified` core activity on
   **both** invoices' subjects (properties carry each other's `full_number`).

### Wiring

- **Vacate** (`daily`/`notice_based` credits): the vacate transaction, after writing
  credits, resolves which invoice each credited charge was on and groups credits **per
  original invoice** — one rectificative per original. Credits against never-invoiced
  charges (pre-S03 history) fall through to the sweep below.
- **Transfer** (`prorate_immediately` credit): same grouping in the transfer transaction.
  The transfer's *debit* joins the next ordinary issuance (it is a new supply — issue it
  immediately in the same transaction alongside the rectificative, so the tenant receives
  a matched pair).
- **Retroactive sweep:** `php artisan invoices:sweep-credits` — finds negative adjustment
  charges with `invoice_id IS NULL`, groups per contract per original-invoice (or per
  contract where no original exists → issues an ordinary *negative-total* invoice is NOT
  allowed; instead flag these in the command output for manual review with the gestor —
  do not invent fiscal documents for pre-fiscal history silently). Run once at deploy;
  idempotent by construction (stamped charges drop out).
- **Manual:** `POST /api/invoices/{id}/rectify` `{ charge_ids?, reason }` — operator picks
  an issued invoice; eligible uninvoiced credits for its charges are preselected.

## Panel surface

Invoice detail: rectificatives listed under the original with negative totals in red;
originals linked from the rectificative header ("Rectifica a F2026-000123"). List view: kind
badge `R` + filter. Vacate/transfer flows need no new UI — their previews (S02) gain an
"invoices to be issued" line naming numbers-to-be. i18n `billing.invoices.rectificative.*`;
es: *Factura rectificativa*, *Rectifica a…*, reason labels.

## Invariants

- Immutability doubles: neither original nor rectificative ever mutates; a wrong
  rectificative is corrected by a further rectificative.
- Charges stamped once (task 03 rule) — the credit charge's stamp points at the
  rectificative.
- Ledger untouched: signs and tax snapshots come from S02's credit rows; this task adds
  fiscal wrapping only.

## Acceptance criteria

- [ ] Vacate with credits issues one rectificative per affected original, negative totals
      matching credits to the cent, in the vacate transaction.
- [ ] Transfer issues the rectificative + the new-charge ordinary invoice as a pair.
- [ ] Rectificatives use the R-series and never an ordinary one (guard test).
- [ ] Original↔rectificative links navigate both ways in API and panel.
- [ ] Sweep stamps historical credits with matchable originals and **reports, not
      invents**, the unmatched; second run is a no-op.
- [ ] Manual rectify validates eligibility and reason.
- [ ] PDF shows negative amounts and the reference line.

## Tests required

| Test | Asserts |
|---|---|
| `RectificativeTest::vacate_credits_grouped_per_original` | One R per original |
| `RectificativeTest::transfer_issues_matched_pair` | R + ordinary, same transaction |
| `RectificativeTest::wrong_series_kind_rejected` | Guard |
| `RectificativeTest::negative_totals_match_credit_charges` | bcmath, original tax snapshots |
| `RectificativeTest::sweep_idempotent_and_reports_unmatched` | No silent invention |
| `RectificativeTest::rectifying_a_rectificative_allowed` | Chain of corrections |
| `RectificativeTest::manual_rectify_eligibility` | Cross-contract/positive/stamped rejected |
