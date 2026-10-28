# S17-06 — Coverage tests & RBAC spine

## Context

Tasks 00–05 are wide. "We authorized everything" is exactly the kind of claim that is true
at merge and false three sprints later, when someone adds a route to a controller and no
test notices. This task makes the claim falsifiable and keeps it that way.

It follows the house pattern: `DemoSpineTest` (S06), `DelinquencySpineTest` (S07),
`HandlerCoverageTest` (S08), the S15 grep-as-test for `revoke_access`. The coverage tests
fail closed on additions, so a developer adding a route is *forced* into the manifest rather
than reminded by a reviewer.

Do not cut this task. Without it the other six are unverifiable and the sprint's exit
criteria reduce to a reading of the diff.

## Scope

**In:**
- `PermissionCoverageTest` — every route reaches an authorization decision
- `SubjectSiteCoverageTest` — every morph-mapped model resolves (may already exist from
  task 02; consolidate here)
- `PanelPermissionMirrorTest` — PHP enum vs panel constants
- `RbacFixture` — a small purpose-built fixture, seconds not minutes
- `RbacSpineTest` — four employees, one narrative walk
- `SystemActorSpineTest` — the 03:00 test, over the real scheduler list
- Demo world seeder: grants for the personas, verified inside the seeder, not by a test

**Out:**
- New features of any kind

## Schema changes

None.

## Implementation notes

### `PermissionCoverageTest`

Enumerate `Route::getRoutes()`. For each route, resolve one of three answers:

1. It is on the public allowlist from task 00 (with a reason).
2. It has a `RoutePermissions` manifest entry naming a `Permission`.
3. It has an explicit `Exempt::self` / `Exempt::reference` entry with a reason.

Anything else fails, and the failure message lists the offending routes by method and URI —
not a count. A developer who adds a route should read the failure and know exactly what to
do without opening this file.

Second assertion, the one that catches the subtler rot: for each manifest entry naming a
permission, assert the controller action actually calls `authorize()` or `Gate::authorize()`.
Static analysis is enough — reflect the controller method, tokenize the source, look for the
call. This is deliberately a grep-shaped test; a manifest entry with no matching call is a
route that *claims* to be protected and is not, which is worse than an unlisted one.

Third: assert every `Permission` case appears in at least one place — a manifest entry, a
policy method, or a system role definition. A permission nothing checks is dead vocabulary
that misleads the role editor into offering a checkbox with no effect.

### `PanelPermissionMirrorTest`

If `app/types/permissions.ts` is hand-maintained, this test reads it and compares to
`Permission::cases()`. Mismatch in either direction fails. A permission present in PHP but
missing in TS means a control the panel cannot gate; the reverse means a checkbox that gates
nothing. If you generate the TS file from the enum instead, this test asserts the generated
file is current (regenerate in CI and diff).

### `RbacFixture` — the spine tests do not use the demo world

`demo:seed --fresh` replays ~14 months through real services and jobs. That is the right
shape for a demo and the wrong shape for a fixture: a spine test built on it spends most of
its runtime testing the replay, and the suite gets slow enough that people stop running it.

Build `Tests\Fixtures\RbacFixture` instead — the smallest world that can express every
assertion below:

- 2 sites (call them A and B), 1 unit class, 3 units per site
- 2 active contracts at A, 1 at B, each with an occupancy and first-period charges
- 1 contact renting at **both** sites (D-RBAC-1 needs this)
- 1 overdue contract at A with an open delinquency case
- 4 employees: leasing agent @A, site manager @A, company accountant, company owner

Built through the real creation paths — `ContractBilling`, `OccupancyGuard`, the offer
acceptance transaction — never by raw inserts, same reason S01-03 seeds through the guards:
a fixture that bypasses the write path stops proving anything about it.

**Budget: the whole spine test file runs in under 30 seconds.** If it doesn't, the fixture
is too big, not the budget too tight.

### `RbacSpineTest`

One narrative, four employees, on `RbacFixture`. Not unit assertions — a walk:

- **Ana**, `leasing_agent` at site A only
- **Site manager** at site A only
- **Carmen**, `accountant`, company-wide
- **Owner**, company-wide

The walk:

1. Ana signs a contract at site A → 201; contract, items, first-period charges and the
   occupancy row all exist (the S01/S05 invariants still hold under authorization).
2. Ana attempts the same on a site B unit → 403, and the database is unchanged — no
   orphan occupancy, no charge, no contract.
3. Ana lists contracts → only site A rows. Her board counts match her list.
4. Ana opens a site B contract by id → 404, not 403.
5. Ana attempts `POST /billing-runs` → 403.
6. Carmen issues an invoice against Ana's contract → 201. Carmen attempts to sign a contract
   → 403. Carmen's Ageing report reconciles to the company board chip.
7. The site manager pauses the delinquency case at site A → 200; the equivalent at B → 404.
8. Owner revokes Ana's grant and re-grants at site B. Ana's next request reflects it with
   no re-login: site B contracts visible, site A now 404.
9. Owner attempts to remove the last owner grant → 422, grant intact.
10. A contact renting at both sites: visible to Ana (she has a relation at her site), and her
    detail view shows both sites' activity — D-RBAC-1 under test.

### `SystemActorSpineTest`

Read the scheduler list out of `bootstrap/app.php` and run every command with **no
authenticated user**, against `RbacFixture`, asserting each completes and produces its
expected effect:

`system-events:maintain`, `activitylog:prune-tiers`, `automations:run-scheduled`,
`automations:resume-waiting`, `contracts:activate`, `billing:run --trigger=scheduled`,
`autopay:collect --trigger=sweep`, `delinquency:run`, `comms:sweep-orphan-attachments`,
`comms:sweep-uncorrelated-call-intents`, `whatsapp:sync-templates`,
`esign:sweep-completion-pending`, `esign:sweep-expired`, `access:sync`.

Then the same for inbound webhooks: Stripe, e-sign, access, delivery — each posted
unauthenticated with a valid signature/token, each asserted to reach the ledger or thread as
before.

Then automation and playbook handlers: a run that vacates a contract or sends a notice
completes without any employee permission, per the task-02 decision.

**Read the command list from the scheduler at test time**, not from a hardcoded copy, so a
newly scheduled command is covered automatically. That is the difference between this test
aging well and aging into a lie.

### Demo world — grants yes, test no

`DemoWorldVerificationTest` has been removed from the suite for runtime, and this task does
not reinstate it. The demo seeder is still a deliverable, so the guarantee moves rather than
disappears: **verification runs at the tail of `demo:seed` itself**, over data already in
memory, costing one pass instead of a second full seed. A failed check aborts the command
loudly with the offending counts — the S01-03 "seed through the guards, fail immediately"
posture, applied to coherence instead of overlap.

Checks to run in that tail (RBAC-relevant ones are new; keep whatever the removed test
covered that still matters): every persona has at least one grant, at least one company-wide
`owner` exists, and each `leasing_agent` persona's visible contract set is a strict non-empty
subset of the owner's.

`demo:seed --fresh` builds ~18 personas plus a crowd. Assign grants:

- One `owner`
- One company-wide `operations_manager`
- One `site_manager` per site
- Two or three `leasing_agent`s, each at one site, at least one at a site with rich data
- One company-wide `accountant`
- One `read_only`

Print the grant table in `storage/demo-script.md` alongside the existing summary — task 05's
manual script and any future demo depend on knowing which login shows which slice.

## API surface

None.

## Panel surface

None, beyond the mirror test's contract with `app/types/permissions.ts`.

## Invariants

- Grep-as-test posture (S15) — the coverage tests are the enforcement mechanism for the
  task-00 and task-01 invariants; those invariant entries should name these tests.
- Invariant 20 / S01 occupancy rules — the spine test asserts they survive a 403 (nothing
  half-written).
- Fail-closed — every coverage test fails on *addition*, never merely on modification.

## Acceptance criteria

- [x] `PermissionCoverageTest` passes and fails correctly: adding an unlisted route fails it;
      adding a manifest entry without an `authorize()` call fails it; adding an unused
      `Permission` case fails it. Prove all three by temporary edits during development.
- [x] Failure messages name offending routes by method and URI.
- [x] `SubjectSiteCoverageTest` fails when a model is added to the morph map without a
      `SubjectSite` arm.
- [x] `PanelPermissionMirrorTest` fails on drift in either direction.
- [x] `RbacFixture` builds through real creation paths — grep confirms no raw inserts of
      contracts, occupancies or charges.
- [x] `RbacSpineTest` passes all ten steps, and the file runs in under 30 seconds.
- [x] `SystemActorSpineTest` reads the command list from the scheduler and passes for every
      command, every webhook, and the automation path.
- [x] No test in this sprint invokes `demo:seed`.
- [x] `demo:seed --fresh` assigns grants to every persona, prints the grant table, and
      aborts loudly if a persona has no grant or no `owner` exists.
- [x] `php artisan test` green via `docker compose -f docker-compose.test.yml run --rm test`
      (923 passed). Panel `bun run lint` / `typecheck` still carry pre-existing debt outside
      this task's surface; `PanelPermissionMirrorTest` guards `permissions.ts`.
- [x] Sprint README exit criteria demonstrably met on a freshly seeded database.

## Tests required

| Test | Asserts |
|---|---|
| `PermissionCoverageTest::every_route_has_a_decision` | Manifest exhaustive over the router |
| `PermissionCoverageTest::manifest_entries_have_authorize_calls` | No route claims protection it lacks |
| `PermissionCoverageTest::every_permission_is_used` | No dead vocabulary |
| `SubjectSiteCoverageTest::every_morph_mapped_model_resolvable` | Fail-closed on new models |
| `PanelPermissionMirrorTest::php_and_ts_enums_match` | Panel cannot silently miss a gate |
| `RbacSpineTest::leasing_agent_signs_at_granted_site` | Positive path intact under RBAC |
| `RbacSpineTest::denied_signing_writes_nothing` | No orphan occupancy or charge |
| `RbacSpineTest::out_of_scope_record_is_404` | Enumeration defence |
| `RbacSpineTest::accountant_issues_invoice_cannot_sign` | Domain separation |
| `RbacSpineTest::regrant_applies_without_relogin` | Live grant resolution |
| `RbacSpineTest::last_owner_protected` | Lockout floor |
| `RbacSpineTest::cross_site_contact_visible_with_full_history` | D-RBAC-1 |
| `SystemActorSpineTest::all_scheduled_commands_run_headless` | The 03:00 test |
| `SystemActorSpineTest::all_webhooks_run_headless` | Provider callbacks |
| `SystemActorSpineTest::automation_handlers_run_headless` | Task-02 decision under test |
| `RbacSpineTest::spine_runs_within_budget` | Fixture stays small; fails if the file exceeds 30s |
