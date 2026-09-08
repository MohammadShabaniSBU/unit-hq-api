# E2E smoke + core — 2026-09-08-163503

## Environment

| Name | Value |
|---|---|
| PANEL_URL | http://localhost:3000 |
| API_URL | http://127.0.0.1:8000 (`GET /up` → 200) |
| DB_DRIVER | sqlite |
| LOGIN_PASSWORD | password |

Code under test: `unit-hq-api` `35747cc` (`origin/dev`), `unit-hq-panel` `7b51843` (`origin/dev`). Compact world: `php artisan demo:seed --fresh --compact` then SQLite snapshot. Host wall clock: **2026-09-08**. Demo civil dates remain June 2025.

None skipped: every scenario has `requires_db: any`.

## Summary

| Result | Count |
|---|---|
| pass | 34 |
| fail | 0 |
| skipped | 0 |

Previous session (2026-09-08-132314) had 5 scenario fails. All five pass on this tree.

---

## Smoke

### S-01 — pass
- persona: readonly
- route: /login
- notes: Rita Lectura signed in; `/settings/people` redirected to `/settings/general`. People table not shown.

### S-02 — pass
- persona: agent-mad
- route: /leasing/contacts
- notes: Ana López. Unfiltered **All 15**. Lucía Ferrer and Marcos Vega deals/contracts present (Active). **Sofía Marín** not on the list.

### S-03 — pass
- persona: ops
- route: /billing/delinquency
- notes: **8–14 days** empty (wall clock). **All days**: Lucía Ferrer open, **€122.45**, 464 days. Timeline late fee €11.15 + notices. Overview balance **€122.45**.

### S-04 — pass
- persona: ops
- route: /billing/runs
- notes: Reset first. Toast **Billing run finished — 0 billed, 11 failed.** `/billing/runs/11`. Catch-up cap vs wall-clock horizon is allowed.

### S-05 — pass
- persona: ops
- route: /inbox
- notes: Reset first. Pilar WhatsApp: template + inbound `¿pueden enviarme el enlace de pago?`. Composer **Free replies for 20h 45m** (open window). Triage discard of `stranger.two@unknown.example` toasted **Message discarded.**

### S-06 — pass
- persona: ops
- route: /automations
- notes: **Default debt process** Playbook badge. Banner verbatim. No palette. Open playbook `/playbooks/1`. Run #3 timeline loads.

---

## Core — leasing

### LEA-01 — pass
- persona: ops
- route: /leasing/deals
- notes: Gracia Lin **#3 Negotiating**. Offer #1 **Viewed**. Offer price still renders **€128.00 / undefined** (extra display bug; scenario does not require a cadence label).

### LEA-02 — pass
- persona: ops
- route: /leasing/contracts
- notes: Omar Haddad **#17 Pending**. **Start date 20/06/2025**, signed **10/06/2025**. Overdue banner **€125.84** remains on a not-yet-started contract (extra; scenario only required pending + post-seed-end move-in).

### LEA-03 — pass
- persona: ops
- route: /leasing/contracts
- notes: Inés Valdés **#6 Notice given**. Moving out **2025-06-17**.

### LEA-04 — pass
- persona: ops
- route: /leasing/contacts
- notes: Patricia Keller / Los Keller. Active + Ended **#4**. Invoice F2026-000013 line **vacate.deposit_deduction: Cleaning fee €50.00**. Deposit settlement Deduct partially €50.00.

### LEA-05 — pass
- persona: ops
- route: /leasing/contracts
- notes: Javier Peña awaiting signature; envelope **declined** — *Terms not acceptable — looking elsewhere*.

### LEA-06 — pass
- persona: ops
- route: /leasing/contracts
- notes: Sofía Marín **#16** MAD-02-SS4-01 Madrid Norte. **Awaiting signature**. Envelope **Awaiting · sent 10/06/2025 · expired**.

### LEA-07 — pass
- persona: ops
- route: /leasing/tasks
- notes: Search **Gracia Lin** (no accent) → **Call the lead**, Open, Related Gracia Lin.

### LEA-08 — pass
- persona: ops
- route: /leasing/unit-map
- notes: Madrid Centro, Planta baja, legend (Available/Occupied/…). Occupied units on the plan. Occupant **Marcos Vega / MAD-01-SS6-01** confirmed on FAC-01 Occupied list.

---

## Core — facility

### FAC-01 — pass
- persona: ops
- route: /facility/units
- notes: Occupied 12. Marcos Vega `MAD-01-SS6-01` **SS6 — Trastero 10 m²**. Lucía Ferrer **SS3 — Trastero 7 m²**.

### FAC-02 — pass
- persona: ops
- route: /facility/unit-classes
- notes: SS1–SS8 **Trastero 5 m²** … **Trastero 12 m²**.

### FAC-03 — pass
- persona: ops
- route: /facility/rates
- notes: MADRID CENTRO + MADRID NORTE only. EUR amounts (€72.00–€218.00). **No `/ undefined`**.

### FAC-04 — pass
- persona: ops
- route: /facility/insurance-plans
- notes: Básico 3000.00 EUR; Premium 5000.00 EUR.

### FAC-05 — pass
- persona: ops
- route: /facility/access-control
- notes: Heading **Access events**. Site filter All sites. Empty events, no crash.

---

## Core — billing

### BIL-01 — pass
- persona: accountant
- route: /billing/invoices
- notes: Rafa Núñez **F2026-000011** Paid, total **€99.22**.

### BIL-02 — pass
- persona: ops
- route: /leasing/contracts
- notes: Rafa **#11** balance €0.00. Payments **Card (Stripe)** €50.00 and €99.22 allocated. No raw i18n key.

### BIL-03 — pass
- persona: ops
- route: /leasing/contracts
- notes: Nadia Rahal **#7**. **20% off** Percent. Timeline €92.80 until 09/06/2025 · €104.80 until 09/08/2025 · €120.80 thereafter.

### BIL-04 — pass
- persona: sm-mad-01
- route: /billing/runs
- notes: **Run billing now** absent. **Failed to load billing runs.** (load error allowed).

---

## Core — inbox

### INB-01 — pass
- persona: ops
- route: /inbox
- notes: SMS Marcos Vega: **Quiero ampliar.** Not sent.

### INB-02 — pass
- persona: ops
- route: /inbox
- notes: Email: **Address suppressed** + **This address is suppressed. Replies won't be sent.** SMS: **We could not reach you by email — here is a quick SMS update about your account.**

### INB-03 — pass
- persona: ops
- route: /inbox
- notes: Calls. Vera Voicemail **Left voicemail about unit availability** (50s).

---

## Core — insights / playbooks / settings / marketing

### INS-01 — pass
- persona: ops
- route: /insights/occupancy
- notes: Native Occupancy. 5% (12/240). No Metabase.

### INS-02 — pass
- persona: accountant
- route: /insights/rent-roll
- notes: Accountant sees native Rent roll (12 units). **agent-mad** on the same URL: blank pane / no financial table.

### PLB-01 — pass
- persona: ops
- route: /playbooks/debt-process
- notes: **Default debt process** Active. Days 0/2/4/7 steps. Nothing sent.

### PLB-02 — pass
- persona: ops
- route: /playbooks/lead-chase
- notes: **Default lead chase** Active. Enrolments Active: **0 enrolments** (allowed). Gracia task still present via LEA-07.

### SET-01 — pass
- persona: manager
- route: /settings/people
- notes: Stayed on People. Ops Manager, Ana López, Rita Lectura listed (compact staff only).

### SET-02 — pass
- persona: ops
- route: /settings/tax-rates
- notes: Exento (seguro) 0.00%; IVA 21% Default.

### SET-03 — pass
- persona: ops
- route: /settings/facility/sites
- notes: Active. Madrid Centro MAD-01 and Madrid Norte MAD-02 only (2 of 2).

### MKT-01 — pass
- persona: ops
- route: /marketing/templates/email
- notes: Heading **Email**. Three templates. New template not clicked.
