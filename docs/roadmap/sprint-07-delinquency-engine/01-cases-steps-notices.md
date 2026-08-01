# S07-01 — Cases, steps & notices

## Context

The fact tables. A **case** records that a contract entered delinquency and (eventually)
how it left; **executed steps** record what the ladder did, each pointing at the artefact
it produced (fee charge, hold, notice, task); **notices** — the table S02 designed and
implementation skipped — finally lands, generic across notice types as originally spec'd.
Severity (days overdue, amount, "current stage") is never stored: computed from charges
per invariant 5.

## Scope

**In:** `delinquencies`, `delinquency_steps`, `contract_notices`, open/cure semantics as
pure functions of the ledger, pause/resume, model relations + timeline query.
**Out:** the job that writes these (02), overlock mechanics (03), panel (04).

## Schema changes

```sql
CREATE TABLE delinquencies (
    id BIGSERIAL PRIMARY KEY,
    contract_id BIGINT NOT NULL REFERENCES contracts(id),
    delinquency_policy_id BIGINT NOT NULL REFERENCES delinquency_policies(id), -- resolved at open
    anchor_due_date DATE NOT NULL,        -- oldest unpaid charge due date at open
    opened_on DATE NOT NULL,              -- site-local detection date
    cured_on DATE NULL,                   -- set once; NULL = active
    cure_trigger VARCHAR(24) NULL,        -- payment | write_off | vacated | manual
    paused_at TIMESTAMPTZ NULL,
    paused_reason TEXT NULL,
    paused_by BIGINT NULL REFERENCES employees(id),
    created_at TIMESTAMP, updated_at TIMESTAMP
);
CREATE UNIQUE INDEX delinquencies_open_idx ON delinquencies (contract_id) WHERE cured_on IS NULL;
CREATE INDEX delinquencies_contract_idx ON delinquencies (contract_id, opened_on);

CREATE TABLE delinquency_steps (
    id BIGSERIAL PRIMARY KEY,
    delinquency_id BIGINT NOT NULL REFERENCES delinquencies(id),
    policy_step_id BIGINT NULL REFERENCES delinquency_policy_steps(id), -- NULL = manual action
    action VARCHAR(32) NOT NULL,
    executed_on DATE NOT NULL,
    trigger VARCHAR(16) NOT NULL,         -- ladder | manual | cure
    charge_id BIGINT NULL REFERENCES charges(id),
    unit_hold_id BIGINT NULL REFERENCES unit_holds(id),
    contract_notice_id BIGINT NULL,       -- FK added below
    task_id BIGINT NULL REFERENCES tasks(id),
    detail JSONB NULL,                    -- e.g. computed fee base/amount as strings
    created_by BIGINT NULL REFERENCES employees(id),  -- manual actions
    created_at TIMESTAMP
);
CREATE UNIQUE INDEX ds_once_idx ON delinquency_steps (delinquency_id, policy_step_id)
    WHERE policy_step_id IS NOT NULL;     -- ladder steps fire once per case

-- the table S02-04 spec'd, unchanged in shape, created here:
CREATE TABLE contract_notices ( ... exactly the S02-04 schema ... );
ALTER TABLE delinquency_steps ADD CONSTRAINT ds_notice_fk
    FOREIGN KEY (contract_notice_id) REFERENCES contract_notices(id);
```

Both delinquency tables are **append-only**: cases gain `cured_on`/pause fields once;
steps are never updated or deleted. A wrong manual fee is corrected by the ledger's own
reversal idiom, and the step row stands as history.

## Behaviour (pure functions — the 02 engine calls these)

`App\Support\Delinquency\DelinquencyState`:

```php
public static function overdueCharges(Contract $c): Collection   // due < site-today, open amount > 0, chargeable types
public static function isDelinquent(Contract $c): bool
public static function daysOverdue(Contract $c): ?int            // site-today − oldest unpaid due date
public static function overdueBase(Contract $c): string          // Σ open gross of rent|insurance ONLY — the no-fee-on-fee base
```

Definitions to pin: "open amount" = gross − allocated (computed, bcmath); chargeable
types for *triggering* = everything except `deposit` (an unpaid fee alone keeps a case
open); the *fee base* additionally excludes `late_fee` + `adjustment` + `refund` +
`write_off` — two different sets, named constants, both tested.

**Open:** first evaluation finding `isDelinquent` on an eligible contract (status
`active|notice_given`, site has a policy) with no open case → insert, resolving the
site's policy id at open (a mid-case site policy *swap* doesn't retarget the open case —
the resolved id pins it; policy *edits* still apply live per 00).

**Cure:** evaluation finding no overdue charges → `cured_on` = site-today +
`cure_trigger`; a `cure`-trigger step row is appended (03 hangs overlock release off it).
Vacate/`ended` transition cures with `vacated`; a `write_off` charge zeroing the balance
cures with `write_off`. **Re-delinquency opens a new case** — history stays legible.

**Pause:** open cases only; pausing freezes ladder execution (02 skips paused), never the
facts — charges continue aging, and resume executes whatever offsets elapsed meanwhile
**only from the resume date forward is wrong** — decide and pin: elapsed-while-paused
steps execute on resume (the debt didn't pause; the *actions* did), each marked
`detail.executed_after_pause = true` so the timeline explains the burst. Tier-3 activity
on pause/resume with reason.

## Invariants

Add to `09`:

> **Delinquency severity is computed; delinquency history is facts.** No stage/severity/
> amount column exists on cases. Cases and steps are append-only; ladder steps fire at
> most once per case (partial unique); every step references the artefact it produced.

## Acceptance criteria

- [ ] State functions match hand-computed fixtures incl. partial allocations, both
      exclusion sets, tz boundaries.
- [ ] Open/cure/reopen lifecycle produces the expected rows; one open case max enforced.
- [ ] Pause freezes execution, not aging; resume back-fills elapsed steps flagged.
- [ ] Vacate and write-off cure with correct triggers.
- [ ] `contract_notices` exists per the S02 schema; the S02 rate-change flow is wired to
      it retroactively (the notice row it always meant to write).

## Tests required

| Test | Asserts |
|---|---|
| `DelinquencyStateTest::bases_and_exclusion_sets` | Trigger set ≠ fee base set |
| `DelinquencyCaseTest::open_cure_reopen` | Append-only lifecycle |
| `DelinquencyCaseTest::single_open_case` | Partial unique |
| `DelinquencyCaseTest::pause_resume_backfill_flagged` | Pinned semantics |
| `DelinquencyCaseTest::vacate_and_write_off_cure` | Triggers |
| `ContractNoticeTest::rate_change_retrofit` | S02 gap closed |
