# S31-06 — Panel

## Navigation (`app/config/navigation.ts`)

```
MARKETING
  Campaigns          /marketing/campaigns           (replaces the placeholder campaigns.vue)
  Newsletters        /marketing/newsletters
  Announcements      /marketing/announcements
  Audience
    Lists            /marketing/audience/lists       (S30)
    Segments         /marketing/audience/segments    (S30)
    Forms            /marketing/audience/forms       (S30)
  Templates          (existing: Email, SMS, WhatsApp, Documents)
```

Approvals: a filter "Awaiting my approval" on the campaigns list plus a badge count
on the Campaigns nav item for `CampaignApprove` holders. (Kept separate from Agent
approvals, which are for AI actions.)

## Campaign wizard (shared by all three types, steps adapt)

1. **Setup** — name; type is fixed by the entry point.
2. **Audience** — list (campaign/newsletter) or sites (announcement); optional segment
   (pick saved or build inline → "save as segment"); live preview card: count,
   estimated skips (no consent / suppressed / capped / no address), sample contacts.
3. **Content** — per channel toggle (Email, SMS): template picker filtered by purpose
   and `sendable`, shows published version number; subject override; desktop/mobile
   preview rendered **as a sample contact** with the footer; SMS segment counter.
4. **Schedule** — now / date-time in site timezone (show "recipients in other
   timezones receive at their 09:00" note); announcement: urgent toggle.
5. **Review** — summary, test-send (to me / up to 5 colleagues), submit / schedule.
   Shows "Requires approval because…" when applicable.

## Campaign detail

- Header: status chip, type, version pinned ("Spring promo email · v4"), actions
  (pause/resume/cancel/duplicate, approve/reject for approvers).
- Progress bar while sending (polling every 5 s, or Reverb broadcast if cheap).
- Report tab: metric tiles, skip-reason breakdown, delivery funnel chart.
- Recipients tab: table with status/skip-reason filters, link to contact & message.
- Activity tab.

## Lists

Campaigns / Announcements tables: name, status, audience, scheduled/sent at,
recipients, open rate. Newsletters: card per list → issues table + "New issue".

## i18n

All strings in `en`, `es`, `fr`; skip reasons and statuses via enum label keys.

## Acceptance criteria

- [ ] Wizard blocks submit with inline errors from `CampaignScheduleValidator`.
- [ ] Preview count matches the materialised recipient count for an unchanged audience.
- [ ] Approver sees the pinned version and can open its read-only preview.
- [ ] Site-scoped user sees only their sites in the sites picker.
