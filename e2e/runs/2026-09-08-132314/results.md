# E2E smoke + core — 2026-09-08-132314

## Environment

| Name | Value |
|---|---|
| PANEL_URL | http://localhost:3000 |
| API_URL | http://127.0.0.1:8000 (`GET /up` → 200) |
| DB_DRIVER | sqlite |
| LOGIN_PASSWORD | password |

Code under test: `unit-hq-api` `9abb1a5` (`origin/dev`), `unit-hq-panel` `2a5764d` (`origin/dev`). Fetch of both remotes at session start found **no newer commits** than that pair (user-reported follow-up fixes were not on GitHub yet). Compact world: `php artisan demo:seed --fresh --compact`. Mutating-scenario resets used `database/database.sqlite.snapshot`. Host wall clock: **2026-09-08**. Demo civil dates remain June 2025.

None skipped: every scenario in this suite has `requires_db: any`.

## Summary

| Result | Count |
|---|---|
| pass | 29 |
| fail | 5 |
| skipped | 0 |

Smoke: **5 pass / 1 fail**. Core: **24 pass / 4 fail**.

---

## Smoke

### S-01 — pass
- persona: readonly
- route: /login
- notes: Rita Lectura signed in; landed on `/leasing/contacts`. Direct URL `/settings/people` redirected to `/settings/general`. People item absent from the settings sidebar.

### S-02 — pass
- persona: agent-mad
- route: /leasing/contacts
- notes: Ana López. Lucía Ferrer present; Marcos Vega / Lucía **Active**. **Sofía Marín is not on the MAD-01 agent list** (site-scope fix from `f67242c` holds).

### S-03 — pass
- persona: ops
- route: /billing/delinquency
- notes: Lucía Ferrer has an **open** case, overdue **€122.45**, matching contract overview. Timeline: notice, late fee €11.15, notice, revoke access. Filter **8–14 days** is empty; row appears under **All days** as **464 days** overdue (host clock 2026-09-08 vs seed-end 2025-06-10). Compact ladder-started assertion holds; overlock/denied-door not required.

### S-04 — pass
- persona: ops
- route: /billing/runs
- notes: Reset first. Toast **Billing run finished — 0 billed, 11 failed.** Landed on `/billing/runs/11`. Empty/failed catch-up vs wall-clock horizon is allowed.

### S-05 — fail
- persona: ops
- route: /inbox
- notes: Pilar Santos WhatsApp thread and Spanish lines present. Triage discard of `stranger.two@unknown.example` toasted **Message discarded.** Composer still **The 24-hour session is closed. Send an approved template to reach this contact.**
- screenshot: e2e/runs/2026-09-08-132314/S-05-step-4.png
- bug: e2e/runs/2026-09-08-132314/bugs.md#s-05

### S-06 — pass
- persona: ops
- route: /automations
- notes: Playbook badge; read-only banner verbatim; no palette; no Save. **Open playbook** → `/playbooks/1`. Runs timeline loads. Editor `kind` crash from the first smoke run is gone (`unit-hq-panel` `2a5764d`).

---

## Core — leasing

### LEA-01 — pass
- persona: ops
- route: /leasing/deals
- notes: Gracia Lin deal **Negotiating**; offer **Viewed**.

### LEA-02 — fail
- persona: ops
- route: /leasing/contracts
- notes: Omar Haddad **Pending** on `MAD-01-SS4-04`. Overview **Start date** and **Signed at** are both **10/06/2025** (10 June), not 20 June. Overdue **€125.84** on a pending contract. Rent-roll (INS-02) lists Omar move-in **20/06/2025** — dates disagree.
- screenshot: e2e/runs/2026-09-08-132314/LEA-02-step-4.png
- bug: e2e/runs/2026-09-08-132314/bugs.md#lea-02

### LEA-03 — pass
- persona: ops
- route: /leasing/contracts
- notes: Inés Valdés **Notice given**; moving out **2025-06-17** (within 5–14 days after seed-end 2025-06-10).

### LEA-04 — fail
- persona: ops
- route: /leasing/contacts
- notes: Patricia Keller / **Los Keller**. Two contracts: Active `MAD-01-SS4-02` and Ended `MAD-01-SS3-02`. Ended overview: deposit **€0.00**, no **Cleaning fee** / €50 on Overview, Items, Invoices (F2026-000004 **Alquiler** €111.32 only), Payments, Billing periods, Activity.
- screenshot: e2e/runs/2026-09-08-132314/LEA-04-step-6.png
- bug: e2e/runs/2026-09-08-132314/bugs.md#lea-04

### LEA-05 — pass
- persona: ops
- route: /leasing/contracts
- notes: Javier Peña awaiting signature. Envelope **Declined — Terms not acceptable — looking elsewhere**.

### LEA-06 — pass
- persona: ops
- route: /leasing/contracts
- notes: Sofía Marín MAD-02-SS4-01 Madrid Norte. Envelope **Awaiting · sent 10/06/2025 · expired** (wall clock). Ops can see her.

### LEA-07 — fail
- persona: ops
- route: /leasing/tasks
- notes: Tasks heading and filters load. Search **Gracia Lin** (accented) → **No data**, Showing 0 of 0. Lead-chase enrolment did not leave a Gracia task.
- screenshot: e2e/runs/2026-09-08-132314/LEA-07-step-3.png
- bug: e2e/runs/2026-09-08-132314/bugs.md#lea-07

### LEA-08 — pass
- persona: ops
- route: /leasing/unit-map
- notes: Madrid Centro map and legend. **MAD-01-SS6-01** Marcos Vega occupied.

---

## Core — facility

### FAC-01 — pass
- persona: ops
- route: /facility/units
- notes: Occupied tab (12). **Marcos Vega** `MAD-01-SS6-01` class **SS6 — Trastero 10 m²**. **Lucía Ferrer** `MAD-01-SS3-01` **SS3 — Trastero 7 m²**. Name search on **All** is client-side on the current page only and misses occupants; Occupied scan is the working path.

### FAC-02 — pass
- persona: ops
- route: /facility/unit-classes
- notes: Heading **Unit class**. SS1–SS8 labels **Trastero 5 m²** … **Trastero 12 m²** (plus AL*).

### FAC-03 — pass
- persona: ops
- route: /facility/rates
- notes: Columns **MADRID CENTRO** and **MADRID NORTE** only (no Madrid Sur). EUR prices present (€72.00–€218.00). Every cell also renders **` / undefined`** — extra product bug, not a scenario fail (EUR is visible).
- screenshot: e2e/runs/2026-09-08-132314/FAC-03-undefined.png
- bug: e2e/runs/2026-09-08-132314/bugs.md#fac-03

### FAC-04 — pass
- persona: ops
- route: /facility/insurance-plans
- notes: **Básico** 3000.00 EUR; **Premium** 5000.00 EUR.

### FAC-05 — pass
- persona: ops
- route: /facility/access-control
- notes: Heading **Access events**. Site filter **All sites**. Empty events table, no crash.

---

## Core — billing

### BIL-01 — pass
- persona: accountant
- route: /billing/invoices
- notes: Carmen Contable. Heading **Invoices**, kind **All kinds**, 17 rows. Named cast invoices with numbers and EUR totals (Patricia Keller F2026-000008 €125.84 / F2026-000004 €111.32; Omar F2026-000015 €125.84).

### BIL-02 — pass
- persona: ops
- route: /leasing/contracts
- notes: Rafa Núñez contract **#11 Active**, balance owed **€0.00**. Payments tab: €50.00 and €99.22 fully allocated 10/06/2025 and 09/06/2025. Column control labelled **Reverse** is an action on a posted payment, not a Reverse status. Method column shows raw i18n key `billing.payments.manual.methods.stripe_card` (extra bug).
- screenshot: e2e/runs/2026-09-08-132314/BIL-02-payments.png
- bug: e2e/runs/2026-09-08-132314/bugs.md#bil-02-i18n

### BIL-03 — pass
- persona: ops
- route: /leasing/contracts
- notes: Nadia Rahal **#7**. Badge **20% off** (Percent). Pricing timeline **€92.80 until 09/06/2025 — €104.80 until 09/08/2025 — €120.80 thereafter**.

### BIL-04 — pass
- persona: sm-mad-01
- route: /billing/runs
- notes: Site Manager MAD-01. Heading **Billing runs**. **Run billing now** absent. Banner **Failed to load billing runs.** (list/load-error is allowed; execution control must stay hidden).

---

## Core — inbox

### INB-01 — pass
- persona: ops
- route: /inbox
- notes: SMS chip. Marcos Vega inbound **Hola — ¿tienen algo más grande que mis 8 m²? Quiero ampliar.** Composer not used.

### INB-02 — fail
- persona: ops
- route: /inbox
- notes: Bea Torres exists as occupied tenant (`MAD-01-SS2-04`). Inbox Email and SMS search `q=Bea+Torres` → **No threads match these filters.** No suppression badge and no SMS fallback thread.
- screenshot: e2e/runs/2026-09-08-132314/INB-02-step-4.png
- bug: e2e/runs/2026-09-08-132314/bugs.md#inb-02

### INB-03 — pass
- persona: ops
- route: /inbox
- notes: Calls chip. Vera Voicemail wrap-up **Left voicemail about unit availability** (50s). Composer not used.

---

## Core — insights

### INS-01 — pass
- persona: ops
- route: /insights/occupancy
- notes: Native **Occupancy**. Unit occupancy 5% (12 / 240). Madrid Centro × class breakdown. No Metabase.

### INS-02 — pass
- persona: accountant
- route: /insights/rent-roll
- notes: Accountant sees native **Rent roll** (12 units, monthly rent €1,204.80). After sign-out, **agent-mad** on the same URL: blank main pane, no financial table (empty gate / deny). Ana López remains on `/insights/rent-roll`.

---

## Core — playbooks

### PLB-01 — pass
- persona: ops
- route: /playbooks/debt-process
- notes: **Default debt process** Active. Builder: Day 0 Payment reminder, Day 2 SMS nudge, Day 4 Overdue notice email, Day 7 Call the tenant. Nothing sent.

### PLB-02 — pass
- persona: ops
- route: /playbooks/lead-chase
- notes: **Default lead chase** Active. Builder loads. Enrolments **Active**: **0 enrolments** / **No active enrolments.** Empty enrolment list is allowed; Gracia task absence is LEA-07.

---

## Core — settings

### SET-01 — pass
- persona: manager
- route: /settings/people
- notes: Stayed on People (no redirect). Table includes Ops Manager, Ana López, Rita Lectura (also compact site managers; no `sm-mad-03`…`05` / `agent-sur`). `/settings/roles`: Accountant, Leasing agent, Operations manager, Owner, Read only, Site manager.

### SET-02 — pass
- persona: ops
- route: /settings/tax-rates
- notes: **Exento (seguro)** 0.00%; **IVA 21%** 21.00% Default. ES, ongoing.

### SET-03 — pass
- persona: ops
- route: /settings/facility/sites
- notes: Active filter. **Madrid Centro MAD-01** and **Madrid Norte MAD-02** only (Showing 2 of 2). No Madrid Sur / Este / Oeste.

---

## Core — marketing

### MKT-01 — pass
- persona: ops
- route: /marketing/templates/email
- notes: Heading **Email**. Three templates listed (Payment reminder, Enquiry thanks, Offers this month). Empty list would also pass. **New template** not clicked.
