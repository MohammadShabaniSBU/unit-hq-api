# S09-00 — Playbook model & compiler

## Context

The linear source of truth and the thing that turns it into engine graphs. Kind-specific
behaviour (what enrols, what exits) lives in per-kind definition classes so task 02/03
are configuration of this machinery, not re-implementations.

## Scope

**In:** `playbooks` + `playbook_steps`, `PlaybookCompiler`, kind registry
(`PlaybookKind` interface), graph versioning on edit, engine addition:
`single_active_run_per_subject`, compiled-graph protection in the general editor.
**Out:** the concrete kinds' seeds and nuances (02/03), UI (04), any new action handler
(01).

## Schema changes

```sql
CREATE TABLE playbooks (
    id BIGSERIAL PRIMARY KEY,
    kind VARCHAR(24) NOT NULL,               -- debt_process | lead_chase
    name VARCHAR(128) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT false,
    enrolment_filters JSONB NOT NULL DEFAULT '{}',   -- kind-validated knobs (02/03)
    automation_id BIGINT NULL REFERENCES automations(id),  -- current compiled graph
    created_at TIMESTAMP, updated_at TIMESTAMP, archived_at TIMESTAMP NULL
);

CREATE TABLE playbook_steps (
    id BIGSERIAL PRIMARY KEY,
    playbook_id BIGINT NOT NULL REFERENCES playbooks(id),
    offset_days SMALLINT NOT NULL,           -- from enrolment; 0 = immediately
    action VARCHAR(32) NOT NULL,             -- send_email | send_sms | create_task | record_notice
    params JSONB NOT NULL DEFAULT '{}',
    sort SMALLINT NOT NULL,
    created_at TIMESTAMP, updated_at TIMESTAMP
);
CREATE UNIQUE INDEX ps_sort_idx ON playbook_steps (playbook_id, sort);

ALTER TABLE automations ADD COLUMN single_active_run_per_subject BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE automations ADD COLUMN playbook_id BIGINT NULL REFERENCES playbooks(id);
```

## Behaviour

**Kind registry.** `App\Support\Playbooks\Kinds\{DebtProcess,LeadChase}` implementing:

```php
interface PlaybookKind {
    public function trigger(): array;                 // trigger node config
    public function guard(array $filters): array;     // exit condition tree (evaluator syntax, live source)
    public function allowedActions(): array;
    public function validateFilters(array $f): void;
    public function subjectDescriptor(): string;      // 'delinquency' | 'deal' — vocabulary + panel links
}
```

**Compiler.** `PlaybookCompiler::compile(Playbook $p): Automation` — emits the bulk-graph
PATCH shape (the fixture format): trigger (from kind + filters) → for each step in sort
order: `logic.wait` (relative `offset_days − previous_offset`, skipped when 0; resolved
to the site send-window start per the README quiet-hours rule) → action node. Guard from
`kind->guard(filters)` stored as the automation's default run guard (engine: trigger
enrolment copies it onto the run). `single_active_run_per_subject = true` always for
playbook compilations. Output must load through the same graph validation as the editor —
and the S08 fixtures 10–12 become **compiler golden tests**: compiling the equivalent
playbook must produce graphs the harness runs to the same step sequences.

**Enrolment uniqueness (engine addition).** At trigger match, when the automation has the
flag: skip run creation if an active (`pending|running|waiting`) run exists for the same
`(automation_id, subject)` — implemented as an insert-guard partial-unique
`(automation_id, subject_type, subject_id) WHERE status IN (...)` isn't expressible;
use the S07 idiom: partial unique on a generated `is_active` boolean column
(`active_key` nullable text = subject key when active, NULL when terminal; unique index
on it) — insert-first, constraint-caught, exactly the once-per-case step pattern.
Harness fixture: `single_enrolment_per_subject` (join the library; coverage test counts
it).

**Edit → recompile.** Saving playbook changes compiles a **new** automation version:
new `automations` row (playbook_id linked), old one deactivated-but-kept — active runs
finish on the graph they started (runs reference their automation; nothing rewrites
in-flight state). The playbook's `automation_id` points at current. Panel offers
"also exit N in-flight enrolments" as an explicit bulk-cancel (cause `superseded`) —
default off. Activate/deactivate toggles the current automation's active flag.

**General-editor protection.** Automations with `playbook_id` render read-only in the
graph editor with a banner linking the playbook page; the bulk PATCH endpoint rejects
writes to them (422 `compiled_playbook`).

## Acceptance criteria

- [ ] Compiler output for the reference debt/lead playbooks matches fixtures 10–12
      semantics under the harness (step sequences equal).
- [ ] Zero-offset first step compiles without a leading wait; equal-offset consecutive
      steps compile back-to-back.
- [ ] Guard lands on runs at enrolment; single-enrolment constraint holds under the
      race fixture.
- [ ] Edit produces a new version; in-flight runs finish on the old graph; the
      explicit bulk-exit works with cause `superseded`.
- [ ] Bulk PATCH rejects compiled automations; editor shows them read-only.
- [ ] `HandlerCoverageTest` green (new fixture registered).

## Tests required

| Test | Asserts |
|---|---|
| `CompilerTest::reference_playbooks_match_fixture_semantics` | Golden equivalence |
| `CompilerTest::offset_edge_cases` | 0-offset, equal offsets, window resolution |
| `EnrolmentTest::single_active_per_subject_race` | Constraint idiom |
| `VersioningTest::inflight_finish_old_graph` | + bulk-exit path |
| `EditorProtectionTest::compiled_graphs_read_only` | Both surfaces |
