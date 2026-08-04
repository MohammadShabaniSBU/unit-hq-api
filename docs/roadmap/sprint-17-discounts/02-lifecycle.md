# DISC-02 — Lifecycle: rate changes, removal, edges

## Context

A discount lives inside a contract that transfers, re-rates, and ends. Three
behaviours get pinned — percent tracking (D-DISC #3), the remove button, and the
transfer/vacate edges — all as fixtures over machinery that mostly already behaves.

## Scope

**In:** rate-change recompute for tracking percents, the remove endpoint/action,
transfer + vacate + notice edge fixtures, seeder extension (discounted personas in
the demo world). **Out:** clawback (D-DISC #1 — recorded), re-attachment post-
removal (attach flows are 03's create-time surfaces; mid-contract granting is the
recorded follow-up with the same compiler).

## Behaviour

**Rate-change recompute.** The scheduled-rate-change path (S02-04), when the
target item's *current version* carries a `discount_id` of a `percent` discount
with `tracks_rate_changes`: the new version's amount = `round2(new_list × (1−p))`,
provenance carried forward, activity property notes both figures ("list 210.00 →
contract 168.00 (20%)"). Non-tracking rows and `free_time` (whose schedule is
finite and typically past): the change applies to segments *at/after* its effective
date at plain list — a mid-free-period rate change does not touch the promised €0
windows (fixture: rate change effective inside period 2 of the 3-month tier —
the 50% segment recomputes? **No**: free-time segments are promises in absolute
weeks, not percentages of list — they recompute proportionally:
`new_list × same_multiplier`. State it exactly this way; it's the one subtle
rule, and "the multiplier is the promise" is the sentence that pins it).

**Removal.** `DELETE /api/contracts/{id}/discount` (confirm + required reason):
compiles one version — list price from the **next period boundary** (billed
periods stand; mid-period removal doesn't claw the current period — consistent
with no-clawback), provenance closed (`removed_at/by/reason` on the linkage),
Tier-3 `contract.discount_removed` with figures as strings. Removing during an
unbilled free period: the *future* free segments collapse to list from next
boundary too — removal means removal; the fixture makes it visible.

**Edges (fixtures, mostly no code).** Transfer `retain_rate`: the discounted
contract price travels (it's just the price — already how retain works); transfer
`destination_rate`: new list, discount provenance closed with reason
`transfer` (a new unit is a new deal — state in the transfer UI copy). Vacate
mid-free-period: settlement math reads versions as ever — the "gap charge" of a
€0 period is €0, correct and slightly funny; assert it. Notice during discount:
nothing special — the stop line and versions compose.

**Seeder.** Two cast personas gain discounts (one 20% menu veteran across a rate
change — the recompute visible in history; one 2-month free-time signed 3 weeks
before seed-end — currently in their €0 period, the rent roll showing it), crowd
gets ~15 discounted contracts across kinds.

## Acceptance criteria

- [x] Tracking recompute exact across a seeded rate change; non-tracking stays;
      free-time multiplier-promise fixture green.
- [x] Removal: next-boundary list, mid-free collapse, audit, reason required.
- [x] Transfer both modes + vacate-in-free + notice fixtures green with **zero new
      billing/settlement code** (the grep extends).
- [x] Demo world: the two personas + crowd, verification matrix updated.

## Tests required

| Test | Asserts |
|---|---|
| `RecomputeTest::tracking_nontracking_multiplier_promise` | D-DISC #3 pinned |
| `RemovalTest::boundary_collapse_audit` | The button's semantics |
| `EdgeTest::transfer_vacate_notice_zero_new_code` | Composition |
| Manual `demo:seed` (Nadia / Amara) | Discount cast end-states present for demos |
