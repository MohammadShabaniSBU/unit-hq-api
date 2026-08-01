# S08-04 — Panel: run-log lifecycle

## Context

The run-log panel shipped pre-`waiting`; it now lies by omission — parked runs render as
running, cancellations show no cause, guards are invisible. Half a day of truth-telling.

## Scope

**In:** status rendering for the full lifecycle, cancel action, wait/guard visibility,
runs-list filters.
**Out:** graph editor changes (S09 owns editor work), fixture import UI (nice-to-have,
noted).

## Panel surface

- **Runs list:** `waiting` chip (amber, with `waiting_until` relative time — "resumes in
  2d 4h"), `cancelled` chip (grey, cause icon: hand = manual, shield = guard). Status
  filter gains both. Default sort unchanged.
- **Run detail:** wait steps render parked state (hourglass + resume time) flipping to
  duration once resumed ("waited 3d"); the synthetic `run.cancelled` step renders
  prominently with cause + causer + timestamp; guard presence shows as a header badge
  ("guarded") with the condition tree in a read-only disclosure (reuse the branch
  condition renderer). Unexecuted nodes after a cancel render dimmed-untouched —
  visually distinct from `skipped` (tooltip explains the difference; the vocabulary
  honesty from 00 made visible).
- **Cancel action:** on `pending|running|waiting` runs, confirm modal (reason optional →
  `cancel_cause = manual`), disabled-with-tooltip on terminals. Respect the existing
  `canEdit` stopgap helper — one more S17 caller, noted.
- i18n `automations.runs.lifecycle.*`; es: waiting → *En espera*, cancelled →
  *Cancelada*, guard → *Condición de salida* (the operator-facing name S09 will also
  use — coin it once, here).

## Acceptance criteria

- [ ] A parked run never displays as running anywhere (list, detail, any count badge).
- [ ] Cancel works from all three states, audited; terminals refuse.
- [ ] Guard badge + tree disclosure render; cancelled-by-guard runs are visually
      self-explanatory without opening step JSON.
- [ ] Dimmed-untouched vs skipped are distinguishable and tooltipped.
- [ ] `lint` + `typecheck`; `en/es/fr` complete.

## Tests required

API already covered by 00; panel manual script in the PR: parked-run rendering (1),
resume flip (2), manual + guard cancel appearances (3), untouched-vs-skipped (4),
filters (5).
