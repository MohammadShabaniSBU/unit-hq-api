---
id: PLB-02
title: Default lead chase is listed and Gracia Lin enrolment is visible if shown
domain: playbooks
route: /playbooks/lead-chase
persona: ops
mutates: false
priority: core
requires_db: any
depends_on: []
anchors: [grace]
invariants: []
---

## Preconditions

Compact demo world. **Default lead chase** is active. Gracia Lin is enrolled and the run is still in flight.

## Steps

1. Log in as `ops`. Go to `/playbooks/lead-chase`. Heading **Lead chase**.
2. Find **Default lead chase**. Confirm **Active** and a non-zero **Steps** count.
3. Open the row. Stay on **Builder** long enough to see steps, then open **Enrolments**.
4. If the enrolments table lists contacts, find **Gracia Lin**. If the UI has no enrolment rows / the tab is empty, record **no enrolment list** and still pass the list + builder half — do not invent an enrolment.
5. Do not create, archive, or send from a step.

## Expected

- **Default lead chase** is listed and opens.
- Builder steps are visible.
- Gracia Lin on Enrolments is a pass when the UI shows enrolments. An empty enrolments table is not a fail by itself if the playbook row and builder loaded.
- Do not open `/automations/:id`.

## Known traps

- Type **Gracia Lin**. Do not send the next chase step.
- Quiet-deal note copy on the list page is decoration, not a fail.

## On failure

Screenshot the lead-chase list and the builder or enrolments tab. Do not send. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
