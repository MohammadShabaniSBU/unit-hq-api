# S01-04 — Panel: unit state surfacing

> **Revision 2.** Amended for D1/D4: the map tooltip renders money, so it inherits the currency
> rule from `00b`. The `fr` fallback hedge is removed — `fr.json` exists from task 00.

## Context

The Units list currently shows a `STATUS` column reading "Enabled" for every row — which
tells an operator nothing. With `unit_occupancies` and `unit_holds` in place, the panel can
show the state an operator actually cares about, and let them take a unit out of service.

## Scope

**In:**
- Real state badge on the Units list, replacing "Enabled"
- State filter on the Units list
- Unit map colouring by state
- Unit detail: occupancy history + hold management
- Place/release maintenance hold drawer

**Out:**
- Vacate and transfer actions (S02)
- Occupancy analytics pages (S17)
- Drag-and-drop map editing

## Panel surface

### Units list (`/facility/units`)

Replace the `STATUS` column with a state badge driven by `unit.state`:

| State | Badge | Colour intent |
|---|---|---|
| `available` | Available | success |
| `occupied` | Occupied | neutral / info |
| `reserved` | Reserved | warning |
| `maintenance` | Maintenance | warning |
| `damaged` | Damaged | error |
| `staff_use` | Staff use | neutral |
| `other` | Blocked | neutral |

Add a tab/filter row above the table mirroring the Contacts page pattern
(`All · Available · Occupied · Reserved · Out of service`), with counts. "Out of service"
groups `maintenance | damaged | staff_use | other`.

Occupied rows show the tenant name and contract link in a secondary line under the unit
number — an operator scanning the list needs to know *who* without clicking through.

### Unit map (`/leasing/unit-map`)

Colour cells by the same state enum. Add a legend. Hovering an occupied unit shows tenant
name, contract start, and balance-owed status; hovering a held unit shows the hold type and
its end date.

**The tooltip renders money**, so it is covered by invariant 31: the balance comes from the map
payload with its currency, and renders through the shared formatter. Extend the bulk endpoint
with `state`, the tenant name, and `{ amount, currency }` for the balance rather than fetching
per cell. If the currency is missing from the payload, show an em dash — do not guess from
locale.

The Units list is a cross-site view ("All sites" in the current screenshot), so the same map or
list can legitimately show £ and € rows side by side. That is correct behaviour, not a bug to
normalise. Do not add a "display currency" toggle; there is no conversion anywhere in this
system.

### Unit detail

Two new cards:

**Current state** — badge, plus the active occupancy (tenant, contract link, since date) or
the active hold (type, reason, dates, who created it).

**History** — chronological list of past occupancies and released holds. Read-only. This is
the operator's answer to "who was in here last year?" and later feeds damage disputes.

**Actions** — "Take out of service" opens a drawer:

- Hold type (maintenance / damaged / staff use / other)
- Start date (defaults to **the site's today**, supplied by the API or computed from the site's
  timezone — not the browser's)
- End date (optional, with helper text: leave empty for indefinite)
- Reason (required — free text)

"Return to service" on an active non-reservation hold calls the release endpoint with a
confirm dialog.

Both actions are hidden when the unit is occupied, with an explanatory tooltip — you cannot
take an occupied unit out of service; that requires ending the contract first (S02).

Dates displayed here are civil dates at the facility. Format them from the `YYYY-MM-DD` string
directly; do not parse into a `Date` and re-format, because that reintroduces the browser's
timezone and will shift a date by a day for anyone west of the facility.

## Implementation notes

- New composable `useUnitHolds(unitId)` following the `useXxx` / `useXxxList` convention.
- Types in `app/types/unit.ts`: `UnitState` union, `UnitOccupancy`, `UnitHold`. Arrays as
  `Array<T>`.
- State badge as a shared component `UnitStateBadge.vue` — used by the list, the map tooltip
  and the detail card. One source of colour mapping.
- The map is likely already fetching a bulk endpoint; extend that payload with `state` rather
  than fetching per cell.
- Money via the shared formatter from `00b`. No `Intl.NumberFormat` constructed locally, no
  symbol literals, no `toFixed(2)` with a symbol concatenated.

## i18n

All new strings in `locales/en.json`, `es.json`, and `fr.json`. Key namespace `units.state.*`,
`units.holds.*`. `fr.json` exists from task 00 D4; English text in the `fr` values is acceptable
this sprint, a missing key is not.

Spanish matters most — first deploy. Suggested terms: `available` → *Disponible*,
`occupied` → *Ocupada*, `reserved` → *Reservada*, `maintenance` → *Mantenimiento*,
`damaged` → *Dañada*, `staff_use` → *Uso interno*, "Take out of service" → *Dar de baja
temporal*. Have the client's operator review these — storage vocabulary is regional.

No currency symbols in any locale file.

## Invariants

- All strings via i18n; no hardcoded text.
- `Array<T>` typing.
- HTTP via `useApi()`.
- Invariant 31 — money renders through the shared formatter with the currency from the record;
  no symbol literals, including in translation strings.
- **Note the `canEdit` stopgap** (`10-open-decisions.md`): hold actions currently ignore
  role and site scope. Do not extend the stopgap further — gate the new actions behind the
  same existing helper so there is exactly one place to fix in S17.

## Acceptance criteria

- [ ] Units list shows real state; the "Enabled" placeholder is gone.
- [ ] State filter tabs work with counts and combine with existing search.
- [ ] Occupied rows show tenant name linking to the contract.
- [ ] Unit map is coloured by state with a visible legend.
- [ ] Unit detail shows current state and full history.
- [ ] The History card renders closed occupancies and released holds, newest first, and shows
      an empty state on a unit that has never been occupied.
- [ ] An operator can place a maintenance hold and the unit immediately leaves availability
      (verified by attempting a reservation on it).
- [ ] Releasing the hold returns it to availability.
- [ ] Out-of-service actions are unavailable on occupied units, with an explanation.
- [ ] The map tooltip balance renders with the currency from the payload; the seeded GBP unit
      shows £ and a EUR unit shows € **in the same cross-site view**.
- [ ] No date is parsed into a JS `Date` and re-formatted for display.
- [ ] `bun run lint` and `bun run typecheck` pass.
- [ ] `en.json`, `es.json`, `fr.json` all contain every new key, and none contains a currency
      symbol.

## Tests required

Panel has no test runner beyond lint/typecheck per `01-stack.md`, so verification is manual
plus API-side coverage. Task 03's seeder prints a summary of state counts, per-site currency,
and the specific unit numbers used for each edge case — **use those unit numbers rather than
creating test data by hand.** Every state and edge case below already exists after
`php artisan migrate:fresh --seed`.

Record this script in the PR description:

1. Every one of the seven badge states is visible on the Units list without filtering
2. Filter tabs → counts sum to the unfiltered total
3. The seeded unit with a **closed occupancy** shows as Available, with its past tenancy in
   the History card
4. The seeded unit with **two sequential closed occupancies** lists both, newest first
5. The seeded unit with an **expired reservation hold** shows as Available, not Reserved
6. The seeded **overlocked occupied unit** shows as Occupied, not blocked twice
7. Place a maintenance hold on a seeded available unit → it disappears from `?available=1`
   and from auto-assign in the New Reservation drawer
8. Release that hold → the unit returns to availability, and the released hold appears in
   History
9. An occupied unit hides the out-of-service action, with the tooltip explaining why
10. Map legend colours match the list badge colours exactly
11. **The seeded GBP unit's tooltip shows £ and a EUR unit's shows € in the same "All sites"
    view**, and the contract page for that GBP unit agrees with the Rates matrix
12. Switch the panel to `fr` — no key renders as a raw dotted path, and money symbols do not
    change

If a component test setup is added later, `UnitStateBadge` is the first candidate.
