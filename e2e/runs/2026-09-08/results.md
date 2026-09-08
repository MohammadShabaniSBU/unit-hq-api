# E2E smoke — 2026-09-08

## Environment

| Name | Value |
|---|---|
| PANEL_URL | http://localhost:3000 |
| API_URL | http://127.0.0.1:8000 (`GET /up` → 200) |
| DB_DRIVER | sqlite |
| LOGIN_PASSWORD | password (post `demo:seed`) |

`.env` files were missing on this host. Fallback: API `.env.example` (`PANEL_URL`, `DB_CONNECTION=sqlite`) and panel `.env.example` (`NUXT_PUBLIC_API_BASE_URL`). Probes of both origins failed, so the API (`php artisan serve`) and panel (`bun run dev`) were started. Compact world: `php artisan demo:seed --fresh --compact` (MAD-01 + MAD-02, 10-day clock `2025-06-01` → `2025-06-10`). Panel language: English before S-01.

Host wall clock while driving the UI: **2026-09-08**. Demo civil dates remain June 2025.

Mutating-scenario resets used the SQLite file-copy recipe (`database/database.sqlite.snapshot`).

None skipped: every smoke scenario has `requires_db: any`.

## Summary

| Result | Count |
|---|---|
| pass | 2 |
| fail | 4 |
| skipped | 0 |

### S-01 — pass
- persona: readonly
- route: /login
- notes: Rita Lectura signed in; landed on `/leasing/contacts` (**Contacts**); **Signed in** toast. Direct URL `/settings/people` redirected to `/settings/general`. People item absent from the settings sidebar. General form showed the expected load error (`settings.manage` missing).

### S-02 — fail
- persona: agent-mad
- route: /leasing/contacts
- notes: Ana López (Madrid Centro). Lucía Ferrer is on the unfiltered **All 21** list (tenant) with a Closed won deal and an **Active** contract `MAD-01-SS3-01`. Marcos Vega contract **Active** (`MAD-01-SS6-01`). Site selector shows Madrid Centro only. Step 6 fails: Sofía Marín (`sofia.marin@demo.keevaris.test`, prospect) is on the same unfiltered contacts list. MAD-02 negative control is visible to the MAD-01 leasing agent.
- screenshot: e2e/runs/2026-09-08/S-02-step-6.png
- bug: e2e/runs/2026-09-08/bugs.md#s-02

### S-03 — fail
- persona: ops
- route: /billing/delinquency
- notes: Heading **Delinquency**. Filters tried: **8–14 days**, **1–7 days**, **All days**, All sites / Madrid Centro / Madrid Norte. Lucía Ferrer never appears. Board chip: EUR €80.00, 1 open. Only row: `MAD-01-SS3-03 · Ana Coloma`, 456 days overdue, €80.00. Compact seed left Lucía with **no** `delinquencies` row.
- screenshot: e2e/runs/2026-09-08/S-03-step-3.png
- bug: e2e/runs/2026-09-08/bugs.md#s-03

### S-04 — pass
- persona: ops
- route: /billing/runs
- notes: Reset from snapshot first. **Run billing now** modal: hint **Preview first — nothing is written until you confirm.** Preview listed contracts with 0 periods / 0.00. **Run for real** → toast **Billing run finished — 0 billed, 11 failed.** Landed on `/billing/runs/11` (**Billing run #11**): 0 billed, 1 skipped, 11 failed. Toast billed+failed matched the detail header. Empty/nothing-due seed-end is allowed; this still created a run.

### S-05 — fail
- persona: ops
- route: /inbox
- notes: Reset from snapshot first. Three-pane Inbox. WhatsApp chip. Pilar Santos thread found. Spanish lines present (template about her contract; inbound `¿pueden enviarme el enlace de pago?`). Composer instead shows **The 24-hour session is closed. Send an approved template to reach this contact.** — not an open-window affordance. Triage listed the documented strangers; discard of SMS `+34999888777` with reason `e2e-smoke` removed the row and toasted **Message discarded.** Scenario stopped on the closed-session assertion (step 4); discard was observed after that.
- screenshot: e2e/runs/2026-09-08/S-05-step-4.png
- bug: e2e/runs/2026-09-08/bugs.md#s-05

### S-06 — fail
- persona: ops
- route: /automations
- notes: **Default debt process** and **Default lead chase** both listed with **Playbook** badges (On; compiled from playbook #1 / #2). Opening `/automations/1` and `/automations/2` never leaves the spinner. Console: `TypeError: Cannot read properties of undefined (reading 'kind')` in `toVFNnode` (`useAutomationEditor.ts`). Banner, palette, Save, Open playbook, and Runs tab were not reachable.
- screenshot: e2e/runs/2026-09-08/S-06-step-3.png
- bug: e2e/runs/2026-09-08/bugs.md#s-06
