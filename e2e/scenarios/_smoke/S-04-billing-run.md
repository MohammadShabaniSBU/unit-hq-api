---
id: S-04
title: Ops can preview and confirm a billing run and land on the run detail
domain: _smoke
route: /billing/runs
persona: ops
mutates: true
priority: smoke
requires_db: any
depends_on: []
anchors: []
invariants: [11]
---

## Preconditions

Demo world seeded. Reset the database first — this scenario writes billing-run rows (append-only). `ops` has `billing.run.execute`.

The 14-month clock already ran scheduled billing. The dry-run preview may be empty ("Nothing due in the current horizon."). That is a valid seed-end state, not a reason to skip.

## Steps

1. Log in as `ops`. Go to `/billing/runs`. Heading **Billing runs**.
2. Click **Run billing now**. A modal opens with the hint **Preview first — nothing is written until you confirm.**
3. Wait for the preview table or the empty-preview message.
4. Click **Run for real**.

## Expected

- The modal does not write until step 4. Closing with **Cancel** would leave the list unchanged (do not cancel in this run).
- After confirm, a success toast of the form **Billing run finished — {billed} billed, {failed} failed.** appears (counts may be zero).
- The panel navigates to `/billing/runs/:id` (**Billing run #{id}**).
- The detail shows outcome counts (billed / skipped / failed) and a per-contract table when anything was considered.
- If the preview was empty, the new run still exists and reports that nothing was due. That is a pass.
- If the preview had rows, billed + failed on the toast match the detail header.

## Known traps

- There is no site/period picker on this modal. Do not look for one.
- `readonly` and `agent-mad` must not be used — they cannot execute a run.
- Re-running without a reset creates a second run. That is not this scenario's assertion, but it pollutes later billing checks.

## On failure

Screenshot the modal (preview or error) and, if navigation happened, the run detail. Do not reverse charges. Reset before the next mutating scenario. Do not fix the bug — report it in `e2e/runs/<date>/bugs.md`.
