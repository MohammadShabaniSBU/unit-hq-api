# S17-01 — Permission enum & grant model

## Context

`employees.role` is a string column defaulting to `staff`, documented as `manager|staff`,
and read in exactly two places — `EmployeeAuthController` lines 38 and 66 — both of which
echo it into the login response. Nothing branches on it. There is no employee↔site
assignment table, which is why `SiteAccess::canManageSite` has been returning `true` since
S06 and why `07-people-and-auth.md`'s "site-level staff see only their assigned site(s)"
rule has never been implementable.

This task builds the grant model and nothing else. After it, the data can express "Ana is a
leasing agent at Camden Lock and Port Mose; Marco is company-wide operations manager" — and
absolutely nothing enforces it yet. Enforcement is tasks 02–04.

**Decision recorded here: no `spatie/laravel-permission`.** It solves the cheap half
(roles↔permissions many-to-many plus a cache) and its answer to the expensive half — scoping
— is `teams`, a request-global current-team context. That is the ambient scoping shape
invariants 1 and 34 exist to forbid. Permission-as-a-row is also the wrong identity here:
permission names appear inside policy methods, so they are code, and a table of them invites
runtime-invented permissions that no policy checks. A PHP enum gives grep-as-test, the same
technique that made `revoke_access` verifiable in S15.

## Scope

**In:**
- `App\Support\Auth\Permission` — backed string enum, the complete permission vocabulary
- `roles`, `role_permissions`, `employee_roles` tables
- `Role`, `EmployeeRole` models; relations on `Employee`
- System role seeder (`RbacSystemRoleSeeder`), idempotent
- Backfill migration: every existing employee gets a company-wide grant preserving today's
  behaviour; then drop `employees.role`
- Last-owner protection
- `10-open-decisions.md`: record the Spatie decision, the `Employee` rename rejection, and
  the morph-alias non-decision

**Out:**
- Gates, policies, any enforcement (task 02)
- Route→permission mapping (task 03)
- List scoping (task 04)
- Panel (task 05)

## Schema changes

```sql
CREATE TABLE roles (
    id           BIGSERIAL PRIMARY KEY,
    key          VARCHAR(64) NOT NULL,
    label        VARCHAR(128) NOT NULL,
    description  TEXT NULL,
    scope_level  VARCHAR(16) NOT NULL,               -- company | site | any
    is_system    BOOLEAN NOT NULL DEFAULT false,
    archived_at  TIMESTAMP NULL,
    created_at   TIMESTAMP,
    updated_at   TIMESTAMP
);
CREATE UNIQUE INDEX roles_key_idx ON roles (key);

CREATE TABLE role_permissions (
    id          BIGSERIAL PRIMARY KEY,
    role_id     BIGINT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    permission  VARCHAR(64) NOT NULL,                -- Permission enum value
    created_at  TIMESTAMP,
    updated_at  TIMESTAMP
);
CREATE UNIQUE INDEX role_permissions_idx ON role_permissions (role_id, permission);

CREATE TABLE employee_roles (
    id           BIGSERIAL PRIMARY KEY,
    employee_id  BIGINT NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
    role_id      BIGINT NOT NULL REFERENCES roles(id),
    site_id      BIGINT NULL REFERENCES sites(id),   -- NULL = company-wide
    granted_by   BIGINT NULL REFERENCES employees(id),
    created_at   TIMESTAMP,
    updated_at   TIMESTAMP
);
CREATE INDEX employee_roles_employee_idx ON employee_roles (employee_id);
CREATE INDEX employee_roles_site_idx ON employee_roles (site_id);
CREATE UNIQUE INDEX employee_roles_scoped_idx
    ON employee_roles (employee_id, role_id, site_id) WHERE site_id IS NOT NULL;
CREATE UNIQUE INDEX employee_roles_company_idx
    ON employee_roles (employee_id, role_id) WHERE site_id IS NULL;
```

Two partial unique indexes rather than one, because `NULL` does not compare equal in a
composite unique index and a company-wide grant would otherwise be duplicable. **SQLite
supports partial indexes**, so unlike the S01 EXCLUDE constraints these need no driver
guard — write them once and let both drivers enforce them.

`scope_level = 'any'` exists for roles that make sense either way (`read_only`). Grant-time
validation: `company` roles reject a `site_id`, `site` roles require one.

```sql
-- migration: drop_role_from_employees  (runs AFTER the backfill migration)
ALTER TABLE employees DROP COLUMN role;
```

## Implementation notes

**`App\Support\Auth\Permission`** — backed string enum, `domain.action`, alphabetised within
domain. Start from this set and reconcile against the real controller list in task 03; the
enum is the vocabulary, task 03 is the mapping.

```php
enum Permission: string
{
    // Leasing
    case ContactView            = 'contact.view';
    case ContactManage          = 'contact.manage';
    case DealManage             = 'deal.manage';
    case OfferManage            = 'offer.manage';
    case OfferSend              = 'offer.send';
    case ReservationManage      = 'reservation.manage';
    case ContractView           = 'contract.view';
    case ContractSign           = 'contract.sign';
    case ContractVacate         = 'contract.vacate';
    case ContractTransfer       = 'contract.transfer';
    case ContractRateChange     = 'contract.rate_change';

    // Facility
    case UnitView               = 'unit.view';
    case UnitManage             = 'unit.manage';
    case UnitHoldManage         = 'unit.hold.manage';
    case SiteManage             = 'site.manage';
    case CatalogueManage        = 'catalogue.manage';   // classes, rates, insurance, discounts

    // Billing & fiscal
    case InvoiceView            = 'invoice.view';
    case InvoiceIssue           = 'invoice.issue';
    case InvoiceRectify         = 'invoice.rectify';
    case PaymentView            = 'payment.view';
    case PaymentRecord          = 'payment.record';
    case PaymentRefund          = 'payment.refund';
    case BillingRunExecute      = 'billing.run.execute';
    case BillingSettingsManage  = 'billing.settings.manage';
    case TaxRateManage          = 'tax.rate.manage';
    case LegalEntityManage      = 'legal_entity.manage';

    // Delinquency
    case DelinquencyView        = 'delinquency.view';
    case DelinquencyAct         = 'delinquency.act';     // pause/resume/notice/overlock
    case DelinquencyWriteOff    = 'delinquency.write_off';

    // Communications
    case InboxView              = 'inbox.view';
    case InboxSend              = 'inbox.send';
    case InboxAssign            = 'inbox.assign';
    case CallPlace              = 'call.place';
    case TemplateManage         = 'template.manage';

    // Operations
    case AutomationView         = 'automation.view';
    case AutomationManage       = 'automation.manage';
    case PlaybookManage         = 'playbook.manage';
    case AccessView             = 'access.view';
    case AccessManage           = 'access.manage';
    case EsignSend              = 'esign.send';

    // Cross-cutting
    case ReportView             = 'report.view';
    case ReportFinancialView    = 'report.financial.view';
    case ActivityView           = 'activity.view';
    case CredentialManage       = 'credential.manage';   // Stripe, comms, e-sign, access
    case SettingsManage         = 'settings.manage';
    case RbacManage             = 'rbac.manage';
}
```

Add `domain(): string` (substring before the first dot) for panel grouping and
`i18nKey(): string` returning `permissions.{value}` — labels live in the panel's locale
files, never in PHP.

**Separate view from manage where a role genuinely stops between them**, and nowhere else.
`ContactView`/`ContactManage` earns its split because read-only accountants exist.
`OfferManage` does not split further, because nobody edits offers but cannot create them.
Resist a full CRUD quartet per model — that is 200 permissions nobody can reason about and
a role editor nobody can use.

**`CredentialManage` is deliberately one permission across Stripe, comms, e-sign and access
provider accounts.** They share `App\Support\Credentials` handling rules and the same blast
radius; splitting them implies a granularity the operator does not actually want.

**System roles** — seeded by `RbacSystemRoleSeeder`, `is_system = true`, permission sets
reset to spec on every run (so a seeder run repairs drift), but grants are never touched.

| Role | Scope | Set |
|---|---|---|
| `owner` | company | Every case in the enum, resolved as `Permission::cases()` so a new permission is automatically owned |
| `operations_manager` | company | Everything except `RbacManage`, `LegalEntityManage`, `CredentialManage` |
| `site_manager` | site | Leasing, facility, delinquency act, inbox, invoice view/issue, payment record, reports (non-financial) |
| `leasing_agent` | site | Contact/deal/offer/reservation manage, contract sign, unit + inbox view/send, esign send |
| `accountant` | company | Invoice issue/rectify, payment view/record/refund, billing run, tax rates, financial reports, activity view. No leasing writes |
| `read_only` | any | Every `*.view` case |

`owner` resolving to `Permission::cases()` is the one place a new permission is granted
implicitly. Every other role is an explicit list, so adding a permission defaults to denied —
which is the correct default and the reason task 06's coverage test can be strict.

**Backfill migration — must not change behaviour.** Run before the column drop:

1. Ensure system roles exist (call the seeder's role-upsert path, not a duplicate copy).
2. Every existing employee with `role = 'manager'` → company-wide `owner` grant.
3. Every existing employee with `role = 'staff'` → company-wide `operations_manager` grant.
4. If no employee would end up with `owner`, promote the lowest-id employee to `owner` and
   emit a Tier-3 activity row recording it.

Step 3 is deliberately generous: today `staff` employees can do everything, and a migration
is the wrong place to take capability away from a live operator without them knowing.
Narrowing is an operator action in the panel (task 05), and the release note should say so.

**Last-owner protection.** `EmployeeRole` deletion refuses when it would leave zero
company-wide `owner` grants across all employees. Implement in the model's `deleting` hook
*and* re-check inside the controller transaction with `SELECT … FOR UPDATE` on the owner
grants — the hook alone loses a concurrent double-revoke. Domain exception → 422 with a
translatable key, never a 500. Same posture as S01's `OccupancyGuard`.

**Where it goes:** `App\Support\Auth\` alongside the existing `SiteAccess` (which task 03
deletes). Static helpers and enums, no state — per the no-`app/Services/` invariant.

## API surface

Read-only in this task; management endpoints land in task 05.

- `GET /api/user` — replaces the scalar `role` with:
  ```json
  { "roles": [ { "key": "site_manager", "label": "…", "site_id": 3 } ],
    "permissions": [ { "permission": "contract.sign", "site_ids": [3, 7] } ],
    "company_permissions": ["report.view"] }
  ```
  `site_ids: null` means company-wide. The panel consumes this in task 05; until then it may
  keep reading a stale field, so **keep emitting `role` as a computed string** (highest
  system role key) for one sprint and mark it `@deprecated`. Delete it in task 05.
- `GET /api/permissions` — the enum, grouped by domain, for the role editor.
- `GET /api/roles` — roles with permission lists.

## Panel surface

None in this task beyond not breaking: task 05 owns the UI. Confirm the panel's auth store
tolerates the added keys and the deprecated `role` field still resolves.

## Invariants

- Invariant 1 — mono-tenant. `employee_roles.site_id` scopes **authorization**, not data
  ownership. It must never become a global scope, middleware context, or queue payload key.
  Same discipline as invariant 34 for `legal_entity_id`.
- Invariant 10 — no money here, but activity rows for grant changes follow the same
  properties-as-strings rule.
- Invariant 15 — activity is append-only; the backfill writes new rows, never rewrites.
- Invariant 28 / archive-only family — roles are `archived_at`, never hard-deleted, because
  historical grants and activity reference them.
- New invariants (next free numbers — file is at 34; confirm before appending):

> **Permissions are a PHP enum, never database rows.** `role_permissions` stores enum
> values. A permission that does not exist as an enum case is a defect, not data.

> **At least one employee holds a company-wide `owner` grant at all times.** Revocation that
> would empty the set is refused inside the transaction, not by UI affordance.

## Acceptance criteria

- [ ] `roles`, `role_permissions`, `employee_roles` migrate on SQLite and Postgres; both
      partial unique indexes enforce on both drivers.
- [ ] `Permission` enum exists; every case has a `domain()` and an `i18nKey()`.
- [ ] `RbacSystemRoleSeeder` is idempotent — running twice changes no rows; running after a
      manual permission edit to a system role restores the spec set.
- [ ] `owner` holds every enum case, verified by a test that compares against
      `Permission::cases()` so a newly added permission fails nothing.
- [ ] Backfill leaves every pre-existing employee with a company-wide grant; no employee
      loses capability at migration time.
- [ ] `employees.role` column is gone; no code references it.
- [ ] Removing the last company-wide `owner` grant returns 422 with a translatable key.
- [ ] Two concurrent revocations of the last two owner grants leave exactly one standing.
- [ ] `GET /api/user` returns `roles` + `permissions`; `role` still present and marked
      deprecated.
- [ ] `10-open-decisions.md` records: no Spatie (with rationale), `Employee` not renamed
      (with the measured blast radius), morph alias deliberately not added (with the
      split-`causer_type` rationale).
- [ ] `07-people-and-auth.md` updated — the "site-level staff" rule now has a data model.

## Tests required

| Test | Asserts |
|---|---|
| `PermissionEnumTest::values_are_unique_and_dotted` | Vocabulary hygiene |
| `RoleSeederTest::is_idempotent` | Second run is a no-op |
| `RoleSeederTest::repairs_drifted_system_role` | Manual edit restored |
| `RoleSeederTest::owner_holds_every_permission` | Compares to `Permission::cases()` |
| `RoleSeederTest::non_owner_roles_are_explicit_lists` | A new enum case does not silently widen any non-owner role |
| `EmployeeRoleTest::company_role_rejects_site_id` | Grant-time scope validation |
| `EmployeeRoleTest::site_role_requires_site_id` | Grant-time scope validation |
| `EmployeeRoleTest::duplicate_company_grant_rejected` | Partial unique on NULL site |
| `EmployeeRoleTest::duplicate_site_grant_rejected` | Partial unique |
| `OwnerFloorTest::cannot_revoke_last_owner` | 422, grant survives |
| `OwnerFloorTest::concurrent_revocation_leaves_one_owner` | `FOR UPDATE` path |
| `RbacBackfillTest::existing_employees_keep_capability` | Migration grants company-wide roles |
| `RbacBackfillTest::promotes_an_owner_when_none_would_exist` | Lockout floor + Tier-3 row |
