# S30-07 — Invariants, docs & tests

## New invariants (`09-conventions-and-invariants.md`, continue numbering after 74)

- **75. Marketing consent is an append-only ledger.** `consent_events` is never
  updated or deleted (redaction nulls `evidence` only); current consent is the latest
  event per (contact, channel, list), computed. Marketing never reads
  `contact_channels.opted_in`.
- **76. Grants need evidence; machines only withdraw.** Only forms, double opt-in,
  operators (with note), sourced imports, the preference center and soft opt-in (if
  enabled) grant consent. Automations, AI tools and inbound triage may withdraw only.
- **77. Unsubscribe links are safe to prefetch.** GET on any preference/unsubscribe
  URL never writes; only POST does.
- **78. Marketing sends use a marketing-eligible provider account** and always carry
  the renderer-appended legal footer.
- **79. Signup forms may create contacts** (explicit exception to inbound-never-creates),
  always with consent evidence and never a deal.

## Doc updates

- `06-communications.md`: consent ledger, lists, preference tokens, provider purpose,
  footer.
- `07-people-and-auth.md`: `MarketingView`, `MarketingManage`, `ConsentManage`.
- `10-open-decisions.md`: M1–M8 decided; legal questions 1–3 open with owner.
- `analytics-schema.md`: additive `analytics.v_consent_state` (latest state per
  contact/channel/list) for Insights.
- `AGENTS.md`: "never grant consent from code paths other than ConsentWriter grant sources".

## Test checklist

- [ ] Ledger: append-only trigger, latest-wins, redaction.
- [ ] Preference center: GET no-write, POST changes, one-click, legacy token.
- [ ] Forms: double opt-in, enumeration-safe response, honeypot, rate limit.
- [ ] Segments: each field, invalid-tree surfacing, RBAC scoping.
- [ ] Provider purpose resolution matrix (site/company × purpose × class).
- [ ] `RouteAuthCoverageTest`, `PermissionCoverageTest` green.
