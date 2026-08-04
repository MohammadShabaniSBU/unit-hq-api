# S17-07 — Panel auth gate & login surface

## Context

Task 00 closed the API perimeter. The panel never got its half: an unauthenticated visitor
still loads the SPA, renders the shell — sidebar, site selector, page chrome — and then
collects 401s in the background. That is a bad look and a real leak, because panel chrome
carries information (site names in the selector, nav structure, the operator's branding)
before a single API call resolves.

This task makes the panel refuse to render for an unauthenticated visitor, and rebuilds the
login page, which is currently the least considered screen in the product and the first one
every operator sees each morning.

One trap, and it is exactly the one task 00 hit from the other side: **the panel has public
routes too.** The public offer preview and the public payment page are reachable by tenants
who will never have an account. A global guard written carelessly redirects a paying tenant
to a staff login. Enumerate them before writing the middleware, not after a client reports it.

## Scope

**In:**
- Global route middleware; unauthenticated → `/login`
- Public panel route allowlist
- Session resolution at boot with no flash of panel chrome
- 401 interceptor in `useApi()`
- Logout that clears every store, not just auth
- Login page redesign
- Login rate limiting (API side — small, belongs with this surface)

**Out:**
- Password reset / forgot-password (no mail template exists for it; if wanted, own sprint item)
- SSO, 2FA, remember-me
- Idle timeout (noted under Invariants as a deliberate gap)

## Schema changes

None.

## Implementation notes

### The guard

`app/middleware/auth.global.ts`. Reads the Pinia auth store, not the token directly — a
token that exists but has been revoked server-side must still redirect.

```ts
const PUBLIC_ROUTES = ['/login', '/invite', '/offers/preview', '/pay']
```

Match by prefix, and **derive this list by grepping the panel's page tree before writing
it** — `01-stack.md` names the public offer preview, S06 added the public pay page, and task
08 adds `/invite/:token`. A public page that lands in the authenticated bucket breaks a
tenant flow silently, because the tenant just sees a login form and assumes the link is dead.

Redirect carries the intended path: `/login?redirect=/billing/invoices/482`. On success,
**validate before navigating** — accept only a same-origin relative path beginning with a
single `/`. A `redirect` parameter taken on trust is an open redirect, and this one is
reachable pre-auth.

### No flash of chrome

The failure mode to avoid: shell paints, then the guard fires, then the login page replaces
it. In an SPA with `ssr: false` the store is empty on first paint, so the guard must not run
against an unresolved store.

Add an `initialised` flag to the auth store and a boot plugin that resolves the session
(token present → `GET /api/user`) before the first route resolves. Until `initialised`,
`app.vue` renders a minimal splash — wordmark centred on the page background, nothing else.
No spinner under ~300ms; a flashed spinner reads worse than a still frame.

### Token handling

Keep the existing Sanctum token; do not rewrite auth to cookie sessions in this task. Store
it where `useApi()` already reads it, and be explicit in the PR about which storage that is —
`localStorage` and a JS-readable cookie have the same XSS exposure, so the choice is about
tab behaviour, not security theatre.

`useApi()` gains a 401 branch: clear the auth store, clear the other stores, redirect to
`/login?redirect=<current path>`. Guard against loops — a 401 from the login request itself
renders a field error, it does not redirect.

### Logout clears everything

This runs on a front-desk machine that several staff share. Logout calls the API, then
**resets every Pinia store**, not just auth — site selector, inbox, any cached lists. A
`$reset` sweep over registered stores is fine; leaving the next person a warm cache of the
previous person's inbox is not.

### Login rate limiting (API)

`POST /api/login` currently has no throttle. Add `throttle:5,1` keyed on email plus IP, and
return 429 with a machine key the panel translates. Unthrottled login on a
publicly-reachable API is credential stuffing waiting to happen, and it is a one-line fix
that belongs with this surface rather than a future sprint.

### The login page

Split layout. Brand panel left at ~42% on `≥1024px`, form right. Below `768px` the panel
collapses to a slim header band carrying only the wordmark, and the form fills the viewport.

**Brand panel** — sidebar navy (`#1E2C55`, the existing sidebar colour, so the login and the
app read as one product). Contents, top to bottom: wordmark with the cube mark; the
signature; two lines of orienting copy at the base.

**Signature — the unit grid.** A 6×3 grid of small rounded rects at `rgba(255,255,255,0.10)`,
three cells at `0.22`, and exactly one cell rendered as an outlined empty square containing an
open-padlock glyph. It is an abstraction of the unit map — the product's most characteristic
artifact — and it says "facility" without a stock photo of a roller door.

This is the one place motion is allowed: the outlined cell's border draws in over ~500ms on
load, once. Wrapped in `@media (prefers-reduced-motion: reduce)` to render statically. No
staggered fade-up on form fields; that is the tell that a template was used.

**Form column** — page background, form at `max-width: 360px`, vertically centred.

| Element | Copy |
|---|---|
| Heading | Sign in |
| Sub | Use your work email address. |
| Fields | Email · Password (with show/hide toggle) |
| Button | Sign in |
| Footer | Need access? Ask your manager. |

No sign-up link — the install is mono-tenant and accounts are created by an owner (task 08).
The footer line replaces it with the answer to the question a sign-up link would raise.

**Error copy.** `Your email or password is incorrect.` — one message for both cases, never
naming which, so the form is not an account-existence oracle. Rate limit gets its own:
`Too many attempts. Try again in a minute.` Neither apologises, both say what to do.

**Quality floor.** `autofocus` on email; `autocomplete="email"` and `="current-password"` so
password managers work; Enter submits; the button shows an in-place spinner and stays
disabled only while the request is in flight; visible keyboard focus rings; the locale
switcher and theme toggle from the app header both present, because an operator who needs
Spanish needs it *before* logging in.

## API surface

- `POST /api/login` — gains throttling; 429 with a translatable key.
- Optional, decide before building: the brand panel's second line shows the operator's name
  ("Camden Lock Self Storage"). `GET /api/settings/general` is authenticated as of task 00,
  so this needs either a minimal public `GET /api/branding` returning display name only —
  an allowlist entry justifiable as pre-auth display data with no business content — or the
  line is dropped. **Do not reopen `/settings/general` to make this work.**

## Panel surface

`pages/login.vue` rebuilt; `middleware/auth.global.ts`, boot plugin, splash state in
`app.vue`, `useApi()` 401 branch, logout store sweep.

All strings through i18n in `en` / `es` / `fr`. Namespace `auth.*`.

## Invariants

- All UI strings via i18n; `Array<T>`; HTTP via `useApi()`; SPA only.
- Invariant 6 — public panel routes are token-addressed (offer token, pay token, invite
  token). The allowlist contains only those.
- Panel guarding is UX, not authorization — every route it hides is already denied by the
  API (tasks 00 and 03). Do not let a guard substitute for a policy.
- **Deliberate gap:** no idle timeout. Shared front-desk machines rely on staff locking the
  workstation. Record in `10-open-decisions.md` as undecided rather than leaving it implicit.

## Acceptance criteria

- [ ] Visiting any authenticated panel route while logged out lands on `/login` with no
      flash of sidebar or page chrome.
- [ ] The public offer preview, the public pay page and `/invite/:token` all load while
      logged out.
- [ ] `?redirect=` returns the user to the intended page after login.
- [ ] A `redirect` value pointing off-origin or to a protocol-relative URL is rejected.
- [ ] A revoked token redirects on the next navigation rather than rendering the shell.
- [ ] A 401 mid-session redirects to login with the current path preserved; a 401 on the
      login request itself renders a field error and does not redirect.
- [ ] Logout clears every Pinia store — verified by logging in as a second employee on the
      same tab and seeing no prior data.
- [ ] Six failed logins in a minute return 429 with translated copy.
- [ ] Login renders correctly at 1440px, 1024px, 768px and 375px.
- [ ] Password managers fill both fields; Enter submits; focus is visible throughout.
- [ ] `prefers-reduced-motion: reduce` renders the signature statically.
- [ ] `en.json`, `es.json`, `fr.json` carry every `auth.*` key.
- [ ] `bun run lint` and `bun run typecheck` pass.

## Tests required

API side:

| Test | Asserts |
|---|---|
| `LoginThrottleTest::sixth_attempt_is_throttled` | 429, translatable key |
| `LoginThrottleTest::throttle_keyed_per_email_and_ip` | One user's failures don't lock another out |
| `BrandingEndpointTest::exposes_display_name_only` | If built — no business content leaks pre-auth |

Panel has no test runner beyond lint/typecheck. Manual script for the PR description:

1. Log out, visit `/billing/invoices` → login page, no chrome flash, URL carries the redirect
2. Log in → land on `/billing/invoices`
3. Hand-edit the URL to `?redirect=https://example.com` → login lands on the default route
4. Revoke the token server-side, navigate → redirected, no shell render
5. Open a public offer link logged out → renders
6. Open the public pay page logged out → renders
7. Log in as employee A, log out, log in as employee B → no trace of A's site selection or inbox
8. Six wrong passwords → throttle message in `es`
9. Login page at 375px → brand panel becomes the header band, form usable
10. macOS reduce-motion enabled → no draw-in animation
