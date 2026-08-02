# Sprint 14 — E-Signature

## Goal

Contracts get signed remotely: a **contract document** rendered from a template
(multi-language, via the S13 family model), sent through an **e-sign provider behind an
adapter interface** (Signable first, per the standing decision), tracked to completion,
with the signed PDF stored immutably against the contract — and the lifecycle gap this
exposes closed properly: a contract can now exist **awaiting signature**, holding its
unit, with charges and invoice deferred until ink.

## The lifecycle problem (solved before any adapter code)

Today, contract creation = signing: charges, invoice, occupancy, all in one transaction
(invariant 20). Remote signature breaks that equation — the tenant may never sign, and
an invoiced-but-unsigned contract is fiscally and commercially wrong. Resolution:

- New status **`awaiting_signature`**, *before* `pending`/`active` in the S02 state
  machine. In it: **no charges, no invoice, no occupancy.** The unit is protected by a
  `unit_holds` row (new type `contract_signature`) — the S01 holds model doing exactly
  what it was built for.
- **Invariant 20 amended, not broken:** first-period charges + invoice + occupancy are
  written in the same transaction as the contract **becoming signed** — which is
  creation for the walk-in path (unchanged, byte-for-byte), and the signature-completion
  webhook for the remote path. One signing routine, two callers.
- Never signed → `cancelled` (operator action or post-expiry), hold released, ledger
  untouched — the tenancy that never was leaves no fiscal trace.

## Standing decisions honoured

Multi-adapter from day one (`ESignProvider` capability interface, registry, per-account
webhook tokens — the comms architecture, fourth application). Signable is adapter #1;
**verify their current API at implementation** (the standing third-party rule).
Document templates join the S13 family model as `channel = 'document'` — locale
variants and the resolver ladder come free.

## Exit criteria

- [ ] An operator prepares a contract, previews the rendered document (es/en per the
      ladder), sends for signature; the tenant signs on Signable's page; the webhook
      lands the signed PDF (hash-recorded, immutable), and the contract transitions to
      signed **with charges + invoice + occupancy created in that transaction**.
- [ ] Walk-in creation is regression-identical (fixture).
- [ ] Decline and expiry are handled states with operator affordances (resend, cancel);
      cancelling an awaiting contract releases the unit with zero ledger rows.
- [ ] The generated document is a **snapshot**: later template or price edits change
      nothing about a sent envelope (the S03 invoice discipline, contract edition).
- [ ] A second adapter could be added touching only an adapter class + registry
      (architecture test, the Sinch idiom).

## Task order

| # | Task | Est. |
|---|---|---|
| 00 | [Awaiting-signature lifecycle](./00-awaiting-signature-lifecycle.md) | 1.5 days |
| 01 | [Contract document templates & rendering](./01-contract-document-templates.md) | 1.5 days |
| 02 | [Provider accounts & Signable adapter](./02-provider-accounts-and-signable.md) | 1 day |
| 03 | [Envelope flow & signed storage](./03-envelope-flow.md) | 1 day |
| 04 | [Surfaces & integration](./04-surfaces.md) | 1 day |

## Risks

**The signing routine must be extracted, not duplicated.** Creation's
charge+invoice+occupancy block becomes `ContractSigning::complete()` called by both
paths. If the webhook path re-implements it, the two will drift on the next billing
change — the preview-equals-commit lesson at its largest scale.

**Webhook-signs race operator-cancels.** The claim idiom (S08): completion and
cancellation both funnel through conditional status transitions; the loser observes.
A signed webhook arriving after cancellation is the ugly case — do **not** silently
resurrect: record it loudly (Tier-3 + attention surface), because a tenant who signed
a cancelled contract is a human conversation, not an automatic state.

**Document snapshots are legal artifacts.** Render once at send: the PDF bytes stored
then are what was signed; the hash recorded then is what any dispute verifies. No
re-render "for display" may ever replace them.

**Unit held ≠ unit occupied.** An awaiting contract blocks availability via its hold
but must not appear in occupancy, billing eligibility, or delinquency scans — grep the
S05/S07 eligibility queries; `awaiting_signature` is outside every one by status, but
assert it, don't assume it.
