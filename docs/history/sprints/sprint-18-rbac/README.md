# Sprint 17 — Authorization & RBAC

## Goal

Turn "authenticated" from a synonym for "omnipotent" into a real authorization decision:
every route reaches one, every decision is a named permission, every permission is granted
per employee **per site**, and nothing about that scoping is ambient.

This is the last committed sprint of the roadmap and the gate before a second employee
account exists on any deployment.

## Why now — and one item that cannot wait for the sprint

A repo audit on `dev` (2026-08-04) found the following. Read all five before planning.

**1. The perimeter is open.** `routes/api.php` opens `Route::middleware('auth:sanctum')->group(...)`
at line 12 and **closes it at line 113**. The remaining 218 of 297 route declarations sit
outside it. `bootstrap/app.php` appends only `AssignRequestId`; no controller declares
constructor middleware or implements `HasMiddleware`. Confirmed public today:

- `apiResource('contracts')`, `('invoices')`, `('payments')`, `('units')`
- `GET/PATCH /settings/general`, `/settings/billing`, `/settings/leasing`, `/settings/activity-log`
- `tax-rates` (incl. `POST`, `PATCH`, `set-default`), `discounts`, `insurances`
- `automations`, `playbooks`, `template-families`, `whatsapp-templates`
- `GET /activities`

Unauthenticated read of the full contact/contract/invoice set, and unauthenticated write to
org billing configuration. **Task 00 is a hotfix. Ship it on its own branch before this
sprint is planned**, do not wait for the permission model.

**2. Roles are decorative.** `employees.role` (`manager|staff`) is read in exactly two
places, both in `EmployeeAuthController` (lines 38 and 66), both echoing it into the login
response. No branch anywhere consumes it.

**3. The `SiteAccess` choke point is one-eighth built.** `App\Support\Auth\SiteAccess::canManageSite`
returns `true` unconditionally and is called from **one** controller
(`Facility\SiteSenderIdentityController`). Docs `05-billing-ledger.md` and
`06-communications.md` both claim "controllers already call through this single helper so
wiring real scoping later is a one-file change." That is not true today and the docs get
corrected in task 03.

**4. There are no policies and no gates.** `app/Policies/` does not exist. The only
`Gate::define` in the codebase is Telescope's `viewTelescope`.

**5. Employee is absent from the morph map.** Every other subject has an alias;
`Employee` does not, so `activity_log.causer_type` carries the FQCN `App\Models\Employee`.

### Revised call on the morph alias

Last thread I said I'd add `'employee' => Employee::class` to the morph map in this sprint.
**Withdrawing that.** Adding the alias makes new rows write `employee` while every historical
row keeps the FQCN, and invariant 15 forbids backfilling append-only history — so
`causer_type` would carry two values forever and every causer filter would need to accept
both. Since the rename is rejected (below), the alias buys consistency we don't need at a
cost we'd carry permanently. Record the omission as deliberate in `10-open-decisions.md`
instead. Laravel resolves the FQCN fine through its own fallback; nothing is broken.

### Rejected in this sprint: renaming `Employee` → `Member`

Measured blast radius: 24 migrations reference `employees`, ~50 FK declarations
(`created_by` / `assigned_to` / `employee_id`), 1,124 mentions across 232 files in
`app/ database/ tests/ routes/`. Zero user-visible benefit, and "member" is the word a
storage operator most naturally applies to the *tenant*, in a product whose central identity
split is Contact-vs-Employee. If external accountants or vendor technicians need dashboard
logins, that is a **role** (`external_accountant`, read-only, scoped), not a class rename.
Recorded as Decided in `10-open-decisions.md` by task 01 so it does not resurface.

## Exit criteria

- [x] Every route in `routes/api.php` is either inside the authenticated group or on an
      explicit public allowlist with a written reason; a test enumerates the router and
      fails on any route that is neither.
- [x] Every authenticated route reaches an authorization decision — a policy, a gate, or an
      explicit `Permission::None` allowlist entry with a reason. Proven by test, not review.
- [x] An employee granted `leasing_agent` at one site can sign a contract there, receives
      403 at another site, and does not see the other site's contracts in any list.
- [x] Scheduled commands, queued jobs, webhooks and automation-originated writes all run
      green with no authenticated employee (the 03:00 billing-run test).
- [x] `employees.role` is gone; grants live in `employee_roles`; the last company-wide
      `owner` grant cannot be removed.
- [x] The `canEdit` panel stopgap is deleted, not widened. Panel reads permissions from
      `/api/user`; every hidden control has a matching API-side denial.
- [x] `SiteAccess` is deleted and its two call sites go through policies. Docs `05`, `06`,
      `07`, `10` corrected in the same PR.

## Task order

Strictly sequential except where noted. Task 00 ships independently and first.

| # | Task | Est. |
|---|---|---|
| 00 | [Close the perimeter (hotfix)](./00-close-the-perimeter.md) | 0.5 day |
| 01 | [Permission enum & grant model](./01-permission-model.md) | 1 day |
| 02 | [Authorization core — gates, policies, subject-site, system actor](./02-authorization-core.md) | 1 day |
| 03 | [Policy rollout across the API](./03-policy-rollout.md) | 2 days |
| 04 | [List visibility & site scoping](./04-list-visibility.md) | 1.5 days |
| 05 | [Panel — permissions, people & roles UI](./05-panel-rbac.md) | 1.5 days |
| 06 | [Coverage tests & RBAC spine](./06-coverage-and-spine.md) | 1 day |

**~8.5 days — this sprint does not fit a week.** Take a week and a half, or cut task 05's
role *editor* (permission matrix) to a follow-up and ship only the grant assignment UI with
seeded system roles. Do not cut task 06; the coverage tests are what make the other six
verifiable, and without them "we authorized everything" is an unfalsifiable claim.

## Risks

**Locking out the machines.** Billing runs, `contracts:activate`, autopay sweeps,
delinquency ladders, access sync, automation handlers and inbound webhooks all write with no
authenticated employee. A naive policy rollout silently 403s the hourly billing run and
nobody notices until a month's rent is missing. Task 02 introduces `SystemActor` *before*
task 03 applies a single policy, and task 06 asserts it against the real scheduler list.

**The banned shape.** Site scoping looks exactly like the tenancy scoping invariant 1
forbids and the ambient `legal_entity_id` filtering invariant 34 forbids. The distinction
this sprint must hold: authorization scoping is **explicit at call sites**
(`->visibleTo($employee)`), never a global scope, never middleware-set context, never in a
queue payload. If a reviewer cannot see the scoping in the query builder chain, it is wrong.

**Panel hiding mistaken for security.** A hidden button is UX. Task 05 must not be allowed
to satisfy an acceptance criterion that task 03 owns. Every panel-hidden control gets a
paired API test asserting 403.

**Coverage rot.** 297 routes today, more tomorrow. The `RouteAuthCoverageTest` and
`PermissionCoverageTest` must fail closed on *new* routes, so the manifest is a chore
developers cannot skip. Same grep-as-test posture as S15's `revoke_access`.

**Demo world.** `demo:seed --fresh` builds ~18 personas; after this sprint they need grants
or the demo logs in and sees nothing. `DemoWorldVerificationTest` was removed from the suite
for runtime, so task 06 moves its guarantee into the tail of the seeder command itself —
same checks, one pass, no second seed. Spine tests use a small purpose-built fixture and
never invoke `demo:seed`.
