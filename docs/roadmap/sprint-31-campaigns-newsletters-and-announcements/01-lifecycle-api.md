# S31-01 — Campaign lifecycle API

## State machine

```
draft ──submit──▶ pending_approval ──approve──▶ scheduled ──(dispatcher)──▶ sending ──▶ sent
  │                    │ reject → draft                │ cancel → cancelled     │ pause ⇄ resume
  └── schedule (no approval needed) ──────────────────▶┘                        └ cancel → cancelled
```

All transitions in `CampaignLifecycle` (one class, `SELECT … FOR UPDATE` on the
campaign row, activity per transition). Controllers never set `status` directly.

## Endpoints

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `campaigns?type=&status=` | `CampaignView` | visibleTo: site-scoped users see campaigns whose sites ⊆ their grants |
| `POST` | `campaigns` | `CampaignManage` / `AnnouncementSend` | create draft |
| `PUT` | `campaigns/{c}` | same | draft only |
| `PUT` | `campaigns/{c}/sends/{channel}` | same | `{template_family_id, subject_override?, scheduled_at}`; family purpose must match type allowlist; family must be sendable |
| `DELETE` | `campaigns/{c}/sends/{channel}` | same | draft only |
| `POST` | `campaigns/{c}/audience-preview` | `CampaignView` | live `{count, by_skip_reason_estimate, sample}` using the same query as materialisation |
| `POST` | `campaigns/{c}/test-send` | `CampaignManage` | `{channel, to: [employee emails/phones] ≤ 5, as_contact_id?}`; renders the **current published** version; message `source = campaign`, `detail.test = true`; never creates recipients |
| `POST` | `campaigns/{c}/submit` | `CampaignManage` | → `pending_approval` if approval required, else → `scheduled` |
| `POST` | `campaigns/{c}/approve` | `CampaignApprove` | approver ≠ submitter (unless sole admin setting) |
| `POST` | `campaigns/{c}/reject` | `CampaignApprove` | `{reason}` → draft |
| `POST` | `campaigns/{c}/pause` · `resume` · `cancel` | `CampaignManage` | |
| `POST` | `campaigns/{c}/duplicate` | `CampaignManage` | new draft, `duplicated_from_id`, sends copied without pinned version |

## Submit / schedule validation (`CampaignScheduleValidator`)

1. ≥ 1 send; each send has `scheduled_at` ≥ now + 2 min (or "now").
2. Template family sendable, purpose allowed for type, channel matches send.
3. **Pin** `template_version_id` = family's current published version.
4. Provider: a marketing-eligible account exists for every site in the audience
   (`ProviderResolver` with `SendClass::Marketing`; announcements: transactional).
5. List allows the send's channel (`subscription_lists.channels`).
6. Freeze `audience_snapshot`: list id, the segment's **filter tree copy** (not just
   id), site_ids, exclusion settings and frequency-cap settings from S30-06.
7. `estimated_recipients` from the preview query; `approval_required` =
   type ≠ announcement AND (estimate > `marketing_approval_threshold` OR submitter
   lacks `CampaignApprove`).
8. SMS: rendered sample incl. footer → segment count returned as a warning if > 2.

Approve re-runs 2–5 (template could have been republished — re-pin and show the
approver the version they approve).

## Activity

Tier-2 on the campaign (`LogChannel::Comms`): `campaign.created`, `.submitted`,
`.approved`, `.rejected`, `.scheduled`, `.paused`, `.resumed`, `.cancelled`,
`.completed`. Props: ids, counts, version numbers — keys only.

## Acceptance criteria

- [ ] Every illegal transition → 422 with the current status.
- [ ] Submit pins the version; republishing the template afterwards doesn't change it.
- [ ] Approve by submitter refused; reject returns to draft with reason visible.
- [ ] Site-scoped marketer can't include a site outside their grants.
- [ ] Test send never writes `campaign_recipients` and is marked `detail.test`.
