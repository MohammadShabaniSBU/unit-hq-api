# S15-04 — Surfaces & events

## Context

Operability: mapping the client's physical locks to units, seeing and trusting access
state everywhere tenancy is discussed, and the event log that turns door hardware
into CRM memory. Read-surfaces over 00–03's data plus one workflow (mapping).

## Scope

**In:** point-mapping UI, access state on unit/contract/context-panel, events log
(site + unit + contact), attention chips (drift, unmapped, failed grants), sync
health card, docs + es sweep. **Out:** floor-plan visual mapping (the unit map gains
a glyph, not an editor), event analytics (S16 reporting reads `access_events`).

## Panel surface

**Mapping** (Settings → Integrations → Access control → Points): the discovered list
(01) beside our sites/units — per row: provider label + kind hint, then assignment
controls (site select; unit search when `unit_door`; type). Unassigned-discovered
and assigned-but-vanished (removed at provider) both surfaced; bulk-assign by
label-pattern for the 500-unit reality (match "AL6-06" in provider labels →
suggested unit, confirm-all flow — the practical difference between an afternoon
and a week of clicking). Archive mapping releases the unit-unique.

**Access state, three places.** Unit page state card gains an Access row (point
mapped? · granted-to whom · overlock/suspension effect). Contract page gains the
Access card: per-point rows with honest grant states (`applied` / `applying…` /
`failed` + retry action / suspended-badge), suspension banner with reason + who +
restore action (respecting the manual-vs-delinquency asymmetry from 03). Inbox
context panel tenancy block: a lock glyph state ("Access suspended · day 9") — the
operator answering the phone knows *why they're calling*.

**Events log.** Contact page tab + unit page tab + a site-level page (Facility →
Access events): time, point, contact (or credential ref for unresolved), granted/
denied chip, the restriction-context note on denied-during-suspension rows.
Filters: point, contact, denied-only, date. Cursor-paginated (the S11 idiom — this
table grows fast).

**Attention + health.** The contracts-index chip pattern gains: failed grants (n),
drift denied-but-granted (n, red). Settings account card: last full sync, grants
applied/failed counts, unknown-grant list with the human revoke action (02's
never-auto-revoke).

**Docs.** `02-facility.md` gains access points; `10-open-decisions.md` collects the
sprint's deferrals (multi-door units, visitor access, per-point suspension,
after-hours rules); es sweep: *Punto de acceso*, *Acceso suspendido*, *Denegado* —
operator-review the vocabulary.

## Acceptance criteria

- [ ] Mapping: assign/bulk-suggest/archive flows against seeded discovered points;
      the 500-unit bulk path measured in the PR (count of confirmed suggestions).
- [ ] Three access-state surfaces render every grant state honestly, incl.
      `applying…` after a seeded cure (the no-early-applied promise, visible).
- [ ] Events log with filters + the denied-context note; cursor pagination bounded.
- [ ] Attention chips filter correctly; unknown-grant revoke is human-only.
- [ ] Docs + i18n complete, es reviewed.

## Tests required

API: `MappingTest::assign_bulk_archive`, `AccessStateTest::honest_states_three_surfaces`,
`AccessEventsTest::filters_cursor_bounded`. Panel manual script: mapping afternoon-
simulation (1), state sweep incl. applying (2), the day-9 context glyph (3), events
filters (4), attention → actions (5).
