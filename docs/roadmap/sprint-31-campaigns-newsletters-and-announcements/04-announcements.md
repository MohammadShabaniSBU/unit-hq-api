# S31-04 — Announcements

Service notices to current tenants: closures, access-hour changes, gate code changes,
maintenance. **Transactional class**, so they reach contacts who opted out of
marketing — which is exactly why they're locked down (C6).

## Rules

- Audience: contacts with a contract in `active` / `notice_given` at `site_ids`
  (required, ≥ 1) ∩ optional segment. No subscription list.
- Template purpose: `announcement` only. Publishing an `announcement` template
  requires `AnnouncementSend` (prevents marketers from relabelling promos).
- No discount / promo attachment (S32 discount field must reject this type).
- `SendClass::Transactional`; marketing suppressions ignored, `all` suppressions
  (hard bounce, complaint, STOP for SMS → see below) still block.
- SMS + STOP: a person who texted STOP has an `all`-scope SMS suppression today —
  keep it blocking. Document that announcements won't reach them by SMS; the UI shows
  that count in the preview ("12 tenants can't receive SMS").
- No marketing footer; a short service footer with site name and phone instead.
- No approval by default; optional setting `announcement_approval_required`.
- `ignore_send_window = true` allowed (urgent: "gate broken now"); requires a
  confirmation checkbox in the panel and is logged in the activity props.
- Not counted toward the marketing frequency cap.

## Abuse guard

Activity `campaign.announcement_sent` on each site with the template version and
recipient count, visible in the site's activity feed — so a promotion sent as an
"announcement" is visible to every manager of that site.

## Acceptance criteria

- [ ] Announcement reaches a tenant with only a marketing suppression; not a
      hard-bounced address.
- [ ] Template with purpose `marketing` rejected for an announcement.
- [ ] Site-scoped manager can only choose their own sites.
- [ ] `ignore_send_window` sends at 22:30 site time; otherwise deferred.
