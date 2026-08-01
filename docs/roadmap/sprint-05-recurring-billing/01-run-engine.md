# S05-01 — Run engine & observability tables

## Context

The job itself: find eligible contracts, process each in its own transaction, record what
happened. The engine knows nothing about charges or invoices (task 02 plugs in); its whole
job is eligibility, locking, isolation, and the audit trail — the parts that make a money
job trustworthy.

## Scope

**In:** `billing_runs` / `billing_run_items`, eligibility query, per-contract transaction
shell with lock + failure isolation, `billing:run` command with `--dry-run` and
`--contract=` targeting.
**Out:** what gets written per contract (02), scheduling (03), panel (04).

## Schema changes

```sql
CREATE TABLE billing_runs (
    id            BIGSERIAL PRIMARY KEY,
    started_at    TIMESTAMPTZ NOT NULL,
    finished_at   TIMESTAMPTZ NULL,
    trigger       VARCHAR(16) NOT NULL,          -- scheduled | manual | retry
    horizon_date  DATE NOT NULL,                 -- resolved at start, recorded for audit
    contracts_considered INTEGER NOT NULL DEFAULT 0,
    contracts_billed     INTEGER NOT NULL DEFAULT 0,
    contracts_skipped    INTEGER NOT NULL DEFAULT 0,
    contracts_failed     INTEGER NOT NULL DEFAULT 0,
    created_by    BIGINT NULL REFERENCES employees(id),   -- manual runs
    created_at TIMESTAMP
);

CREATE TABLE billing_run_items (
    id             BIGSERIAL PRIMARY KEY,
    billing_run_id BIGINT NOT NULL REFERENCES billing_runs(id),
    contract_id    BIGINT NOT NULL REFERENCES contracts(id),
    outcome        VARCHAR(16) NOT NULL,   -- billed | skipped | failed
    periods_billed SMALLINT NOT NULL DEFAULT 0,
    detail         VARCHAR(64) NULL,       -- skip/fail reason key, e.g. 'not_due',
                                           -- 'fiscal_blocker', 'catch_up_cap', 'stop_line'
    error_message  TEXT NULL,
    invoice_ids    JSONB NULL,             -- issued this item, for the panel
    amount_total   NUMERIC(10,2) NULL,     -- gross billed, display snapshot
    currency       CHAR(3) NULL,
    created_at TIMESTAMP
);
CREATE INDEX bri_run_idx ON billing_run_items (billing_run_id, outcome);
CREATE INDEX bri_contract_idx ON billing_run_items (contract_id);
```

Append-only in spirit: runs finish, items are written once. No update/delete API.

## Behaviour

### Eligibility

One indexed query, not a scan-and-filter loop:

```sql
SELECT id FROM contracts
WHERE status IN ('active', 'notice_given')
  AND billed_through <= :horizon      -- horizon resolved per task 03's setting
ORDER BY id
```

Per-site "today" refinement happens inside the per-contract step (the query over-selects
by at most a day; the arithmetic then says "nothing due" → `skipped/not_due`, which is
cheap and keeps the query simple). Contracts in `pending/ended/cancelled` never enter.

### Per-contract shell

For each id — **its own transaction**, sequential (no parallel workers this sprint;
correctness first, the run is I/O-light):

1. `SELECT … FOR UPDATE` the contract row. Re-check status + cursor after lock (a vacate
   may have raced the eligibility query).
2. Resolve the contract's site-today via `SiteClock`; compute due periods via
   `BillingMath::periodsBetween(cursor, horizonForContract, cap)`.
3. None due → record `skipped/not_due`, commit, next.
4. Delegate to task 02's generator per period, in order; advance `billed_through` to the
   last period's end **in the same transaction**.
5. Record `billed` with counts/amounts/invoice ids.

**Failure isolation:** the whole per-contract transaction rolls back on any throw; record
`failed` + reason key + message (the record write happens *outside* the failed transaction
— open a fresh one); Tier-1 `billing.contract.failed`; continue with the next contract.
`CatchUpCapExceeded` records `failed/catch_up_cap` — human review, not silent partial
billing. The run itself only aborts on infrastructure-level errors.

### Command

`billing:run {--dry-run} {--contract=} {--trigger=manual}`
`--dry-run`: full eligibility + arithmetic, prints the would-bill table (contract, periods,
window, est. amount), writes **nothing** — including no run row.
`--contract=`: single-contract targeting for support situations; still writes a run
(trigger `manual`) so it's audited.

Activity: `billing.run.completed` Tier-2 on a new `billing` `LogChannel` value (the channel
already exists reserved in `08` — this sprint starts using it); per-contract failures Tier-1.

## Invariants

Add to `09`:

> **Recurring billing is cursor-serialised.** The only idempotency mechanism is the
> row-locked read-and-advance of `contracts.billed_through`; charges, invoice and cursor
> advance commit atomically per contract per run. No secondary dedup state may be
> introduced. Run and run-item rows are append-only.

## Acceptance criteria

- [ ] Immediate re-run after a full run: every item `skipped/not_due`, zero writes beyond
      the run rows.
- [ ] Poisoned contract (task-02 stub throwing): recorded `failed`, others unaffected, run
      finishes with correct counters.
- [ ] Vacate racing the run: post-lock re-check yields skip, no billing past `ended`.
- [ ] Dry-run byte-writes nothing (DB row-count assertion) and prints the table.
- [ ] Cap breach records `failed/catch_up_cap` without partial cursor advance.
- [ ] `Pgsql` concurrency: two overlapping runs never double-bill (lock contention test).

## Tests required

| Test | Asserts |
|---|---|
| `RunEngineTest::rerun_is_noop` | Cursor idempotency |
| `RunEngineTest::failure_isolated_and_recorded` | Fresh-transaction record write |
| `RunEngineTest::status_recheck_after_lock` | Race with vacate |
| `RunEngineTest::dry_run_writes_nothing` | Incl. no run row |
| `RunEngineTest::cap_fails_cleanly` | No partial advance |
| `Pgsql/RunConcurrencyTest::overlapping_runs_single_bill` | FOR UPDATE does its job |
