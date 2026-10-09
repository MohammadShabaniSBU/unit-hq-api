# S31-02 — Dispatch pipeline

## Overview

```
scheduler (every minute) ─▶ DispatchDueCampaignSends
      └─▶ MaterialiseCampaignRecipients (per send, one job)
             └─▶ SendCampaignBatch (chunks of 100, rate-limited)  ──▶ EmailSender / SmsSender
                    └─▶ CompleteCampaignSend (when no pending/claimed left)
```

Queue: dedicated `campaigns` queue so a big send never delays transactional jobs
(dunning, offers, webhooks). Add a Horizon/worker entry.

## 1. Dispatch

`DispatchDueCampaignSends` (scheduled every minute, `withoutOverlapping`): sends with
`status = scheduled AND scheduled_at <= now()` whose campaign is `scheduled` or
`sending` → set send `materialising`, campaign `sending` (via `CampaignLifecycle`),
queue materialisation.

## 2. Materialisation

One `INSERT … SELECT … ON CONFLICT (campaign_send_id, contact_id) DO NOTHING` per
chunk of contact ids, built from `audience_snapshot`:

| Type | Base set |
|---|---|
| campaign / newsletter | `ConsentState::grantedQuery(channel, list_id)` |
| announcement | contacts with a contract in `active` / `notice_given` at `site_ids` |

∩ segment filter tree (`FilterBuilder::for('contact')->apply`) ∩ site narrowing ∩
`visibleTo(creator)` for site-scoped creators. `address` = primary usable channel
address; `site_id` = contact business site. Rows with no address are inserted as
`skipped / no_address` so the report is complete.

Then send → `sending`, `materialised_at = now()`, queue batches.

Materialisation is re-runnable (idempotent on the unique key) — a crashed job just
runs again.

## 3. Sending a batch

For each recipient in the batch:

1. **Claim**: `UPDATE campaign_recipients SET status='claimed', claimed_at=now(),
   attempts=attempts+1 WHERE id=? AND status='pending'` — 0 rows → skip (someone else
   has it). Stale claims (> 15 min) are returned to `pending` by the completion
   sweeper.
2. **Campaign state**: if campaign is `paused` / `cancelled` → release claim, stop the
   batch.
3. **Gates** (C5), each producing a `skip_reason`:
   - consent still granted (campaign/newsletter) — `no_consent`
   - exclusions from snapshot: open delinquency → `excluded_delinquent`; active run of
     an excluded playbook kind → `excluded_playbook`
   - frequency cap (marketing only): count of `campaign_recipients` sent to this
     contact in the window ≥ cap → `frequency_capped`
4. **Send window**: if the recipient's site local time (`SiteClock`) is outside the org
   send window and not `ignore_send_window` → release claim and re-queue the batch with
   a delay to the next window opening. (Never skip for window — only defer.)
5. **Render**: `TemplateResolver::variantOf($send->templateVersion, $contact, $site)`
   (pinned) + `TemplateProvenance`; marketing footer appended (S30-06).
6. **Send** with `SendContext::campaign($recipient, class)`; the sender's suppression
   check may return a suppressed result → `skipped / suppressed`.
7. Persist `status = sent | skipped | failed`, `message_id`, `processed_at`.
   Provider errors: retry up to 3 attempts with backoff, then `failed` + `error`.

## 4. Throttling

`RateLimited` job middleware keyed per `communication_account_id`, limit from a new
optional `credentials.rate_limit_per_minute` (defaults: email 600/min, SMS 60/min).
Batch size 100.

## 5. Completion

`CompleteCampaignSend` (scheduled every minute + after each batch): when a send has no
`pending`/`claimed` rows → `completed`; when all sends completed → campaign `sent`,
activity `campaign.completed` with counts by status/skip reason.

## Pause / resume / cancel

- Pause: campaign `paused`; batches stop at the next recipient; nothing is lost.
- Resume: campaign `sending`; re-queue batches for `pending` rows.
- Cancel: campaign `cancelled`; remaining `pending` → `skipped / cancelled`
  (add `cancelled` to skip reasons).

## Acceptance criteria

- [ ] Killing a worker mid-batch then rerunning sends each recipient exactly once.
- [ ] Contact who withdraws consent after scheduling is skipped `no_consent`.
- [ ] Frequency cap: third marketing campaign within 7 days is skipped for that contact.
- [ ] Recipients in a site whose local time is 23:00 are deferred to 09:00, not skipped.
- [ ] Transactional queue latency unaffected during a 20k-recipient send (load test note).
- [ ] Every sent message has `source = campaign`, `source_ref` and `detail.template`.
