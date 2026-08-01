# S09-04 — Panel: the two builders

## Context

The reason playbooks exist as a concept: an operator page where "day 1 email, day 2 SMS,
day 3 call task, stops when they pay" is *visibly that sentence* — a vertical timeline,
no canvas, no nodes, exit condition stated as product copy, not configured.

## Scope

**In:** Automations → Debt process + Lead chase pages (builder + enrolments), shared
components, deal/case cross-links, general-editor read-only treatment (00's protection,
rendered).
**Out:** template *builder* improvements (S13 — steps reference existing EmailTemplates
or inline text), enrolment analytics beyond counts (S17).

## Panel surface

### Builder (per kind page; identical component, kind-configured)

Header: name, active toggle (activation runs 00's overlap validation for debt),
enrolment-filter controls per kind (site/policy/min-days · site/stage/source), and the
**fixed exit statement** as copy: *"Enrolment ends automatically when the balance is
paid, written off, or the contract ends"* / *"…when the deal advances, is won, or is
lost"* — with a small "how it works" disclosure explaining guards in operator words.

Body: vertical timeline. Each step card: day badge (Day 0/2/4 — computed labels between
cards show "wait 2 days"), action icon + summary line, expand to edit params — email:
template select or inline subject/body with a token-insert menu (`TokenResolver`
vocabulary surfaced as friendly names); SMS: body + tokens + live char count; task:
title + urgent toggle; debt sends: the `record_notice` pairing as a simple "also record
a formal notice: [type]" checkbox row. Add-step inserts at any position; arrow reorder
(house pattern); offsets validated non-decreasing with inline nudge rather than error
where possible.

Saving an active playbook surfaces 00's versioning choice plainly: "N enrolments in
progress will finish on the previous version" + the explicit exit-them checkbox.

### Enrolments tab (per playbook)

Active table: subject (contact + contract/deal links), enrolled date, **progress dots**
(the S07 ladder-dots component generalised: done/waiting/upcoming, next-step date),
current wait countdown. Exited table: exit cause in words (paid → *Pagado* etc.,
mapping guard/cancel causes), duration, steps completed. Row → the run detail
(S08-04's page), breadcrumbed back. Manual exit action per enrolment (cancel, cause
manual) with confirm.

### Cross-links

Delinquency case timeline shows playbook steps (01's `playbook` trigger badge) and the
case header links its active enrolment. Deal detail gains an "In lead chase — step 2 of
4, next SMS in 1 day" chip linking the enrolment. Compiled automations in the general
list: badge + read-only banner linking here (00's protection made visible).

i18n `playbooks.*` (shared) + per-kind copy; es: debt process → *Proceso de impago*,
lead chase → *Seguimiento de leads* (confirm with the operator — *interesados* may read
better), exit → *Salida automática*.

## Implementation notes

`usePlaybook(kind)` / `usePlaybookEnrolments`; the builder is one
`PlaybookBuilder.vue` with a kind config object (actions allowed, filter controls, copy
keys) — resist per-kind forks. Enrolment queries are the runs API filtered by
automation lineage (playbook_id across versions), aggregated server-side — one endpoint,
bounded queries (the standing rule).

## Acceptance criteria

- [ ] The two-minute test from the sprint README passes with a stopwatch, honestly.
- [ ] Token insertion renders correctly in a sent (log-driver) email/SMS from a real
      enrolment.
- [ ] Progress dots + countdown match engine state incl. `waiting`; manual exit works.
- [ ] Versioning save-dialog behaves per 00 for both checkbox states.
- [ ] Cross-links all land correctly; compiled automations read-only end-to-end.
- [ ] `lint` + `typecheck`; `en/es/fr` complete.

## Tests required

API: `EnrolmentEndpointTest::lineage_aggregate_bounded`. Panel manual script in the PR:
the stopwatch build (1), activate + seeded enrolment appears (2), pay → exit visible in
both timelines (3), edit-active dialog both paths (4), token menu → rendered send (5),
cross-links sweep (6), read-only editor (7).
