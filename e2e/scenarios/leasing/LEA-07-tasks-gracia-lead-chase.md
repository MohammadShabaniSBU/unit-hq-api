---
id: LEA-07
title: The tasks page loads and Gracia Lin has a lead-chase task
domain: leasing
route: /leasing/tasks
persona: ops
mutates: false
priority: core
requires_db: any
depends_on: []
anchors: [grace]
invariants: []
---

## Preconditions

Compact demo world. Gracia Lin is enrolled in **Default lead chase**. The seeded sequence creates a task as one of the early steps.

## Steps

1. Log in as `ops`. Go to `/leasing/tasks`. Heading **Tasks**.
2. Confirm the list (or empty-state chrome with filters) appears. Toggle **Board** if a view switcher is present, then return to **List**.
3. Search or scan for **Gracia Lin**.
4. Open the task if a row is present. Do not complete, reassign, or create a task.

## Expected

- The page heading and filters render. A total load failure / blank error is a fail.
- A lead-chase task for **Gracia Lin** is present. A missing Gracia task is a fail (enrolment ran in the seed).
- Do not create a task to "make the step work."

## Known traps

- Type **Gracia Lin**, not `GraceLin`.
- Do not open `/automations/:id` to invent a run. Playbook enrolments belong to PLB-02.

## On failure

Screenshot the tasks heading/filters and the search result. Do not create a task. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
