# Scenario template

Copy this file into `scenarios/<domain>/`. Do not edit the original.

Routes are always relative (`/billing/runs`). The agent prepends `PANEL_URL`. Never write an origin, port, or hostname in a scenario.

```markdown
---
id: DOM-01
title: One sentence that states the expected outcome
domain: leasing | billing | facility | inbox | automations | playbooks | insights | settings | marketing | public | _smoke
route: /leasing/contacts
persona: ops
mutates: false
priority: smoke | core | edge
requires_db: any
depends_on: []
anchors: [lucia, MAD-01]
invariants: []
---

## Preconditions

Demo world seeded. Named anchors exist in their documented end state
(see `fixtures.md`).

## Steps

1. Log in as the persona named in frontmatter (`personas.md`).
2. Go to the `route`.
3. …

## Expected

- What must be visible, in order when order matters.
- Figures that must agree across two surfaces (derived values, not caches).

## Known traps

- Display-name vs class-name, accents, unit-class labels — see `fixtures.md`.

## On failure

Screenshot the failing surface. Record `id`, `route`, `persona`, and the
step number in `e2e/runs/<date>/results.md`. If it is a product bug,
append a report to `e2e/runs/<date>/bugs.md`. Do not fix the bug.
Do not edit application code. Do not invent a fixture.
```

## Frontmatter

| Field | Rules |
|---|---|
| `id` | Unique. Smoke uses `S-NN`. Later domains use a short prefix (`LEA-`, `BIL-`, `INB-`, …). |
| `title` | States the outcome, not the activity. |
| `domain` | One of the values listed above. |
| `route` | Relative path of the primary surface. |
| `persona` | Key from `personas.md` (`ops`, `readonly`, `agent-mad`, …). |
| `mutates` | `true` if the steps write anything (ledger, triage resolve, send). Needs a reset beforehand. |
| `priority` | `smoke` for the first-pass set. `core` / `edge` for later coverage. |
| `requires_db` | `any` (default) or `pgsql`. Skip — do not pass — when the resolved driver does not match. |
| `depends_on` | Other scenario ids that must have passed first. Usually `[]`. |
| `anchors` | Cast handles and site codes this scenario touches. Two mutating scenarios that share an anchor collide. |
| `invariants` | Numbers from `docs/09-conventions-and-invariants.md`. Empty is allowed on a pure UI-wiring check. |
