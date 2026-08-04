# S17-02 — Authorization core

## Context

Task 01 built the grant model. Nothing reads it. This task builds the machinery that turns
`(employee, permission, subject)` into an allow or a deny, and — more importantly — makes
sure the machines keep working.

`app/Policies/` does not exist. The only `Gate::define` in the repo is Telescope's. So
there is no existing pattern to follow and every choice made here is copied 297 times in
task 03. Get the shape right before rolling it out.

The single largest risk in this sprint lives in this task. The scheduler runs
`contracts:activate`, `billing:run`, `autopay:collect`, `delinquency:run`,
`automations:run-scheduled`, `automations:resume-waiting`, `access:sync`,
`whatsapp:sync-templates`, `esign:sweep-*` and three comms sweeps — every one of them
writing contracts, charges, invoices and access grants with **no authenticated employee**.
Inbound webhooks do the same. If policies are applied without a system actor first, the
hourly billing run starts throwing at 03:00 and the failure looks like a queue problem for
about a month.

## Scope

**In:**
- `Employee::can(Permission, ?Site)` and the per-request resolution cache
- `App\Support\Auth\SubjectSite` — model → site resolution, exhaustive and fail-loud
- `App\Support\Auth\SystemActor` + `Gate::before`
- Base policy + `AuthServiceProvider` registration convention
- 403 response shape and machine keys
- `authorize()` helper convention for controllers

**Out:**
- Applying it to any controller (task 03)
- List scoping (task 04)

## Schema changes

None.

## Implementation notes

### Resolution

```php
// App\Models\Employee
public function can(Permission $permission, ?Site $site = null): bool
```

Semantics, in this order:

1. A **company-wide** grant carrying the permission allows it anywhere, including when
   `$site` is given.
2. A **site-scoped** grant carrying the permission allows it only when `$site` matches.
3. `$site === null` on a permission the employee holds only at specific sites → **allow**,
   but only for permissions whose subject genuinely has no site (settings, catalogue, RBAC
   admin are company-level by nature and their permissions belong to company roles). For a
   site-bearing subject, task 03 must always pass the site — a null site there is a bug, and
   `SubjectSite` failing loud is what catches it.

Cache the resolved `permission → site_ids` map on the Employee instance for the request.
**Never bake permissions into the Sanctum token.** Token abilities look tempting and are a
trap: revoking a role would leave live tokens carrying stale grants until they expire, and
there is no invalidation story that does not end in a token-revocation sweep. Resolve from
the database per request; it is two indexed queries and one of them is usually cached.

### `SubjectSite` — the part that will be got wrong

```php
final class SubjectSite
{
    public static function for(Model $subject): ?Site;   // null = genuinely company-level
}
```

One exhaustive `match` on the model class. Explicit map, and the default arm **throws**
`UnresolvableSubjectSite`. Fail loud, never fail open: an unmapped model that silently
returns `null` becomes "company-level" and every site-scoped agent gets access to it.

The resolutions that are not obvious:

| Subject | Site comes from |
|---|---|
| `Contract` | Its open `unit_occupancies` row → unit → site. Ended contracts: the most recent occupancy. A transferred contract has two — use the open one, else the latest by `started_on` |
| `ContractItem` | Parent contract |
| `Invoice` | Its contract; entity-level invoices with no contract resolve through `legal_entities` → sites (may be several — see below) |
| `Payment` / `Allocation` | Through the contract it references |
| `Delinquency` | Its contract |
| `MessageThread` | The `site_sender_identities` row / account that owns the conversation; fall back to the contact's most recent contract site |
| `Contact` | **`null` by design** — a Contact is not site-scoped (`07-people-and-auth.md`). Visibility is handled in task 04, not here |
| `AccessPoint` / `AccessGrant` | The site the point belongs to |
| `Unit`, `UnitHold`, `UnitOccupancy` | Directly or via unit |
| `Reservation`, `Offer`, `OfferOption` | Reservation → unit → site; offer options reference a class, so an Offer resolves through its Deal's site if set, else `null` |
| `BillingRun`, `TaxRate`, `Discount`, settings, `LegalEntity`, `Role` | `null` — company-level by nature |

**Multi-site subjects.** A `LegalEntity` can span sites, and an entity-level invoice
therefore has no single site. Return `null` and require a company-level permission for those
subjects — do not invent an "any of these sites" rule, because it turns into the ambient
filtering the invariants forbid. If the client turns out to need site-scoped accountants
against a multi-site entity, that is a decision for `10-open-decisions.md`, not an
improvisation here.

### System actor

```php
final class SystemActor implements Authorizable { /* marker */ }
```

Registered once:

```php
Gate::before(function ($actor) {
    return $actor instanceof SystemActor ? true : null;   // null, never false
});
```

Returning `null` for everyone else is essential — `false` short-circuits every policy and
denies the entire application.

Anything running without a request-bound employee resolves the actor as `SystemActor`:
scheduled commands, queued jobs, webhook controllers, automation node handlers, playbook
step handlers. Provide `App\Support\Auth\Actor::current(): Authorizable` returning the
authenticated employee when there is one and `SystemActor` otherwise, and have task 03 use
it rather than `auth()->user()` at write sites.

**This does not weaken auditing.** Tier-3 activity keeps its existing causer behaviour: a
system-actor write records the causer as null with `properties.actor = 'system'` plus the
originating command or job name, so "who vacated this contract" still answers honestly. The
causer morph is stamped at dispatch during the request lifecycle (invariant 25) — that path
is unchanged and must not be re-resolved inside workers.

**Automation-originated writes are system writes.** `AutomationContext` already suppresses
re-triggering; the actor resolution follows the same context. An automation that vacates a
contract does not need `contract.vacate` — the operator authorised the automation, not each
of its writes. Note this explicitly in the automation docs so it is a decision, not a hole.

### Policies

One policy per subject model, `app/Policies/{Model}Policy.php`, registered explicitly in
`AuthServiceProvider` (an array, not discovery — discovery hides gaps and task 06 needs the
list enumerable).

```php
final class ContractPolicy
{
    public function view(Employee $e, Contract $c): bool
    {
        return $e->can(Permission::ContractView, SubjectSite::for($c));
    }

    public function vacate(Employee $e, Contract $c): bool
    {
        return $e->can(Permission::ContractVacate, SubjectSite::for($c));
    }
}
```

Policy methods stay this thin. Business-rule preconditions (a contract must be `active` to
vacate) belong where they already live — in the controller and model, inside the
transaction. Do not migrate state-machine rules into policies; a policy answers "may this
person", not "is this legal right now", and conflating them means a 403 where the operator
deserves a 422 explaining the state.

For actions with no model instance (`billing.run.execute`, `settings.manage`,
`rbac.manage`), define a Gate keyed by the permission value rather than inventing a policy
on a nonexistent subject.

### Denial shape

403 through the existing `ApiResponsable` shape, machine key, panel translates:

```json
{ "message": "errors.forbidden", "data": { "permission": "contract.vacate", "site_id": 7 } }
```

Never leak *why* beyond the permission name. Do not distinguish "no such record" from "not
allowed to see it" for site-scoped subjects — a 404 for records outside the employee's
sites is the correct answer, and task 04 makes it consistent with list behaviour.

## API surface

No new endpoints. New behaviour: 403 on denied actions, with the shape above.

Add `GET /api/user` → already extended in task 01; nothing further.

## Panel surface

None. Task 05 consumes.

## Invariants

- No `app/Services/` — `SubjectSite`, `SystemActor` and `Actor` are `App\Support\Auth\`
  static helpers and markers, same tier as `BillingMath`.
- Invariant 25 — causer morph stamped at dispatch, never re-resolved in workers. The system
  actor changes the *authorization* answer, not the causer record.
- Invariant 1 / 34 — site resolution happens explicitly at the call site via `SubjectSite`.
  No global scope, no middleware-set current-site, no queue payload context.
- New invariant (next free number):

> **Authorization failure is fail-closed.** An unmapped subject in `SubjectSite` throws; it
> never resolves to `null` and never resolves to "allowed". Adding a model to the morph map
> without adding it to `SubjectSite` fails `SubjectSiteCoverageTest`.

## Acceptance criteria

- [x] `Employee::can()` honours company-wide grants everywhere and site grants only at the
      matching site.
- [x] Permissions are resolved per request from the database; no permission data is written
      into Sanctum tokens (grep proves it).
- [x] Revoking a role takes effect on the employee's **next request**, with no logout.
- [x] `SubjectSite::for()` covers every model in the morph map plus contracts, invoices,
      payments, threads and access records; an unmapped model throws.
- [x] `Gate::before` returns `true` for `SystemActor` and `null` — not `false` — otherwise.
- [x] With no authenticated employee, an artisan command performing an authorized write
      succeeds.
- [x] A denied action returns 403 with `{ message: "errors.forbidden", data: { permission } }`.
- [x] A policy method contains no business-state logic (review checklist item in the PR).

## Tests required

| Test | Asserts |
|---|---|
| `PermissionResolutionTest::company_grant_allows_any_site` | Scope semantics |
| `PermissionResolutionTest::site_grant_denies_other_site` | Scope semantics |
| `PermissionResolutionTest::revocation_applies_next_request` | No token caching |
| `PermissionResolutionTest::permissions_absent_from_token_abilities` | Grep/assert on issued token |
| `SubjectSiteTest::resolves_contract_via_open_occupancy` | The non-obvious one |
| `SubjectSiteTest::resolves_transferred_contract_to_open_occupancy` | Two occupancies, one open |
| `SubjectSiteTest::resolves_ended_contract_to_latest_occupancy` | Closed-contract path |
| `SubjectSiteTest::company_level_subjects_resolve_null` | TaxRate, BillingRun, settings |
| `SubjectSiteTest::unmapped_subject_throws` | Fail-loud, not fail-open |
| `SubjectSiteCoverageTest::every_morph_mapped_model_is_resolvable` | Fails on new models |
| `SystemActorTest::gate_before_allows_system_actor` | Machine path |
| `SystemActorTest::gate_before_returns_null_for_employees` | Policies still run |
| `SystemActorTest::automation_write_does_not_require_permission` | Documented decision under test |
| `ForbiddenResponseTest::shape_is_machine_key_with_permission` | Response contract |
