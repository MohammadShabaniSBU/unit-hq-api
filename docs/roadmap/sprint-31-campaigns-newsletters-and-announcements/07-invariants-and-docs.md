# S31-07 — Invariants, docs & tests

## New invariants (continue after S30's)

- **80. Campaign sends pin a template version and an audience snapshot at scheduling;
  both are immutable afterwards.** Editing a scheduled campaign = cancel + duplicate.
- **81. One recipient row per (send, contact) and claim-before-send.** No campaign
  message is sent without a claimed `campaign_recipients` row; every non-sent row
  carries a `skip_reason`.
- **82. Consent is re-checked at send time**, not only at materialisation.
- **83. Announcements are transactional only within their fence:** current tenants of
  chosen sites, `announcement` templates, no promotions, `AnnouncementSend`
  permission, logged on each site.
- **84. Campaign statistics are derived** from recipients, messages and consent
  events — never stored counters.

## Doc updates

- `06-communications.md`: campaign engine, queue, throttling, footer, provenance.
- `07-people-and-auth.md`: campaign permissions and site scoping.
- `12-automation-engine.md`: note that drips stay automations (C9).
- `analytics-schema.md`, `report-definitions.md`: new views and reports.
- `10-open-decisions.md`: C1–C9 decided; S32 backlog listed.
- `00-overview.md`: Marketing module summary.

## Test checklist

- [ ] Lifecycle transitions (legal/illegal), approval rules, version pinning.
- [ ] Materialisation per type, RBAC intersection, idempotent rerun.
- [ ] Gates: consent, exclusions, frequency cap, suppression → skip reasons.
- [ ] Send window deferral and announcement override (SiteClock-frozen tests).
- [ ] Crash/retry exactly-once (claim tests with two workers simulated).
- [ ] Pause/resume/cancel mid-send.
- [ ] Report reconciliation incl. late delivery webhooks.
- [ ] Route/permission coverage tests.
