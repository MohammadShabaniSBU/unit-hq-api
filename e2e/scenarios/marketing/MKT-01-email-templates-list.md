---
id: MKT-01
title: The email templates list loads
domain: marketing
route: /marketing/templates/email
persona: ops
mutates: false
priority: core
requires_db: any
depends_on: []
anchors: []
invariants: []
---

## Preconditions

Compact demo world. Email templates may be empty or seeded. This scenario only asserts the list page loads.

## Steps

1. Log in as `ops`. Go to `/marketing/templates/email`. Heading **Email**.
2. Confirm the list chrome (search, New template) and either template rows or the empty copy **No email templates yet. Create your first one.**
3. Do not click **New template**. Do not open the builder. Do not send.

## Expected

- The page heading **Email** is visible and the list or empty state renders.
- A 500 / spinner-forever is a fail.
- Creating, editing, or sending a template is out of scope.

## Known traps

- Do not visit WhatsApp / SMS template send flows.
- An empty list is a pass.

## On failure

Screenshot the email templates page. Do not create or send. Do not fix the bug — report it in `e2e/runs/<YYYY-MM-DD-HHmmss>/bugs.md`.
