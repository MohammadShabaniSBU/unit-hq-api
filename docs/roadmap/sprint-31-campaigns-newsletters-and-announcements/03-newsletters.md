# S31-03 — Newsletters

Newsletters are campaigns with `type = newsletter`; this task adds only what differs.

## Rules

- `subscription_list_id` must reference a list with `kind = newsletter`.
- Template purpose allowlist: `newsletter | marketing`.
- `newsletter_issue_number`: assigned at submit as `max(issue_number) + 1` for that
  list among non-cancelled newsletters (locked on the list row). Token
  `{{newsletter.issue_number}}`, `{{newsletter.name}}` available in templates
  (extend `SubjectTokenBag::sample` + the real bag).
- Segment is optional (e.g. "Madrid tenants only" edition); consent always required.
- Frequency cap: newsletters **count toward** the cap but are **not blocked by it**
  (a subscriber asked for them). Make this a setting
  `marketing_newsletter_bypasses_cap` default true.

## Endpoints

- `GET subscription-lists/{list}/newsletters` — issues with status, sent date, counts.
- `POST subscription-lists/{list}/newsletters/next` — duplicates the latest **sent**
  issue into a new draft (same template family, list, segment; no schedule).

## Panel

Marketing → Newsletters: one card per newsletter list (subscribers by channel, last
issue, next scheduled) → issue list → "New issue" (pre-filled).

## Acceptance criteria

- [ ] Issue numbers are gapless per list across concurrent submits (cancelled issues
      keep their number; document it).
- [ ] A non-newsletter list is rejected for `type = newsletter`.
- [ ] Newsletter is sent to a contact at the frequency cap when the bypass setting is on.
