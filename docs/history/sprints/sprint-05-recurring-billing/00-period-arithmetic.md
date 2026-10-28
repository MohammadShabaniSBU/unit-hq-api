# S05-00 — Period arithmetic

## Context

`BillingMath` computes anchors and *first*-period stubs. The recurring job needs the other
half: given a contract's snapshot (interval, count, anchor model, anchor date) and its
cursor, produce the **next full period window** — and, for catch-up, the ordered sequence
of windows up to a horizon. Pure functions, golden-tested, because every cent the job ever
bills flows through them.

## Scope

**In:** `nextPeriod`, `periodsBetween`, day-count edge cases, the documented
`interval_count` limitation surfaced as a guard.
**Out:** money (charges are task 02), stubs (first periods stay signing-time work),
multi-period epoch for `interval_count > 1` on calendar models (still out of scope per
`10-open-decisions.md` — now *enforced*, see below).

## Behaviour

`App\Support\Billing\BillingMath` gains:

```php
/** @return array{start: CarbonImmutable, end: CarbonImmutable}  end exclusive */
public static function nextPeriod(Contract $c, CarbonInterface $cursor): array
/** @return list<array{start,end}> ordered, [] when none due before $until */
public static function periodsBetween(Contract $c, CarbonInterface $cursor, CarbonInterface $until): array
```

- `cursor` is always a valid period boundary (the invariant `billed_through` maintains);
  `nextPeriod` starts exactly there. Assert it *is* a boundary for the contract's anchor
  and throw on corruption — a bad cursor must fail loudly, not bill a misaligned window.
- **anniversary:** start = cursor, end = start + (count × interval), calendar-aware.
  Monthly month-end behaviour: anchors are `move_in_date`-derived; for move-in on the
  29th–31st, addMonths clamping applies — pin the chosen behaviour (Carbon's clamp:
  Jan 31 + 1 month = Feb 28/29, and *stays* clamped thereafter per addMonthsNoOverflow
  vs not). **Decision: use `addMonthsNoOverflow` from the original anchor, not from the
  cursor**, so windows never drift (compute period N as anchor + N×interval). Golden-test
  the Jan-31 contract across a year including leap.
- **calendar:** monthly on `billing_anchor_day` (1..28 — clamping unreachable by
  construction); end = next boundary.
- **calendar_week:** weekly on the ISO weekday; end = start + 7 × count days.
- **interval_count > 1 on calendar models:** the docs defer true multi-period epochs. Make
  the deferral safe: `nextPeriod` **throws** `UnsupportedCadence` for
  `calendar*` + `count > 1` (settings validation should already prevent it; belt and
  braces), rather than guessing boundaries.
- `periodsBetween` iterates `nextPeriod`; hard cap parameter (task 01 passes the config
  value) — exceeding it throws `CatchUpCapExceeded` carrying the count.

All `CarbonImmutable`, all date-only (midnight-normalised), end-exclusive `[)` throughout —
the same convention as occupancies, item versions and prices.

## Invariants

- Multiply-before-divide / bcmath rules untouched (no money here, but the file hosts them —
  keep the class cohesive and side-effect-free).
- End-exclusive boundaries, consistent with every other window in the system.

## Acceptance criteria

- [ ] Golden tables: anniversary monthly (incl. 29/30/31 move-ins across a leap year),
      anniversary weekly ×2, calendar day-1 and day-28, calendar_week Monday — each ≥6
      consecutive periods asserted start+end.
- [ ] Misaligned cursor throws; `calendar` + count 2 throws `UnsupportedCadence`.
- [ ] `periodsBetween` returns [], 1, and n windows correctly; cap throws with count.
- [ ] Period N computed from anchor (drift test: cursor-chained vs anchor-derived agree
      for 24 periods).

## Tests required

| Test | Asserts |
|---|---|
| `NextPeriodTest::anniversary_monthly_golden` | Incl. month-end + leap |
| `NextPeriodTest::calendar_and_week_golden` | Boundary days |
| `NextPeriodTest::no_drift_over_24_periods` | Anchor-derived |
| `NextPeriodTest::bad_cursor_throws` | Loud corruption |
| `NextPeriodTest::unsupported_cadence_guard` | Deferred scope enforced |
| `PeriodsBetweenTest::sequence_and_cap` | Catch-up shape |
