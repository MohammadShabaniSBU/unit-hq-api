# S15-02 — Reconciliation engine

## Context

The projector: desired state (00) vs the grant cache vs, periodically, the provider's
own list — computed, diffed, converged. Declarative sync is what makes every business
event (move-in, overlock, payment, vacate) a *fact write plus a nudge* instead of a
provider call, and what makes provider downtime a delay instead of corruption.

## Scope

**In:** the sync job (full + per-contract scopes), diff/converge logic, event nudges
wired from the S01/S02/S07 fact writers, drift detection against `listGrants`,
failure isolation + retry, dry-run, health surface data. **Out:** the delinquency
step (03 — it writes facts and nudges, nothing else), UI (04).

## Behaviour

**The loop.** `access:sync {--site=} {--contract=} {--dry-run}` and the queued
`SyncAccess(scope)` job:

1. Compute `DesiredAccess` for the scope.
2. Load live grants (`applying|applied`) for the scope.
3. Diff → three sets: **to-grant** (desired, no live grant), **to-revoke** (live
   grant, not desired), **stuck** (`applying`/`revoking` older than a threshold, or
   `failed` — retried).
4. Converge, per grant, isolated (the S05 shell posture): to-grant → insert
   `applying` → adapter `grant` → `applied` + refs (PIN handling per 01); failure →
   `failed` + error, Tier-1, next. To-revoke → `revoking` → adapter `revoke` →
   `revoked` (terminal; a re-desired contact gets a *new* row — grant history stays
   legible, the append-only spirit).
5. Insert-first everywhere: the live-unique index is the double-apply guard (the S07
   step idiom); a crash between insert and adapter call leaves `applying` for the
   stuck-retry, never a double grant.

**Nudges.** `afterCommit` dispatch of scoped syncs from: occupancy open/close (S01
writers), overlock place/release (S07), suspension suspend/lift (00), contract
status transitions to/from active-family (S02), transfer (both units' scopes). One
helper (`AccessSync::nudge(contract)`) so call sites are one line; grep-audit that
every fact writer nudges. Scheduled full sync **hourly** as the authoritative
convergence (the sweeper-authoritative idiom, again): nudges are latency, the
schedule is truth.

**Drift.** The hourly full sync additionally pulls `listGrants` and compares three
ways: provider grants we don't know (→ attention: *unknown grant*, with a revoke
action in 04 — never auto-revoke what we didn't place, a human placed it for a
reason); our `applied` missing at the provider (→ re-apply + Tier-1); and the loud
one — a grant present at the provider for a contact our desired state *denies* →
converge (revoke) **and** Tier-3 `access.drift_denied_but_granted` (the README's
louder incident: the delinquent tenant who could still get in).

**Honest states for 04.** Grant rows carry the truth the panel shows: a cured tenant
sees `applying` until convergence — the "feels immediate" promise is the nudge's
minutes, and the UI never claims `applied` early (the S06 no-optimism rule, spatial
edition).

## Acceptance criteria

- [ ] Move-in → nudge → gate + door granted; vacate → revoked; transfer moves the
      door grant and keeps the gate — all through nudges alone (no manual sync in
      the fixtures).
- [ ] Full-sync idempotence: converged state re-syncs to zero operations.
- [ ] Failure isolation: one adapter error leaves it `failed`+retried, siblings
      converge; stuck-`applying` retried by the schedule.
- [ ] Drift: all three cases detected with their distinct postures; the
      denied-but-granted Tier-3 fires.
- [ ] Dry-run prints the three sets, writes nothing (the row-count assert).
- [ ] Nudge grep-audit: every fact writer covered.

## Tests required

| Test | Asserts |
|---|---|
| `SyncConvergeTest::lifecycle_via_nudges_only` | Move-in/vacate/transfer |
| `SyncIdempotenceTest::zero_ops_when_converged` | The declarative promise |
| `SyncIsolationTest::failed_and_stuck_retry` | S05 posture |
| `DriftTest::three_cases_three_postures` | Incl. the Tier-3 |
| `NudgeAuditTest::every_writer_nudges` | The grep, as a test |
