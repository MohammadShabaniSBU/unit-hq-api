# S07-03 — Overlock

## Context

The moment S01 designed for: `HoldType::Overlock` — exempt from the overlap constraint,
coexisting with an occupancy, blocking nothing in availability — finally gets a writer.
Overlock is an *access restriction on an occupied unit*: physically, the operator's second
lock; digitally (S16), a revoked keypad code. This task is thin by design because S01 did
the schema thinking already.

## Scope

**In:** place/release helper (engine + manual paths), auto-release on cure per policy
flag, unit + contract surfaces, the S01 seeded-overlock retrofit (seed rows gain real case
linkage).
**Out:** access-control hardware (S16 — the reserved `revoke_access` action), overlock on
*non*-delinquency grounds (an operator wanting a manual overlock for another reason uses
the generic holds API? **No** — reject `overlock` there as S01-02 already rejects
`reservation`; overlock without a case is a state the timeline can't explain. Manual
overlock goes through the case's manual actions, 04).

## Behaviour

`App\Support\Delinquency\Overlock`:

```php
public static function place(Delinquency $case, ?Employee $by = null): UnitHold|array
public static function release(Delinquency $case, string $reason, ?Employee $by = null): void
```

**Place** — for **each** unit currently occupied by the contract (multi-unit contracts
overlock all their units; a per-unit choice is manual-action territory, 04):
`unit_holds` row `hold_type = overlock`, `starts_on` = site-today, `ends_on` NULL,
`reason` = case reference, `created_by` = engine-null or employee. Idempotent: an
unreleased overlock for that unit+case → return it, no duplicate (the S01 constraint
exempts overlock from overlap protection, so idempotency is this helper's job — partial
unique `(unit_id) WHERE hold_type='overlock' AND released_at IS NULL` added now, which
the S01 constraint's exemption made impossible to express there).

**Release** — set `released_at` (never delete, S01 rule) on the case's unreleased
overlock holds; append a `cure`-or-`manual` step row linking them.

**Auto-release:** the 02 cure path calls `release` when the case's policy has
`auto_release_overlock` (default true). False = operator releases manually after
physically removing the lock — some operators insist the digital state follow the
physical walk, and the policy flag respects that; the case then shows "cured —
overlock pending release" until they do.

**Vacate interplay:** vacate cures the case (01) → auto-release fires before the
occupancy closes in the same evaluation; a vacate against a still-overlocked unit with
`auto_release_overlock = false` **blocks with 422** ("release the overlock first") —
never close a tenancy while the operator's lock is notionally on the door.

## Panel surface

Units list + map (S01-04's badge component): occupied-and-overlocked shows the Occupied
badge **plus** a lock glyph + tooltip (case link) — overlock never masquerades as a
state; it decorates one. Unit detail current-state card names the case. Contract header:
red **Overlocked** chip beside the status badge. i18n `units.overlock.*`,
`contracts.overlock.*`; es: *Sobrecerradura* (confirm with the operator — regional; some
say *doble candado*).

## Invariants

- S01's hold rules: released, never deleted; facts, not cached state.
- Overlock exists only case-linked (holds API keeps rejecting the type).
- The new partial unique: one live overlock per unit.

## Acceptance criteria

- [ ] Ladder overlock places holds on all the contract's occupied units, idempotently;
      timeline links them.
- [ ] Cure auto-releases (flag true); flag false → pending-release state, manual release
      works and appends the step.
- [ ] Vacate blocked while unreleased under flag-false; proceeds (auto-releasing) under
      flag-true.
- [ ] Holds API still rejects `overlock` on create *and* release.
- [ ] Availability untouched throughout (S01's `overlocked_unit_not_double_counted`
      re-asserted through the real writer).
- [ ] Seed retrofit: the S01 overlocked units gain real open cases explaining them.

## Tests required

| Test | Asserts |
|---|---|
| `OverlockTest::place_all_units_idempotent` | Multi-unit + partial unique |
| `OverlockTest::auto_release_on_cure_flag_matrix` | Both flag values |
| `OverlockTest::vacate_guard` | 422 / auto-release paths |
| `OverlockTest::holds_api_still_rejects` | Both verbs |
| `OverlockTest::availability_never_blocked` | S01 promise, real writer |
