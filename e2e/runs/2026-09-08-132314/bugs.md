# Product bugs — 2026-09-08-132314

Scenario failures first. Extra display defects that did not fail their scenario are at the end.

### S-05
- route: /inbox
- persona: ops
- step: 4
- what happened: Pilar Santos's WhatsApp thread is present. Conversation includes the outbound template and her inbound `¿pueden enviarme el enlace de pago?`. The composer is blocked with **The 24-hour session is closed. Send an approved template to reach this contact.** Message dates are 10/06/2025; the host clock is 2026-09-08. Triage discard still works.
- what was expected: An open-window / in-session affordance. Fixtures: WhatsApp session window open at compact seed-end.
- screenshot: e2e/runs/2026-09-08-132314/S-05-step-4.png

### LEA-02
- route: /leasing/contracts/17
- persona: ops
- step: 4
- what happened: Omar Haddad contract **#17** is **Pending** (`MAD-01-SS4-04`). Overview **Start date** and **Signed at** are both **10/06/2025**. Overdue banner **€125.84**. Native rent-roll lists this same contract with move-in **20/06/2025**.
- what was expected: Pending, signed, move-in 10 days after compact seed-end — **2025-06-20**. Contract overview and rent-roll should agree.
- screenshot: e2e/runs/2026-09-08-132314/LEA-02-step-4.png

### LEA-04
- route: /leasing/contracts/4
- persona: ops
- step: 6
- what happened: Patricia Keller / Los Keller has two contracts (Active `MAD-01-SS4-02`, Ended `MAD-01-SS3-02`). Ended contract **#4**: deposit **€0.00**, no cleaning-fee line, no €50 deduction on Overview / Items / Invoices / Payments / Billing periods / Activity. Invoice F2026-000004 is rent **Alquiler MAD-01-SS3-02** €111.32 only.
- what was expected: Vacated contract shows a deposit settlement with a **Cleaning fee** (or €50) deduction.
- screenshot: e2e/runs/2026-09-08-132314/LEA-04-step-6.png

### LEA-07
- route: /leasing/tasks
- persona: ops
- step: 3
- what happened: Tasks page loads. Search **Gracia Lin** with All statuses returns **No data** (0 of 0). PLB-02 enrolments tab also shows **0 enrolments**.
- what was expected: A lead-chase task for Gracia Lin is present (enrolment ran in the seed).
- screenshot: e2e/runs/2026-09-08-132314/LEA-07-step-3.png

### INB-02
- route: /inbox
- persona: ops
- step: 2–5
- what happened: Bea Torres is an occupied tenant (`MAD-01-SS2-04` on `/facility/units`). Inbox Email and SMS with search `Bea Torres` both show **No threads match these filters.** No hard-bounce / suppressed affordance and no SMS fallback copy.
- what was expected: Email channel shows suppression / bounce (or contact pane marks email suppressed). SMS fallback thread exists with seeded wording about not reaching her by email.
- screenshot: e2e/runs/2026-09-08-132314/INB-02-step-4.png

### FAC-03
- route: /facility/rates
- persona: ops
- step: 3
- what happened: MAD-01 / MAD-02 EUR prices render (e.g. SS1 **€72.00**) but every matrix cell appends **` / undefined`**.
- what was expected: EUR amount only (or a defined cadence/label). `undefined` should not appear in the operator UI.
- screenshot: e2e/runs/2026-09-08-132314/FAC-03-undefined.png

### BIL-02-i18n
- route: /leasing/contracts/11?tab=payments
- persona: ops
- step: 2
- what happened: Rafa Núñez payments are allocated and balance is €0.00 (scenario pass). Method column shows the raw key **`billing.payments.manual.methods.stripe_card`** instead of a human label.
- what was expected: A translated payment-method label.
- screenshot: e2e/runs/2026-09-08-132314/BIL-02-payments.png
