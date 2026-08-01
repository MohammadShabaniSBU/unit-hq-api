# S04-04 — Integrity verification, event log & regime rules

## Context

Three closing obligations: the system must be able to **prove its own chain intact** (and
alarm when it is not), keep an **event log** of fiscally relevant system events, and enforce
the **regime ratchet** — enabling starts a chain, nothing ever breaks one. This task also
turns the S03 `fiscal_regime` validation stub into the real transition rules.

## Scope

**In:** chain verification command + scheduled run, tamper alarm (persistent panel banner +
Tier-3), `verifactu_events` log, regime transition guard + entity UI, declaración-responsable
config skeleton.
**Out:** XAdES signing (README gap), the exhaustive statutory event taxonomy (extends as the
declaración is drafted).

## Behaviour

### Chain verification

`php artisan verifactu:verify-chain {entity?}` — per entity: walk records in
`chain_position` order; recompute each huella from stored snapshot fields; assert
`prev_huella` linkage, single genesis, gapless positions. Output per entity: `INTACT (n
records)` or the first divergent position with expected/actual.

Scheduled **daily**; also run automatically before every submission batch (02 hook) — never
send from a broken chain.

**On divergence:** insert an `integrity_alarm` verifactu-event (below), Tier-3
`RecordsActivity::core('verifactu.integrity_failed')`, and set a persistent flag the panel
renders as a **red, non-dismissible banner** on every Billing page for that entity:
tampering detected is a legal-counsel event, not a toast. Submission for that entity pauses
(`SubmitVerifactuRecords` checks the flag). Clearing requires
`verifactu:acknowledge-integrity {entity} --reason=` — a User (superadmin) command, logged;
the divergent state itself is never "fixed" by rewriting.

### Event log (`verifactu_events`)

The SIF must keep its own event record. Reuse the chain idiom at minimal scope:

```sql
CREATE TABLE verifactu_events (
    id BIGSERIAL PRIMARY KEY,
    legal_entity_id BIGINT NOT NULL REFERENCES legal_entities(id),
    event_type VARCHAR(48) NOT NULL,
    payload JSONB NOT NULL,
    occurred_at TIMESTAMPTZ NOT NULL,
    prev_huella VARCHAR(64) NULL,
    huella VARCHAR(64) NOT NULL,
    created_at TIMESTAMP
);
```

Chained per entity like records (own genesis). Emitted this sprint:
`regime_enabled`, `regime_changed`, `chain_genesis`, `submission_batch`
(count + envelope status), `record_rejected`, `record_subsanated`, `invoice_annulled`,
`integrity_check_ok` (daily, one row), `integrity_alarm`, `certificate_rotated`,
`export_produced`. Append-only, no API mutation, excluded from all pruning (`08` doc note:
this is a **fourth** logging surface, fiscal, retention indefinite).

`verifactu:export {entity} {from} {to}` — records + events as the AEAT-shaped XML files to
a zip (inspection readiness; emits `export_produced`).

### Regime transitions (replacing the S03 stub)

`LegalEntity::changeRegime()` guard, per `architecture-payments-and-fiscal.md` §3:

| From → To | Rule |
|---|---|
| `none → verifactu` / `none → no_verificable` | Allowed (ES only); next issuance creates genesis; event logged |
| `verifactu ⇄ no_verificable` | Allowed; chain continues seamlessly; pending submissions: → `no_verificable` keeps already-`pending` rows pending but stops new ones — drain or hold? **Drain**: finish submitting what was created under `verifactu` (they were legally due), create no new pending |
| any → `none` | **Blocked** once any record exists (422 citing §3); allowed only pre-genesis |
| → `ticketbai` / `sii` | Still rejected as unimplemented |

Panel: regime select on entity detail with per-option explanations; the `none` option
disabled-with-tooltip once records exist; confirmation modal restates consequences (es
statutory wording where applicable).

### Declaración responsable skeleton

`config/verifactu.php` gains the producer block 02 already reads (name, NIF, system name,
version) plus a `docs/verifactu-declaracion-responsable.md` template listing the statements
the regulation requires the producer to sign. **Content is a legal document — draft it with
the gestor before any production use.** Task ships the skeleton + a README checklist item;
nothing in code.

## Invariants

Add to `09`:

> **The Verifactu chain is self-verifying and alarms are sticky.** Integrity is checked on
> schedule and before every submission; a detected divergence pauses submission and raises a
> non-dismissible alarm cleared only by a logged superadmin acknowledgement. `verifactu_events`
> is a fourth, fiscal logging surface: chained, append-only, never pruned.

Regime ratchet (§3) enters `09` verbatim.

## Acceptance criteria

- [ ] Verify command passes on seeded chains (including 03's mixed fixture); flipping one
      byte in a snapshot field is detected at the right position.
- [ ] Alarm: banner renders, submission pauses, Tier-3 + event rows written; acknowledge
      command clears flag, logs, never mutates records.
- [ ] All listed events emit with correct chaining; export zip round-trips.
- [ ] Regime matrix enforced exactly; `none` blocked post-genesis; drain semantics on
      `verifactu → no_verificable` tested.
- [ ] `08-activity-logging.md` updated with the fourth surface; `09` gains both invariants;
      declaración skeleton committed.

## Tests required

| Test | Asserts |
|---|---|
| `VerifyChainTest::intact_and_tampered_detection` | Position-accurate |
| `VerifyChainTest::pre_submission_hook_blocks` | Broken chain never submits |
| `IntegrityAlarmTest::sticky_until_acknowledged` | Banner flag + pause + clear path |
| `VerifactuEventTest::chained_append_only` | Own genesis, no pruning |
| `RegimeTransitionTest::full_matrix` | Table-driven incl. drain |
| `ExportTest::zip_contains_records_and_events` | Inspection readiness |
