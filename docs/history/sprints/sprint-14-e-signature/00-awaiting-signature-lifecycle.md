# S14-00 — Awaiting-signature lifecycle

## Context

The foundation: teaching the S02 state machine that a contract can exist before it is
signed, without leaking into billing, occupancy, or delinquency. Everything else in
the sprint hangs off this being airtight.

## Scope

**In:** `AwaitingSignature` status + transitions, the extracted `ContractSigning::
complete()` routine, deferred-creation path, `contract_signature` hold type (+
`unit_holds.contract_id`), cancellation/release, eligibility audits.
**Out:** documents (01), envelopes (03), any provider code.

## Schema changes

```sql
ALTER TABLE unit_holds ADD COLUMN contract_id BIGINT NULL REFERENCES contracts(id);
-- hold_type gains 'contract_signature' (app enum); blocking, non-overlock semantics,
-- exempt from the manual holds API exactly like 'reservation' (S01-02 idiom)
```

`ContractStatus` gains `AwaitingSignature`. Transition map additions:
`awaiting_signature → pending | active | cancelled` (pending/active chosen by move-in
date at completion, mirroring today's creation logic); nothing transitions *into*
`awaiting_signature` except creation. All through `ContractTransition` as ever.

## Behaviour

**The extraction.** Today's creation block — occupancy open, first-period charges,
`InvoiceIssuer::issue`, Verifactu hook (dormant), activity — moves verbatim into
`App\Support\Contracts\ContractSigning::complete(Contract $c): void`, transactional,
called by: (a) the existing creation path immediately (walk-in, **regression fixture:
byte-identical ledger/invoice/occupancy output**), (b) S14-03's signed-webhook
handler. `signed_at` is set here, not at row creation, for the deferred path.

**Deferred creation.** Contract store gains `signature_mode: immediate | remote`
(default immediate — nothing changes for existing callers). Remote: the row is
created `awaiting_signature`, items + prices reference as normal (the document needs
them), **no** `ContractSigning::complete()`, and instead a `contract_signature` hold
on each unit item (guards asserted — an awaiting contract still may not double-book).
`billed_through` stays null until completion (the S05 eligibility query already
requires active/notice_given — assert).

**Completion.** `ContractSigning::complete()` on the awaiting path additionally:
release the signature holds + open occupancies in the same transaction (hold
`released_at`, occupancy from `move_in_date` — the S01 adjacency rules apply), then
the extracted block, then transition per move-in date. Claim-based: the transition is
the conditional update; a racing cancel loses or wins cleanly (README rule; the
signed-after-cancel loud-record lands in 03 where the webhook lives).

**Cancellation.** `awaiting_signature → cancelled`: release holds, no ledger effect,
`ended_reason = 'cancelled'`, activity. The S02 guard "cancel blocked when payments
exist" is trivially satisfied — assert anyway (a deposit taken *before* signature is
a flow we deliberately do not support v1; record in `10-open-decisions.md`:
pre-signature deposits/holding fees are a known future ask).

**Eligibility audits.** Explicit tests: awaiting contracts appear in **none** of —
billing run eligibility (S05), delinquency scan (S07), activation job (S05-03),
occupancy-based availability (they block via the *hold*, not occupancy), contract
board default filters (panel shows them under their own tab in 04).

## Invariants

Amend 20 in `09`:

> **20 (amended).** First-period charges, the first invoice, and occupancy open are
> written in one transaction with the contract **becoming signed** — at creation for
> immediate signing, at signature completion for remote. `ContractSigning::complete`
> is the only implementation; both paths call it.

## Acceptance criteria

- [ ] Walk-in regression fixture: identical output pre/post extraction.
- [ ] Remote creation: row + items + holds, zero charges/invoice/occupancy; guards
      block double-booking against holds and occupancies both.
- [ ] Completion: holds→occupancies swap + full signing block in one transaction;
      rollback on any failure leaves the awaiting state intact (the S04 chain posture:
      completion is atomic or not at all).
- [ ] Cancel releases everything with zero ledger rows.
- [ ] All five eligibility audits green.
- [ ] Transition matrix updated + table-tested; holds API rejects the new type.

## Tests required

| Test | Asserts |
|---|---|
| `SigningExtractionTest::walkin_byte_identical` | The regression fixture |
| `AwaitingTest::deferred_creation_shape` | Holds yes, ledger no |
| `AwaitingTest::completion_atomic_swap` | One transaction, rollback-safe |
| `AwaitingTest::cancel_clean` | Zero fiscal trace |
| `EligibilityAuditTest::five_systems_exclude_awaiting` | The leak check |
