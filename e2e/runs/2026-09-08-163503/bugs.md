# Product bugs — 2026-09-08-163503

No scenario failures this session. Extra defects that did not fail their scenario:

### LEA-01-offer-undefined
- route: /leasing/deals/3
- persona: ops
- step: offer card on deal overview
- what happened: Gracia Lin offer #1 (Viewed) shows price **€128.00 / undefined**. The facility rates matrix (FAC-03) no longer appends `/ undefined`.
- what was expected: EUR amount only, or a defined billing-period label — never the string `undefined`.
- screenshot: e2e/runs/2026-09-08-163503/LEA-01-offer-undefined.png

### LEA-02-pending-overdue
- route: /leasing/contracts/17
- persona: ops
- step: 4
- what happened: Omar Haddad **Pending** contract has start **20/06/2025** (scenario pass) but overview still shows **Overdue balance: €125.84** and billed-through **20/07/2025** before move-in.
- what was expected: A pending contract that has not started should not present an overdue balance as if occupancy had begun.
- screenshot: e2e/runs/2026-09-08-163503/LEA-02-pending-overdue.png
