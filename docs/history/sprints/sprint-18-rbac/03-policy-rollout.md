# S17-03 — Policy rollout across the API

## Context

Tasks 00–02 built the perimeter, the grants and the machinery. This task is the grind: 92
controllers, 297 route declarations, each one getting an authorization decision and a route
manifest entry.

It is the longest task in the sprint and the one most likely to be done 90% and called done.
Task 06's `PermissionCoverageTest` is what makes the last 10% visible, so **write the
manifest as you go** rather than reconstructing it afterwards.

`App\Support\Auth\SiteAccess` is deleted here. It has one caller
(`Facility\SiteSenderIdentityController`) and returns `true` unconditionally, despite
`05-billing-ledger.md` §Credential handling and `06-communications.md` §Authorization gap
both claiming controllers already route through it. Those doc paragraphs get corrected in
this PR.

## Scope

**In:**
- A route→permission manifest, exhaustive over `routes/api.php`
- `authorize()` / `Gate::authorize()` calls in every authenticated controller action
- Policies for every subject model with site-bearing actions
- Deletion of `SiteAccess`; its callers move to policies
- Actor resolution at write sites (`Actor::current()` instead of `auth()->user()`) where a
  path can run headless
- Doc corrections in `05`, `06`, `07`

**Out:**
- List row filtering (task 04) — this task authorizes *reaching* an endpoint; which rows come
  back is next
- Panel (task 05)
- The coverage test itself (task 06), though the manifest it consumes is written here

## Schema changes

None.

## Implementation notes

### The manifest

`app/Support/Auth/RoutePermissions.php` — a single array keyed by route name or
`METHOD /uri`, valued by a `Permission` case or an explicit exemption:

```php
'GET /api/contracts'            => Permission::ContractView,
'POST /api/contracts'           => Permission::ContractSign,
'POST /api/contracts/{contract}/vacate' => Permission::ContractVacate,
'GET /api/user'                 => Exempt::self('own identity'),
'POST /api/logout'              => Exempt::self('own session'),
'GET /api/countries/options'    => Exempt::reference('static reference data'),
```

`Exempt` carries a reason string. An endpoint with no permission and no reason fails task
06's test. This is the same grep-as-test posture as S15's `revoke_access` — the artefact a
reviewer can read in one sitting.

Two legitimate exemption categories only:

- **`Exempt::self`** — acts on the caller's own identity or session (`/user`, `/logout`).
- **`Exempt::reference`** — static, non-sensitive reference data with no business content
  (country lists). `sites/options`, `units/options` and `unit-classes/options` are **not**
  reference data — they leak the estate and go through `*.view`, filtered in task 04.

### Rollout order

Do it domain by domain, one commit each, so a bisect can find the one that broke the panel:

1. **Facility** — `Facility\*` controllers, units, holds, sites, classes, rates, insurance,
   discounts, tax rates. Mostly `UnitView` / `UnitManage` / `CatalogueManage` / `SiteManage`.
2. **Leasing** — contacts, channels, addresses, deals, offers, offer options, reservations,
   tasks, notes, boards. Note the board controllers (`ContactBoardController`,
   `DealBoardController`, `OfferBoardController`, `ReservationBoardController`,
   `TaskBoardController`, `ContractBoardController`) — all `*.view`, all needing task 04
   scoping.
3. **Contracts** — sign, vacate, transfer, rate change, documents, autopay, notices.
   `ContractRateChangeController` → `ContractRateChange`; `ContractAutopayController` →
   `ContractManage`-family, decide and record which.
4. **Billing & fiscal** — invoices, series, payments, payment requests, overdue, billing
   runs, legal entities, entity Stripe. `LegalEntityStripeController` and
   `EsignProviderAccountController` / `AccessProviderAccountController` all →
   `CredentialManage`.
5. **Delinquency** — board, policies, cases, notices, write-off. `writeOff` gets its own
   permission; pause/resume/notice share `DelinquencyAct`.
6. **Comms** — inbox, messages, attachments, triage, calls, Aircall links, template
   families, template assets, WhatsApp templates, unsubscribe (public, untouched).
7. **Operations** — automations, playbooks, access points/grants/events/suspensions, e-sign
   envelopes, copilot, activities, settings, object customization, attribute definitions and
   values, employees.
8. **RBAC itself** — the employee/role/grant endpoints from task 05 → `RbacManage`.

### Controller convention

```php
public function vacate(Request $request, Contract $contract): JsonResponse
{
    $this->authorize('vacate', $contract);
    // … existing transaction unchanged …
}
```

For subject-less actions:

```php
Gate::authorize(Permission::BillingRunExecute->value);
```

**Authorize before validation, and before the transaction opens.** A 403 should not have
touched the database.

**Nested resources authorize the parent.** `POST /units/{unit}/holds` authorizes
`UnitHoldManage` against the *unit*, because that is what carries the site. Do not authorize
against the newly-created child, which does not exist yet.

### Actor resolution at write sites

Grep for `auth()->user()`, `$request->user()` and `Auth::id()` across `app/`. Any occurrence
on a path reachable from a command, job or webhook must become `Actor::current()`, or it
returns `null` headless and either crashes or writes a null causer where a system marker
belongs. Priority paths: billing run generation, autopay, delinquency ladder, access
reconciler, automation and playbook handlers, e-sign completion, comms delivery pipeline.

### `SiteAccess` deletion

- `Facility\SiteSenderIdentityController` → `CredentialManage` policy against the site.
- Delete `app/Support/Auth/SiteAccess.php`.
- `05-billing-ledger.md` §Credential handling rules and `06-communications.md`
  §Authorization gap: replace the "single choke point / one-file change" paragraphs with the
  real model. `07-people-and-auth.md` site-scoping section: describe grants.
- `10-open-decisions.md` Active WIP: delete the "Site credential authorization stopgap" line
  and the `canEdit` stopgap line (task 05 finishes the latter).

## API surface

No shape changes. Every authenticated endpoint may now return 403 with the task-02 shape.

One behavioural note to document: endpoints that previously returned 200 for any logged-in
employee may now 403 for narrowly-granted employees. Since the task-01 backfill gives every
pre-existing employee a company-wide grant, **no existing deployment sees a behaviour change
until an operator narrows someone's grants**. Say this in the release note.

## Panel surface

None in this task, but the panel will start receiving 403s once grants are narrowed. Task 05
handles presentation; if you narrow a test employee's grants while working here, expect
unhandled toasts and do not "fix" them by widening permissions.

## Invariants

- Invariant 20 — contract create writes charges in one transaction. Authorization happens
  *outside* and *before* it; never add an authorize call inside a transaction.
- Invariant 25 — automation-originated writes do not re-trigger automations, and (task 02)
  run as system actor. Rollout must not add employee authorization inside handler code.
- Invariant 11 — payment confirmation stays rail-specific. Webhook controllers remain
  public and system-actored; do not add employee gates to them.
- Advanced-filter convention — nothing here touches `FilterableFields`; task 04 does.

## Acceptance criteria

- [x] `RoutePermissions` covers every route in `routes/api.php`; no route is absent.
- [x] Every exemption carries a reason string and falls in the `self` or `reference`
      category.
- [x] Every authenticated controller action calls `authorize()` or `Gate::authorize()`
      before validation and before opening a transaction.
- [x] `app/Support/Auth/SiteAccess.php` is deleted; grep returns no references.
- [x] No `auth()->user()` / `Auth::id()` remains on any path reachable from a command, job
      or webhook.
- [x] An `owner` employee can exercise every endpoint the panel uses — full panel walk with
      no 403s.
- [x] A `leasing_agent` at site A gets 403 signing a contract on a site B unit.
- [x] An `accountant` gets 403 on `POST /contracts` and 200 on `POST /invoices/{id}/issue`.
- [x] Scheduler dry-run (`billing:run`, `contracts:activate`, `delinquency:run`,
      `access:sync`, `automations:run-scheduled`) completes with no employee authenticated.
- [x] Docs `05`, `06`, `07`, `10` corrected in this PR.
- [x] `php artisan test` green; every pre-existing test still passes (they authenticate as
      an employee who, post-backfill, holds `owner`).

## Tests required

| Test | Asserts |
|---|---|
| `PolicyRolloutTest::owner_reaches_every_endpoint` | Table-driven over the manifest; smoke, not deep |
| `PolicyRolloutTest::unpermitted_role_is_denied` | One denial per domain, 403 shape |
| `LeasingAgentScopeTest::cannot_sign_contract_at_other_site` | 403, no partial write |
| `LeasingAgentScopeTest::can_sign_contract_at_granted_site` | Positive path, contract + charges + occupancy intact |
| `AccountantScopeTest::can_issue_invoice_cannot_sign_contract` | Cross-domain separation |
| `CredentialPolicyTest::credential_manage_required_for_stripe_and_comms` | Replaces SiteAccess |
| `HeadlessWriteTest::scheduled_commands_run_without_employee` | Table over the scheduler list in `bootstrap/app.php` |
| `HeadlessWriteTest::webhooks_run_without_employee` | Stripe, e-sign, access, delivery |
| `AuthorizeBeforeTransactionTest::denied_request_writes_nothing` | DB unchanged on 403 |
