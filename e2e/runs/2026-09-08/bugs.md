# Product bugs — 2026-09-08 smoke

### S-02
- route: /leasing/contacts
- persona: agent-mad
- step: 6
- what happened: Ana López (leasing agent, Madrid Centro / MAD-01) sees Sofía Marín on the unfiltered Contacts list (**All 21**). The row is a prospect with email `sofia.marin@demo.keevaris.test`. Search that does not use the accented display name returns **No data**; the name is still present when the search box is empty.
- what was expected: Sofía Marín is MAD-02 cast. A MAD-01 leasing agent must not see her. Step 6 (search for Sofía Marín) should return no contact with that name. Site-scoped records on other sites are blocked for this persona.
- screenshot: e2e/runs/2026-09-08/S-02-step-6.png

### S-03
- route: /billing/delinquency
- persona: ops
- step: 3
- what happened: After `demo:seed --fresh --compact`, the Delinquency board (Open, All days, All sites) shows one open case: `MAD-01-SS3-03 · Ana Coloma`, 456 days overdue, €80.00. Lucía Ferrer is not on **8–14 days**, **1–7 days**, or **All days**. The compact database has no `delinquencies` row for Lucía (contact id 2). Board chip: 1 open / EUR €80.00.
- what was expected: Lucía Ferrer has an **open** compact case, ladder started (late fee and/or notice), typically in **8–14 days**, still owing. A missing row is a fail.
- screenshot: e2e/runs/2026-09-08/S-03-step-3.png

### S-05
- route: /inbox
- persona: ops
- step: 4
- what happened: Pilar Santos's WhatsApp thread is present (`/inbox?channel=whatsapp&thread=49`). Conversation includes the outbound template ("Hola Pilar, le escribimos desde Keevaris sobre su contrato.") and her inbound `¿pueden enviarme el enlace de pago?`. The composer is blocked with **The 24-hour session is closed. Send an approved template to reach this contact.** Message dates are 10/06/2025; the host clock is 2026-09-08.
- what was expected: An open-window / in-session affordance on that thread. Fixtures: WhatsApp session window open at compact seed-end.
- screenshot: e2e/runs/2026-09-08/S-05-step-4.png

### S-06
- route: /automations/:id
- persona: ops
- step: 3
- what happened: The automations table lists **Default debt process** and **Default lead chase**, each with a **Playbook** badge. Opening either (`/automations/1`, `/automations/2`) stays on a spinner. DevTools console: `Uncaught (in promise) TypeError: Cannot read properties of undefined (reading 'kind')` during `toVFNnode` in `useAutomationEditor.ts` (watcher on the editor page). The editor banner, missing palette, Save, Open playbook, and Runs tab never appear.
- what was expected: The compiled playbook opens at `/automations/:id` with the read-only banner **This graph is compiled from a playbook and is read-only here. Edit it on the playbook page.** No node palette; Save disabled or absent. Runs list at `/automations/:id/runs` loads (empty runs still pass the editor half).
- screenshot: e2e/runs/2026-09-08/S-06-step-3.png
