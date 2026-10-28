# S02-01 — Contract status model and transition guard

## Context

`contracts.status` currently carries `Active` and little else. Every transition in this
sprint — notice, move-out, transfer, cancellation — needs a defined source and target state,
and needs to be rejected when applied to a contract in the wrong state. Vacating an
already-vacated contract must fail loudly, not write a second occupancy close.

This is a small task deliberately placed before the transitions themselves, so all three can
share one guard rather than each inventing its own validation.

## Scope

**In:**
- `ContractStatus` enum and the permitted transition map
- A single transition guard used by every lifecycle action
- Status-change activity logging
- Notice fields on `contracts`

**Out:**
- The transitions themselves (tasks 02–04)
- Delinquency states — overdue is computed per-charge, never a contract status (invariant 5).
  S08 adds overlock as a *hold*, not a status.

## Behaviour

### States

| Status | Meaning | Billing runs? | Occupancy open? |
|---|---|---|---|
| `pending` | Signed, move-in date in the future | No | Yes, future-dated |
| `active` | In force | Yes | Yes |
| `notice_given` | Move-out scheduled, still in force | Yes | Yes, end-dated |
| `ended` | Moved out, settled | No | No |
| `cancelled` | Never commenced | No | No |

`cancelled` exists for the signed-but-never-moved-in case. It is distinct from `ended`
because it must be excluded from tenancy and churn reporting in S17.

### Permitted transitions

```
pending      → active | cancelled
active       → notice_given | ended | cancelled
notice_given → active | ended
ended        → (terminal)
cancelled    → (terminal)
```

`notice_given → active` is notice withdrawal, which tenants do routinely. It must reopen the
occupancy's `ended_on` back to `NULL` and clear the notice fields.

`active → cancelled` is permitted only when the contract has **no payments allocated**. A
contract that has taken money must be ended, not cancelled, so the ledger stays coherent.
Enforce this in the guard, not in the controller.

### The guard

`App\Support\Contracts\ContractTransition`:

```php
public static function assert(Contract $contract, ContractStatus $to): void
public static function allowed(Contract $contract): array   // for the panel
```

`assert` throws a domain exception mapped to 422 with a translatable key naming both states.
`allowed` returns the currently-valid targets so the panel can render only the actions that
will succeed — never render an action the guard will reject.

Every transition runs **inside the caller's transaction**, alongside the occupancy and ledger
writes it accompanies. Status must never be updated in its own separate transaction; a
partial failure that leaves status advanced but occupancy open is exactly the corruption this
sprint is meant to prevent.

## Schema changes

```sql
ALTER TABLE contracts ADD COLUMN notice_given_on   DATE NULL;
ALTER TABLE contracts ADD COLUMN notice_period_days SMALLINT NULL;  -- snapshot at signing
ALTER TABLE contracts ADD COLUMN move_out_on       DATE NULL;       -- actual, set at ended
ALTER TABLE contracts ADD COLUMN ended_reason      VARCHAR(32) NULL;
    -- vacated | non_payment | transferred_out | operator_terminated | cancelled

CREATE INDEX contracts_status_idx ON contracts (status);
```

`notice_period_days` is snapshotted at signing from site settings, per invariant 18 — a later
change to site notice terms must not retroactively alter existing contracts.

Confirm how `status` is currently stored (string column plus PHP enum, or DB enum) before
writing the migration; if it is a string column backed by an enum class, no migration is
needed for the status values themselves.

## API surface

```
GET /api/contracts/{id}  →  status, allowed_transitions: Array<string>,
                            notice_given_on, move_out_on, ended_reason
```

No generic status-setter endpoint. Each transition gets its own verb endpoint in tasks 02–04
(`/notice`, `/vacate`, `/transfer`, `/cancel`) because each carries different required data
and different side effects. A generic `PATCH status` would let a caller skip the ledger work.

## Panel surface

Contract detail header: status badge, and an actions menu rendered from
`allowed_transitions` only. Actions absent from that array are not shown — not shown and
disabled, actually absent, so the UI cannot drift from the guard.

i18n keys under `contracts.status.*` and `contracts.transitions.*`. Spanish matters most:
`pending` → *Pendiente*, `active` → *Activo*, `notice_given` → *Preaviso*,
`ended` → *Finalizado*, `cancelled` → *Anulado*. Have the client's operator confirm
*Preaviso* — it is the standard Spanish tenancy term but usage varies.

## Invariants

- Invariant 5 — overdue is computed per-charge and is **not** a contract status. Do not add
  an `overdue` or `delinquent` state here or in S08.
- Invariant 18 — `notice_period_days` is snapshotted at signing.
- Invariant 14 — activity `description` is a machine key. Use `contract.status_changed` with
  `from` and `to` in properties.
- Invariant 16 — the status-change activity row goes through `RecordsActivity::core`, inside
  the transaction.

## Acceptance criteria

- [ ] `ContractStatus` enum exists with all five states.
- [ ] `ContractTransition::assert` rejects every transition outside the map, with a 422 and a
      translatable key.
- [ ] `active → cancelled` is rejected when any payment is allocated to the contract.
- [ ] `allowed()` returns exactly the set the guard would accept.
- [ ] Panel renders actions solely from `allowed_transitions`.
- [ ] A `contract.status_changed` core activity row is written inside the same transaction as
      the transition.
- [ ] Seeder produces at least one contract in each of the five states.
- [ ] `en.json`, `es.json`, `fr.json` carry all status and transition keys.

## Tests required

| Test | Asserts |
|---|---|
| `ContractTransitionTest::permitted_transitions_succeed` | Full map, table-driven |
| `ContractTransitionTest::forbidden_transitions_rejected` | 422, state unchanged |
| `ContractTransitionTest::terminal_states_reject_everything` | `ended`, `cancelled` |
| `ContractTransitionTest::cancel_blocked_when_payments_exist` | Ledger coherence |
| `ContractTransitionTest::notice_withdrawal_reopens_occupancy` | `ended_on` back to null |
| `ContractTransitionTest::allowed_matches_assert` | The two never disagree |
| `ContractTransitionTest::status_change_logs_core_activity` | In-transaction, machine key |
