# S15-00 — Access domain model & desired state

## Context

The pure half: what exists (points, grants-as-cache, suspensions) and the computation
that answers "who may open what, right now" from facts. No provider code — 02 projects
this; 01 talks to the world. Getting this task provider-free is what keeps it testable
and the adapters thin.

## Scope

**In:** `access_points`, `access_grants`, `access_suspensions`, the desired-state
computation, suspension lifecycle helpers. **Out:** any HTTP, sync (02), the step
activation (03), UI (04).

## Schema changes

```sql
CREATE TABLE access_points (
    id BIGSERIAL PRIMARY KEY,
    access_provider_account_id BIGINT NOT NULL,        -- FK lands with 01's table
    site_id BIGINT NOT NULL REFERENCES sites(id),
    unit_id BIGINT NULL REFERENCES units(id),          -- NULL = site-level (gate/zone)
    point_type VARCHAR(16) NOT NULL,                   -- unit_door | gate | zone
    provider_point_id VARCHAR(128) NOT NULL,
    label VARCHAR(128) NOT NULL,
    archived_at TIMESTAMP NULL,
    created_at TIMESTAMP, updated_at TIMESTAMP
);
CREATE UNIQUE INDEX ap_provider_idx ON access_points (access_provider_account_id, provider_point_id)
    WHERE archived_at IS NULL;
CREATE UNIQUE INDEX ap_unit_idx ON access_points (unit_id) WHERE unit_id IS NOT NULL AND archived_at IS NULL;
    -- one door per unit v1; multi-door units recorded in 10-open-decisions

CREATE TABLE access_suspensions (
    id BIGSERIAL PRIMARY KEY,
    contract_id BIGINT NOT NULL REFERENCES contracts(id),
    reason VARCHAR(32) NOT NULL,           -- delinquency | manual
    delinquency_id BIGINT NULL REFERENCES delinquencies(id),
    created_by BIGINT NULL REFERENCES employees(id),
    lifted_at TIMESTAMPTZ NULL,            -- lift, never delete (the S10 idiom)
    lifted_by BIGINT NULL, lift_reason VARCHAR(32) NULL,   -- cure | manual | vacated
    created_at TIMESTAMP
);
CREATE UNIQUE INDEX asus_active_idx ON access_suspensions (contract_id) WHERE lifted_at IS NULL;

CREATE TABLE access_grants (
    -- the CACHE of what 02 has projected; never a source of truth
    id BIGSERIAL PRIMARY KEY,
    access_point_id BIGINT NOT NULL REFERENCES access_points(id),
    contact_id BIGINT NOT NULL REFERENCES contacts(id),
    contract_id BIGINT NOT NULL REFERENCES contracts(id),   -- the justifying tenancy
    provider_grant_id VARCHAR(128) NULL,
    state VARCHAR(16) NOT NULL,            -- applying | applied | revoking | revoked | failed
    last_error TEXT NULL,
    applied_at TIMESTAMPTZ NULL, revoked_at TIMESTAMPTZ NULL,
    created_at TIMESTAMP, updated_at TIMESTAMP
);
CREATE UNIQUE INDEX ag_live_idx ON access_grants (access_point_id, contact_id)
    WHERE state IN ('applying','applied');
```

## Behaviour

**Desired state.** `App\Support\Access\DesiredAccess::forSite(Site $s):
Collection<DesiredGrant{contact_id, contract_id, access_point_id}>` — pure, reading
only facts:

A contact should hold a grant on a point when there exists a contract where **all** of:
- status ∈ {active, notice_given} (awaiting/pending/ended/cancelled contribute nothing
  — the S14 audit posture extends here; **pending is a decision**: no access before
  move-in day, the occupancy's `started_on` governs via the next rule)
- for `unit_door` points: an open `unit_occupancies` row for that unit covering
  site-today
- for `gate|zone` points: any such occupancy at the point's site
- **no** unreleased `overlock` hold on that unit (unit doors only — an overlocked
  tenant still enters the gate to reach the office; state this, it will be questioned)
- **no** active `access_suspensions` row for the contract (all points — suspension is
  total by design; per-point suspension is not a v1 concept)

Table-tested exhaustively: the truth table of (status × occupancy × overlock ×
suspension × point type) is this task's real deliverable.

**Suspension helpers.** `AccessSuspension::suspend(contract, reason, ?case, ?by)` /
`::lift(contract, reason, ?by)` — idempotent (active-unique caught), Tier-3 activity
both ways (suspending someone's access is an accountable act, symmetrical with the
S10 suppression-lift posture), and both dispatch the 02 sync nudge `afterCommit`
(defined here as a no-op interface, wired in 02 — the task stays pure).

## Acceptance criteria

- [ ] The truth table: every row of the five-factor matrix asserted, including the
      overlocked-gate-yes-door-no case and the pending-no-access case.
- [ ] Point constraints: one live door per unit; provider-id uniqueness per account.
- [ ] Suspension lifecycle idempotent, audited, lift-never-delete.
- [ ] `DesiredAccess` touches no provider code, no HTTP, no grants table (grep — it
      computes truth, 02 compares it to the cache).

## Tests required

| Test | Asserts |
|---|---|
| `DesiredAccessTest::five_factor_truth_table` | The deliverable |
| `DesiredAccessTest::purity_grep` | Facts in, desires out |
| `AccessSuspensionTest::lifecycle_audited_idempotent` | The helpers |
| `AccessPointTest::uniqueness_constraints` | Both indexes |
