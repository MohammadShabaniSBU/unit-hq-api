# S08-01 — Condition evaluator correctness

## Context

You reported branching "still has problems". Rather than hunt bugs one by one, this task
treats `ConditionEvaluator` as untrusted: write the semantics down first, build the golden
matrix from the written rules, then fix the evaluator until the matrix is green. Branches,
run guards (00), and S09 exit conditions all ride this one class — it is the sprint's
correctness centre.

## Scope

**In:** written semantics doc, golden matrix suite, evaluator fixes, nested AND/OR/NOT
verification, custom-attribute (`attr:{id}`) conditions, branch-handler integration
(skipped-path logging re-verified).
**Out:** new operators beyond fixing declared ones, expression *language* changes, UI.

## The semantics to pin (write `docs/automation-conditions.md`, then implement to it)

Decide and document each — the golden matrix is these rules made executable:

1. **Type discipline.** Comparisons are typed by the *field definition* (native field map /
   attribute definition), never by guessing the value: numeric fields compare numerically
   (`"10" > "9"`), strings lexically, dates as dates (tz: site-local day boundaries for
   date-only, instant for datetime), booleans strictly. A value that fails its field's
   type cast → condition is **false + a step-detail warning**, never a throw mid-run.
2. **Null/missing trichotomy.** `field is null`, field absent from payload, and empty
   string are three cases. Pin: `equals/not_equals` treat null per SQL-ish semantics
   (`null equals x` → false, `null not_equals x` → **false** too — surprising, so it must
   be written down; provide explicit `is_empty` / `is_not_empty` operators as the
   sanctioned way) — empty string is a value, absent field behaves as null.
3. **Collections.** `in` / `not_in` with empty option lists (false / true), `contains` on
   strings vs multi-select attributes (substring vs membership — two operators or
   documented overload, pick one).
4. **Nesting.** AND/OR/NOT to arbitrary depth; short-circuit order is an implementation
   detail *except* that warnings from unevaluated sides must not appear (test it —
   side-effect-free evaluation).
5. **Snapshot vs live.** Trigger-payload conditions read the **snapshot** (what fired the
   trigger); guard conditions (00) read **live** (that's their whole point). Same
   evaluator, an explicit `source` flag — never ambient.
6. **Money.** Amount fields compare via bcmath on the string values — the one place the
   evaluator touches invariant 10.

## Golden matrix

`tests/Unit/Automation/ConditionGoldenTest`: a data-provider table ≥120 rows —
{field type} × {operator} × {value class: normal, null, missing, empty, wrong-type} with
expected outcome + expected-warning flag. Plus nested fixtures (3-deep mixed AND/OR/NOT,
NOT over null-semantics cases). Every row cites its rule number from the doc. The matrix
is the spec's enforcement: future evaluator edits that change a row must change the doc
in the same PR.

Then re-run every existing branch test; fix the evaluator (not the tests) until green —
except where an existing test asserted a behaviour the doc now overrules: change it and
list each in the PR description (these are the "problems" made visible).

## Branch integration

With the evaluator trusted: verify `logic.branch` logs the untaken path as `skipped`
(invariant 24), that branch conditions use snapshot source, and that a mid-graph branch
after a wait (00) evaluates against the *stored* snapshot, not a re-fetch — the
resume-context risk from the sprint README, tested here.

## Acceptance criteria

- [ ] `docs/automation-conditions.md` exists; every golden row cites a rule.
- [ ] Matrix green; existing branch tests green or explicitly re-ruled in the PR list.
- [ ] Wrong-type and deleted-attribute conditions warn-and-false, never throw.
- [ ] Guard-vs-branch source semantics tested (same tree, different `source`, different
      results after a mutation).
- [ ] Post-wait branch reads the snapshot (resume-context test).
- [ ] bcmath path for amount fields (string "9.50" < "10.00" numeric, not lexical).

## Tests required

| Test | Asserts |
|---|---|
| `ConditionGoldenTest::matrix` | ≥120 rows, rule-cited |
| `ConditionGoldenTest::nested_and_side_effect_free` | Short-circuit purity |
| `ConditionSourceTest::snapshot_vs_live` | The rule-5 split |
| `BranchIntegrationTest::skipped_logging_and_post_wait_snapshot` | Engine seam |
