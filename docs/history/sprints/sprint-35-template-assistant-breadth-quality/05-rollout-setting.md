# S35-05 — Rollout setting

## Context

Owners need to switch the assistant off without a deploy (bad model day, cost
spike) and widen access deliberately.

## Scope

**In:** company setting, fail-closed gate, settings UI.
**Out:** per-site enablement.

## Schema changes

None. Add `template_assistant_enabled` (bool, default `false`) and
`template_assistant_daily_turn_limit` (int, nullable → config default) to the settings
payload class that owns AI settings; if none exists, `MarketingSettings` is **not**
the right home — create `AiSettings` following `GeneralSettings`.

## Implementation notes

- Middleware/guard on every assist route: setting off → 403
  `{ message: "errors.forbidden", data: { reason: "assist.disabled" } }`. Sessions and
  turns are kept.
- `GET /api/user` (or the settings bootstrap the panel already reads) exposes the flag
  so the tab hides without a failed request.
- Daily limit from the setting overrides config when set.
- Setting change is a Tier-2 settings activity (existing mechanism).

## API surface

Existing settings endpoints accept the two keys.

## Panel surface

Settings → AI providers (or the AI section that exists after the settings
reorganisation) → **Template assistant** card: toggle, daily limit, link to the
quality report. i18n en/es/fr.

## Invariants

- **45** fail-closed.

## Acceptance criteria

- [ ] Default off on a fresh install; demo seed turns it on.
- [ ] Off → tab hidden, every assist route 403, in-flight turns finish but cannot be
      applied (status endpoint also gated).
- [ ] Limit change takes effect on the next turn.

## Tests required

| Test | Asserts |
|---|---|
| `TemplateAssistRolloutTest::disabled_is_fail_closed` | All routes |
| `TemplateAssistRolloutTest::setting_limit_overrides_config` | Limit |
