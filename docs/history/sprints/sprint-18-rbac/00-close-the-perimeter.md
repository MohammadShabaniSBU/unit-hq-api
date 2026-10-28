# S17-00 — Close the perimeter (hotfix)

## Context

`routes/api.php` opens `Route::middleware('auth:sanctum')->group(function (): void {` at
line 12 and closes it at line 113. Lines 114–373 are outside. `bootstrap/app.php` appends
only `AssignRequestId` to the global stack; no controller declares constructor middleware or
implements `HasMiddleware`. Nothing else re-applies auth.

Of 297 route declarations, **79 are authenticated and 218 are not.** Publicly reachable
today, verified by reading the file:

| Exposed | Effect |
|---|---|
| `apiResource('contracts')`, `('invoices')`, `('payments')` | Full read of every tenancy, invoice and payment; write access to contract mutation endpoints |
| `apiResource('units')`, `units/{unit}/holds` | Full inventory read; unit state mutation |
| `PATCH /settings/general`, `/billing`, `/leasing`, `/activity-log` | Anonymous rewrite of org billing cadence, anchor, proration, deposit, retention |
| `tax-rates` `POST` / `PATCH` / `{id}/default` | Anonymous creation of tax versions on a fiscal catalogue |
| `discounts`, `insurances`, `insurance-rate-matrix` | Catalogue read/write |
| `automations`, `playbooks`, `template-families`, `whatsapp-templates` | Read and mutate automation graphs and message templates |
| `GET /activities` | Full audit log, including `properties` |

This is not an RBAC gap; it is an unauthenticated data breach waiting for a reachable
deployment. It ships **before** the rest of the sprint, on its own branch, as its own PR.

The fix is small. The care is entirely in not accidentally authenticating the routes that
are *supposed* to be public — offer token links, the public pay page, inbound webhooks, the
unsubscribe floor, the Stripe publishable key, template assets — because breaking those
silently breaks tenant-facing flows and inbound provider callbacks.

## Scope

**In:**
- Restructure `routes/api.php` so authentication is the default and public is the exception
- An explicit, commented public allowlist
- `RouteAuthCoverageTest` — fails on any route that is neither authenticated nor allowlisted
- Verify the currently-public business routes still work when authenticated (panel smoke)

**Out:**
- Any permission checking (task 03)
- Any site scoping (task 04)
- Panel changes — the panel already sends the Sanctum token on every request via `useApi()`;
  confirm this, and if any call path omits it, fix that call path here

## Schema changes

None.

## Implementation notes

**Structure the file so the failure mode is safe.** Do not simply move the closing brace to
the end — a future route appended after the brace repeats the bug. Invert the default:

```php
// routes/api.php

// ---------------------------------------------------------------------------
// PUBLIC — every route here is deliberately unauthenticated. Adding one
// requires a comment naming what authenticates it instead (token, signature,
// HMAC). If it has no such answer, it does not belong in this block.
// ---------------------------------------------------------------------------
Route::post('login', [Controllers\EmployeeAuthController::class, 'login']);

// Inbound webhooks — provider signature / per-account URL token.
Route::post('webhooks/stripe/{accountToken}', Webhooks\StripeWebhookController::class);
// … existing webhook routes unchanged …

// Offer links, pay page, unsubscribe floor, publishable key, template assets
// — crypto-random or content-hash tokens, per invariant 6.
// … existing public routes unchanged …

// ---------------------------------------------------------------------------
// AUTHENTICATED — everything else. No route may be added below without being
// inside this group. RouteAuthCoverageTest enforces it.
// ---------------------------------------------------------------------------
Route::middleware('auth:sanctum')->group(function (): void {
    // … all 79 currently-grouped routes, plus all 218 currently outside …
});
```

**Enumerate the real public set from the router, not from memory.** Before moving anything,
dump `php artisan route:list --json` on the current `dev` and save it as a fixture. After the
change, dump again and diff: the only routes whose middleware changed must be ones you
intended. Any public route that accidentally became authenticated shows up in that diff, and
those are the ones that break tenant flows silently.

The public set as it exists today (confirm against the dump, do not trust this list alone):

- `POST login`
- `POST webhooks/stripe/{accountToken}`, `webhooks/esign/{webhookToken}`,
  `webhooks/access/{webhookToken}`, `webhooks/{provider}/{webhookUrlToken}`,
  `webhooks/{provider}/{webhookUrlToken}/inbound`
- `GET|POST comms/unsubscribe/{token}`
- `GET public/template-assets/{hash}/{filename}`
- the public offer route (`offers/token/{token}`) and the public payment surface
  (`PublicPaymentController`) — locate both in the file and keep them public
- the Stripe publishable-key endpoint (`05-billing-ledger.md` §Connect step 4) — public by
  documented decision, it is not a secret

**`GET /activities` deserves a second look.** It is currently public and returns
`properties`, which per `08-activity-logging.md` carries money strings and identifiers, and
whose tier-2 filtering already assumes a caller identity (`include_disabled=1` is gated on
the caller being a `User`). Authenticate it here; the permission gate lands in task 03.

**Do not fix authorization in this task.** After this task, every authenticated employee can
still do everything. That is the pre-existing state and it is fine — the point of this PR is
that "everyone" now means "someone who logged in", which is a change you can reason about
and revert independently of the rest of the sprint.

## API surface

No shape changes. 218 route declarations move from anonymous to `auth:sanctum`, returning
401 for unauthenticated callers instead of 200.

Confirm the 401 path renders as JSON: `bootstrap/app.php` already sets
`redirectGuestsTo(fn () => null)` for `api/*` with a comment explaining that `route('login')`
does not exist. Add a test for it — a 401 on one of the newly-protected routes must be JSON,
not a `RouteNotFoundException` 500.

## Panel surface

None expected. Verify: grep `unit-hq-panel` for any `$fetch` / raw fetch that bypasses
`useApi()` and would therefore omit the bearer token. Any hit becomes a `useApi()` call in
this PR. Then walk the panel with the browser network tab and confirm no request 401s —
Settings pages, Rates matrix, Unit class matrix, Discounts, Tax rates, Automations,
Playbooks and Template builder are the surfaces most likely to have been leaning on the open
routes.

## Invariants

- Invariant 6 — public links use crypto-random tokens, never PKs. The allowlist must contain
  only token- or signature-authenticated routes; a route that is public because "the panel
  needs it" is a defect, not an allowlist entry.
- `06-communications.md` / `05-billing-ledger.md` — inbound webhook routes are authenticated
  by per-account URL token and provider signature. They stay outside Sanctum, unchanged.
- New invariant (next free number — the file is at 34; confirm before appending):

> **Every API route is authenticated unless it appears in the public allowlist in
> `routes/api.php` with a comment naming what authenticates it instead.** Enforced by
> `RouteAuthCoverageTest`, which fails on any route that is neither inside the authenticated
> group nor on the allowlist.

## Acceptance criteria

- [ ] `php artisan route:list` shows `auth:sanctum` on every route except the documented
      public allowlist.
- [ ] The before/after `route:list --json` diff is attached to the PR description, and every
      middleware change in it is intended.
- [ ] `GET /api/contracts`, `/api/invoices`, `/api/payments`, `/api/units`,
      `PATCH /api/settings/billing`, `POST /api/tax-rates` and `GET /api/activities` all
      return 401 without a token.
- [ ] All five webhook routes, the unsubscribe route, the public template-asset route, the
      public offer route, the public payment surface and the publishable-key route still
      return their previous status codes without a token.
- [ ] A 401 on an API route renders JSON, not a 500.
- [ ] The panel operates with no 401s across every page in the surface map in `01-stack.md`.
- [ ] `RouteAuthCoverageTest` fails when a route is added outside both the group and the
      allowlist (prove it by adding one temporarily in the test).
- [ ] `php artisan test` green.

## Tests required

| Test | Asserts |
|---|---|
| `RouteAuthCoverageTest::every_route_is_authenticated_or_allowlisted` | Enumerates the router; fails listing offenders by name |
| `RouteAuthCoverageTest::allowlist_entries_still_exist` | An allowlisted route that was deleted fails the test, so the list cannot rot |
| `PerimeterTest::business_routes_require_authentication` | Table-driven over contracts / invoices / payments / units / settings / tax-rates / activities → 401 |
| `PerimeterTest::public_routes_remain_public` | Table-driven over the allowlist → not 401 |
| `PerimeterTest::unauthenticated_api_response_is_json` | 401 body is JSON, no `RouteNotFoundException` |
