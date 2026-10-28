# S01-05 — Doc realignment: payments & fiscal identity

> **Revision 2 — the premise is inverted.** Revision 1 asked for the docs to be realigned onto
> a **per-site** payment model. Per D6/D7 in task 00, that is now the superseded position:
> payment credentials, invoice series, VAT registration and fiscal regime scope to
> **`legal_entities`**. Same effort, opposite direction. If you are holding revision 1, discard
> it — implementing it would take the docs further from the decision, not closer.

## Context

The project docs currently describe three mutually incompatible payment architectures, and
which one a Cursor session believes depends on which file it happened to read last:

| Source | Says |
|---|---|
| `05-billing-ledger.md` | Per-site direct Stripe keys, `site_stripe_settings`, site is merchant of record. Also still references the Connect question. |
| roadmap `README.md` §4 D6/D7 | Stripe Connect dropped; `payment_provider_accounts` scoped by `site_id`; `sites.fiscal_regime`. |
| `architecture-payments-and-fiscal.md` | `legal_entities` is the issuer; `payment_provider_accounts.legal_entity_id`; `legal_entities.fiscal_regime`. Declares itself as superseding the Stripe section of `05`. |

`10-open-decisions.md` records the per-site model under "Decided (do not reopen)", which makes
it worse: a session that reads the decisions file will treat the superseded position as
settled and defend it.

The decision (task 00, D6/D7) is that the architecture doc is correct. A Verifactu hash chain
is per issuer NIF. VAT registration is per tax ID. A SEPA creditor identifier belongs to a legal
person. Sites are buildings; several of them can belong to one issuer, and one operator can own
sites under two issuers. Scoping fiscal identity to a building is a modelling error that S03 and
S05 would each pay for.

This task writes that down everywhere and deletes the contradictions. **No code changes. No
migrations. No `legal_entities` table** — that is S03.

## Scope

**In:**
- Rewrite the Stripe/payments sections of `05-billing-ledger.md`
- Mark roadmap README §4 D6/D7 superseded in place
- Correct the per-site payment entries in `10-open-decisions.md`
- Promote `architecture-payments-and-fiscal.md` to the single source, with a status header
- Add invariant 34 (`legal_entities` is not a tenancy boundary) if task 00 has not already
- Record the open legal-entity-count question where S03 will see it
- Verify by interrogation: a cold Cursor session gives the right answer

**Out:**
- The `legal_entities` table, `payment_provider_accounts`, SEPA tables — S03/S06/S07
- Any change to the existing `site_stripe_settings` code — it stays working until S06 replaces
  it. This task documents the target, it does not deprecate the present.
- Verifactu implementation — S05

## What each document becomes

### `architecture-payments-and-fiscal.md` — the single source

Add a status header at the top:

```markdown
> **Status: authoritative.** This document is the single source for payments, fiscal identity
> and invoice issuance. Where `05-billing-ledger.md`, `10-open-decisions.md` or the roadmap
> README disagree, this document wins. Last reconciled: <date>, S01-05.
```

Also fold in the two things that were only ever recorded in D6/D7 and are worth keeping — the
reasoning that Connect is dropped because there is no platform distributing money (which
removes the PSD2 money-transmitter question outright rather than mitigating it), and the rule
that generating a direct-debit collection file is **not** a payment.

### `05-billing-ledger.md` — the surgery

The Stripe section is the largest block of superseded text in the repo. Replace it with a short
forward-pointing summary rather than a second full description, because two descriptions is how
this happened in the first place:

- Delete the per-site Stripe key narrative, the `site_stripe_settings` connect flow steps, and
  the per-site webhook routing description.
- Replace with: credentials, webhook routing, and merchant-of-record status belong to the
  **legal entity**; per-account webhook route tokens follow the `offers.token` pattern; see
  `architecture-payments-and-fiscal.md`. Note that `site_stripe_settings` exists in the codebase
  today and is replaced in S06.
- **Keep** the ledger sections untouched — three layers, append-only, computed balances,
  per-charge overdue, deposit-is-not-revenue. None of that is affected, and it is the most
  load-bearing text in the repo.
- Rewrite **invariant 11's** description here to the rail-specific form already drafted in the
  architecture doc: Stripe → verified webhook with per-account idempotency; SEPA DD → payment
  written at settlement, returns write reversal payments; manual → authenticated employee with
  a recorded causer. Never optimistic from the client, in all three.
- Apply the D5 rename from task 00: the display-grouping table is `billing_periods`; `Invoice`
  is reserved for the S03 fiscal document. Task `00b` does the code rename; this task makes the
  doc consistent with it.

### roadmap `README.md` — supersede in place, do not delete

Leave D6 and D7 where they are, wrapped:

```markdown
> **Superseded by S01-00 D6/D7 and `architecture-payments-and-fiscal.md`.** Credentials and
> fiscal regime scope to `legal_entities`, not to sites. Retained for context; do not
> implement.
```

Deleting them loses the reasoning about why Connect was dropped, which is still correct and
still worth reading. Also fix the deployment-context table row that says "one Stripe account
per legal entity" but then describes per-site credentials — that row is half-right already.

Sprint mapping stays: S06 is still payment methods and Stripe, S07 is still SEPA. Only the
scoping noun changes.

### `10-open-decisions.md` — correct the decided list

The bullet beginning "**Stripe: per-site direct keys**" is the dangerous one. Rewrite it:

```markdown
- **Stripe: per legal entity.** Each `legal_entity` holds its own Stripe credentials and is
  merchant of record. No Connect, no platform account, no application fees, no `mode` column.
  Webhook verification is per payment-provider-account (crypto-random route token + signing
  secret), never by PK. `site_stripe_settings` is the current implementation and is replaced in
  S06. Full model: `architecture-payments-and-fiscal.md`. (Supersedes the earlier per-site
  wording recorded in S00.)
```

Add D1–D8 from task 00 to the decided list if that task has not already done it, and move the
legal-entity-count question into "Undecided" with an owner and a date.

### `02-facility.md` — one line

Sites currently list "Stripe keys" among their per-site integration surfaces. Change to: sites
belong to a legal entity, which holds payment credentials; floor maps and sender identities
remain per-site. Sender identities genuinely are per-site — a branch has its own from-address —
so do not sweep those along with the payment change.

### `AGENTS.md` — one line

Add to the non-negotiables summary: *payment credentials and fiscal regime scope to
`legal_entities`, never to sites, and `legal_entity_id` never scopes a query.*

`AGENTS.md` is the file most likely to be the only one a hurried session reads. It is worth
one line.

## The blocking question

`architecture-payments-and-fiscal.md` names it and this task must not close it, only place it
where it cannot be missed:

> **Is the Spanish client one legal entity with several sites, or several entities?**

If one, the table has a single row and costs nothing but future-proofing. If several, invoice
series and Stripe credentials must hang off the entity from day one and the panel needs an
entity management screen in Settings — which is a materially bigger S03.

Record it in `10-open-decisions.md` under "Undecided" with a **named owner** and a **target
date before S03 kickoff**. It is one call to the client's gestor, and the same call should
cover the other two S03 confirmations already listed in the architecture doc (cutover genesis
date, SpaceManager's Verifactu status, invoice series continuity).

## Invariants

Ensure `09-conventions-and-invariants.md` contains, from task 00:

> **34. `legal_entities` is a fiscal domain concept, not a tenancy boundary.** It identifies the
> issuer of an invoice and the holder of payment credentials. It must never appear in a global
> scope, a middleware-applied filter, a queue payload context, or a default query constraint.
> Filtering an invoice *series* by entity is correct; filtering *contacts* by entity is a
> defect.

This is the invariant most likely to erode, because `legal_entity_id` is shaped exactly like the
`company_id` that invariant 1 forbids and every instinct will be to scope by it. Invariant 1 and
invariant 34 must sit near each other in the file so a reader meets them together.

## Acceptance criteria

- [ ] `architecture-payments-and-fiscal.md` carries the authoritative-status header.
- [ ] `05-billing-ledger.md` contains no per-site Stripe credential narrative, no Connect
      language, and points to the architecture doc. Ledger sections unchanged.
- [ ] Invariant 11's rail-specific rewrite appears in `05-billing-ledger.md` and in `09`.
- [ ] Roadmap README D6/D7 carry a superseded banner and are not deleted.
- [ ] `10-open-decisions.md` no longer records per-site Stripe as decided.
- [ ] `02-facility.md` and `AGENTS.md` updated.
- [ ] `09` contains invariants 29–36 from tasks 00, 00b and 02, with 34 adjacent to 1.
- [ ] The legal-entity-count question is in "Undecided" with an owner and a date.
- [ ] `grep -rn "Connect\|site_stripe_settings\|per-site Stripe" docs/` returns only:
      the architecture doc's explanation of why Connect was dropped, the superseded banners, and
      the note that `site_stripe_settings` is current-implementation-to-be-replaced.

## Verification — interrogate a cold session

The point of this task is that a fresh session gives the right answer, so test that directly.
Open a new Cursor chat with no prior context and ask, one at a time:

1. "Where do Stripe credentials live in this system?" → **per legal entity**, not per site.
2. "Can I scope a contacts list query by legal entity?" → **no**, that is invariant 34.
3. "Does this system use Stripe Connect?" → **no**, and it can say why.
4. "When is a payment row written for a SEPA direct debit?" → **at settlement**, not at file
   generation. Returns write reversal payments.
5. "Which table holds the fiscal invoice?" → `invoices`, from S03. The current display grouping
   is `billing_periods`.
6. "What currency is a contract billed in?" → the currency snapshotted on the contract from its
   items' price rows; site and org currency are prefill defaults only.

Paste the six answers into the PR description. Any wrong answer means a doc still contradicts,
and the grep did not find it — fix and re-ask rather than arguing with the session.

## Tests required

None — this task changes no code. The verification section above is the test, and its output
belongs in the PR.
