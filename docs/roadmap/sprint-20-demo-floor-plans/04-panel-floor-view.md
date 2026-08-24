# S20-04 — Panel: floor view

## Context

With tasks 01–03 done, every demo site has four floors of matching shapes. This task makes the
panel render them, coloured by real unit state.

**Audit before you build.** This task file is written against `unit-hq-api` only — the panel's
current `/leasing/unit-map` implementation was not reviewed. Start by establishing which of these
is true:

- **A.** The page already fetches `site_maps` and injects state → this task is the floor switcher,
  the legend, and the state/overlay split. Half a day.
- **B.** The page renders a grid of units with no SVG at all → this task is the SVG rendering
  path. A full day, and the acceptance criteria below are the specification.

Do not write the SVG fetch twice. If A, extend; if B, build.

## Scope

**In:**
- Floor switcher when a site has more than one map
- Inline SVG render with `data-status` stamped per shape at render time
- Overdue / overlock overlays, distinct from status
- Legend matching the Units list badge colours exactly
- Hover detail: unit number, state, tenant + contract link when occupied
- Empty state when a site has no map

**Out:**
- Uploading or editing maps from this page (Settings → Facility already owns upload)
- Drag-and-drop shape placement
- Click-to-reserve or any write action from the map
- Rendering a map for a site whose shapes do not match (show the mismatch count, do not attempt a
  partial render)

## Schema changes

None.

## Implementation notes

**Fetch one floor at a time.** `GET /api/sites/{site}/maps` without `with_svg` returns the floor
list cheaply — id, name, `sort_order`. Use it to build the switcher, then fetch
`GET /api/site-maps/{siteMap}` for the selected floor only. Four floors of 100 shapes is ~250 KB
of SVG; do not pull all of them to render one.

**Stamping state.** The SVG carries identity and geometry only — no colours are baked in
(deliberately: invariant 5). After injecting the markup, walk `[data-unit-number]` and set
`data-status` from the units payload the page already loads. Overlays are separate attributes so
they compose:

```ts
el.setAttribute('data-status', unit.state)              // UnitState
if (unit.is_overdue)  el.setAttribute('data-overdue', '1')
if (unit.is_overlocked) el.setAttribute('data-overlock', '1')
```

An overlocked unit is still occupied — `HoldGuard` exempts `overlock` from blocking availability.
Rendering it as "blocked" would misstate occupancy on the one screen operators use to eyeball it.

**Sanitize on the client too, or don't inject as HTML.** The API sanitizes on write, but the panel
should not treat any stored blob as trusted markup. Prefer parsing to a document and appending
nodes over `v-html` on a raw string.

**Shapes with no matching unit** (the `MAD-05` orphans from task 03) get a neutral hatched fill and
no tooltip. They must not throw, and they must not be counted in the legend totals.

**Conventions:** composable `useSiteMaps(siteId)` / `useSiteMap(siteMapId)` per the `useXxx` /
`useXxxList` rule; HTTP via `useApi()`; types in `app/types/`; arrays as `Array<T>`; every string
through i18n in `en.json`, `es.json`, `fr.json`. Reuse `UnitStateBadge`'s colour mapping — one
source of truth for state colour, shared by the list, the map and the legend.

## API surface

Existing, unchanged:

```
GET /api/sites/{site}/maps            → floor list (add ?with_svg=1 only if a floor list needs it)
GET /api/site-maps/{siteMap}          → one floor including svg_map
```

If the units payload the page uses does not already carry `state`, that is an API gap — raise it
rather than deriving state in the browser.

## Panel surface

`/leasing/unit-map`:

- Floor tabs or a select, ordered by `sort_order`, hidden when a site has one map
- The plan, scaled to the container via the SVG's `viewBox`
- Legend: Available · Occupied · Reserved · Maintenance · Damaged · Staff use · Blocked, plus the
  two overlays
- Hover on an occupied shape: unit number, tenant name, contract link, overdue flag
- Hover on a held shape: hold type and end date
- Empty state when the site has no map, pointing at Settings → Facility
- i18n namespace `units.map.*`

## Invariants

- All strings via i18n; `Array<T>` typing; `useApi()` for HTTP.
- Invariant 5 — the map never reads a stored status; it stamps derived state at render.
- `canEdit` stopgap (`10-open-decisions.md`) — this page is read-only, so do not extend it.

## Acceptance criteria

- [ ] A demo site shows four floors; switching floors re-renders without a full page reload.
- [ ] Only the selected floor's SVG is fetched (verify in the network panel).
- [ ] Every shape is coloured by its unit's real state; a unit taken out of service via the API
      changes colour on reload.
- [ ] An overlocked occupied unit renders as occupied with the overlock stroke, not as blocked.
- [ ] An overdue occupied unit is visually distinct from a current one.
- [ ] Legend colours match `UnitStateBadge` exactly — same mapping module, not a copy.
- [ ] `MAD-05` Planta 3 renders: 2 orphan shapes are inert and hatched, 3 uncovered units are
      absent from the plan and the page does not error.
- [ ] A site with no map shows the empty state, not a blank frame or a spinner.
- [ ] Hovering an occupied unit shows tenant and a working contract link.
- [ ] `bun run lint` and `bun run typecheck` pass; `en.json`, `es.json`, `fr.json` all carry every
      new key.

## Tests required

Per `01-stack.md` the panel has no test runner beyond lint and typecheck, so verification is manual
against a freshly seeded demo world. Record this script in the PR:

1. `php artisan demo:seed --fresh`, open `/leasing/unit-map` at Madrid Centro
2. All four floors render; each shows a mix of unit classes, not one class per floor
3. Counts per state in the legend reconcile with the Units list filter tabs for that site
4. Place a maintenance hold on a visible unit via the API → it changes colour on reload
5. Switch to Paris Sud, Third floor → orphan shapes inert, no console error
6. Switch to a site with the map deleted → empty state
7. Throttle the network: only one `svg_map` payload is fetched per floor change

If a component test harness lands later, the state-stamping function is the first thing to cover —
it is pure, and it is where the overlay/status distinction will regress.
