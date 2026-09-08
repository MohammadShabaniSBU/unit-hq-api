---
id: S-01
title: A read-only employee can sign in and is redirected away from People
domain: _smoke
route: /login
persona: readonly
mutates: false
priority: smoke
requires_db: any
depends_on: []
anchors: []
invariants: []
---

## Preconditions

Demo world seeded. `readonly` exists (`readonly@example.com` / `LOGIN_PASSWORD`).

## Steps

1. Open `/login` while signed out. The heading is **Sign in**. Fields are labelled **Email** and **Password**. The submit button is **Sign in**.
2. Enter `readonly@example.com` and `LOGIN_PASSWORD`. Submit.
3. Wait for the signed-in shell. The default landing is `/leasing/contacts` (heading **Contacts**).
4. Navigate directly to `/settings/people` (paste the path; do not rely on the sidebar).

## Expected

- Step 2 succeeds. A **Signed in** toast may appear. No "Your email or password is incorrect." alert.
- Step 3 shows the contacts list, not an error page.
- Step 4 does **not** stay on People. The panel redirects to `/settings/general`. The employee table is not shown.
- A 403 / load error on the General form is acceptable here: `GET /api/settings/general` needs `settings.manage`, which `readonly` does not have. The assertion is the redirect away from People, not a successful settings save.
- The People item is absent from the settings sidebar for this persona (`rbac.manage`).

## Known traps

- Direct URL is the assertion. A missing nav item alone is not enough.
- `readonly` is Rita Lectura. Do not log in as `manager`.

## On failure

Screenshot `/login` (if sign-in failed) or the page after the `/settings/people` navigation (URL bar + heading). Record the step number in `e2e/runs/<date>/`. Do not create a new employee. Do not fix the bug — report it in `e2e/runs/<date>/bugs.md`.
