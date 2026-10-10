# Communications & Activity Timeline

## ContactChannel

Multi-channel contact identity: email, phone, WhatsApp.

- Fields: `contact_id`, `type`, `value`, `label`, `is_primary`, `opted_in`.
- Partial unique index: **one primary channel per type per contact**.
- `opted_in` is not marketing consent. Triage and AI set it without evidence. Marketing reads the append-only `consent_events` ledger (`ConsentState`); current state is the latest event per (contact, channel, subscription list).

## Marketing consent (S30)

### Consent ledger

`consent_texts` stores the exact wording shown to a person (`key`, `version`, `locale`, `body`). Rows are immutable: no `updated_at`; a trigger rejects UPDATE and DELETE. A new wording is a new version.

`consent_events` is the append-only ledger (invariant 75). A row names the contact, channel (`email` | `sms` | `whatsapp`), subscription list, action (`granted` | `pending` | `withdrawn`), source, optional `consent_text_id`, `evidence` JSON, causer, and `occurred_at`. A trigger rejects DELETE and any UPDATE except nulling `evidence`. Current consent is not a column: `ConsentState` reads the latest event per (contact, channel, list), tie-broken by `id`. Marketing never reads `contact_channels.opted_in`.

`ConsentWriter` is the only insert path (invariant 76). `grant()` accepts `form`, `double_opt_in`, `operator` (employee causer and a note), `import` (`import_batch` and `original_source`), `preference_center`, and `soft_opt_in` (default off). Every grant except `operator` requires `consent_text_id`. Automations, AI tools, and inbound triage may call `withdraw()` only. `contacts:redact` nulls `evidence` on existing rows and appends a `withdrawn` event with `source = redaction`. The original grant row stays `granted`.

### Subscription lists

No membership table. A member of a list on a channel is a contact whose latest event on that pair is `granted` (`ConsentState::grantedQuery`). Seeded system lists: `promotions` (email and SMS) and `newsletter` (email). Lists are archive-only (`archived_at`); `key` never changes. System lists cannot be archived. Consent events reference lists forever.

### Preference tokens

`PreferenceToken` is an HMAC public token (no expiry) for the preference center and RFC 8058 one-click. `GET /api/comms/preferences/{token}` renders the list × channel grid and writes nothing. `POST` applies changes with `source = preference_center`; `unsubscribe_all` also writes the marketing suppression. `GET /api/comms/unsubscribe/{token}` redirects to the preference page and writes nothing (invariant 77). `POST` is one-click: a token that names a list withdraws that list (`source = one_click`); a token that names none (legacy `UnsubscribeToken`) withdraws every list for every contact on that address.

## Message store — canonical communication record

Bodies, attachments, threading, and delivery state live on `message_threads` /
`messages` / `message_attachments`. Every outbound send and every inbound receipt
creates exactly one `messages` row. See invariant 38 in `09`.

| Piece | Role |
|---|---|
| `message_threads` | One conversation per contact+channel (email subject reuse; SMS/call/WhatsApp by `channel_key`) |
| `messages` | Direction, status, bodies (HTML sanitized at write), provider ids, `source` / `source_ref` |
| `messages.detail.template` | Templated outbound only: `{family_id, version_id, version_number, variant_id, locale, preferred_locale}`. Inline sends write nothing (invariant 74) |
| `message_attachments` | Private-disk files linked to a message |
| `Threading::forOutbound` / `forInbound` | Outbound subject/number reuse; inbound References → subject → new (email) or `(contact, number)` (SMS/call/WhatsApp) |
| `SendContext` | Callers pass provenance (`manual` / `offer` / `playbook` / `automation` / `system` / `ai_agent`) **and required `class`** (`transactional` / `marketing`) into `EmailSender` / `SmsSender` / `WhatsAppSender`. Agent replies go through `App\Support\Ai\AgentSend` with `source = ai_agent` (invariant 69) — never a direct `messages` insert |
| `WhatsAppWindow` | Computed 24h customer-service window from `last_inbound_at` (never stored). Inbox WA threads expose `whatsapp_window: {open, closes_at}\|null` |
| `whatsapp_templates` | Provider-synced approval registry (live `status`); sendTemplate refuses non-`approved`. Manager + sync (S13-03) |
| `channel_suppressions` | Address-keyed pre-send gate (`all` / `marketing` scope). Writers: hard bounce, complaint, SMS/WhatsApp STOP (+ Meta opt-out), unsubscribe, manual. Enforced in `EmailSender` / `SmsSender` / `WhatsAppSender`. |
| `comms_triage` | Unmatched inbound parking lot — attach / create-and-attach / discard; never silent Contact create |

## Interaction — CRM timeline index

Model name is `Interaction` (chosen over `CommunicationLog` / `Communication`). It remains the lightweight timeline the CRM UI reads. Sends still write an Interaction, but the body of truth is the linked `messages` row (`interactions.message_id`).

| Field | Notes |
|---|---|
| `contact_id` | required |
| `deal_id` | optional |
| `channel` | email / sms / whatsapp / call / … |
| `direction` | inbound / outbound |
| `occurred_at` | timestamp |
| `content` / `summary` | denormalised preview for the timeline (canonical body on `messages`) |
| `metadata` | JSONB for channel-specific data (e.g. call duration) |
| `provider_message_id` | nullable; provider-assigned id for delivery reconciliation |
| `communication_account_id` | nullable FK; which credential actually sent it |
| `message_id` | nullable FK → `messages` (null only for pre-store / non-message timeline entries) |

## OfferDelivery vs Interaction

`OfferDelivery` stays a **separate, specialised delivery-receipt table** (per-send status tracking). When an offer is sent, an **Interaction row is also written** so the CRM timeline stays unified, and both point at the same `messages` row via `message_id`. Do not merge these tables. Both also carry `provider_message_id` + `communication_account_id` so a later provider switch does not break webhook reconciliation for old messages.

## Channel × provider

Each **channel** (email, SMS, WhatsApp, call) can have several **providers** configured. `is_active` selects the live vendor for that channel without destroying the others' credentials — an operator can keep Postmark configured while Brevo is live and switch with one click.

| Enum | Values (this slice) |
|---|---|
| `Channel` | `email`, `sms`, `whatsapp`, `call` — all four are `isImplemented()` (call receive-only). `webchat` is S26-07c and is not a case yet |
| `Provider` | `brevo`, `postmark`, `mandrill`, `twilio`, `sinch`, `aircall` |
| `AccountScope` | `company`, `site` |

Registered adapters today: Brevo + Postmark (email), Twilio + Sinch (SMS), Sinch Conversation (WhatsApp), Aircall (call — inbound lifecycle webhooks + click-to-dial). Mandrill remains commented.

### Capability-by-interface

Adapters do **not** expose a `capabilities()` boolean map. Capability is interface presence:

| Interface | Purpose |
|---|---|
| `ProviderAccount` | `make`, `verify`, `credentialFields`, `channels` |
| `SendsEmail` / `SendsSms` / `SendsWhatsApp` | outbound send (`SendsWhatsApp`: session text + template) |
| `ManagesWhatsAppTemplates` | submit / fetch / list / parse template approval status (Sinch Provisioning API) |
| `AutoRegistersWebhooks` | create/delete endpoint resources over the vendor API |
| `ReportsDeliveryEvents` | parse delivery status callbacks into normalised `DeliveryEvent`s |
| `ReceivesInbound` | parse inbound content webhooks into normalised `InboundMessage`s (Postmark email, Twilio/Sinch SMS, Aircall calls; Brevo delivery-only) |

The panel renders "Create webhook" only when `auto_registers_webhooks` is true (derived from `instanceof AutoRegistersWebhooks`). Postmark and Twilio show a pasteable URL instead.

### Resolution order (`ProviderResolver`)

`resolve(Channel, ?Site, SendClass = Transactional)` — `SendContext->class` is passed by every sender. Purposes (`communication_accounts.purpose`: `all` | `transactional` | `marketing`, default `all`) are tried in order; within a purpose the site account wins over the company one:

1. Marketing send: active `marketing` account (site, then company), then `all`. **Never** `transactional`. None → `ChannelNotConfigured::forMarketing()` (S31 surfaces it at scheduling time, not at send).
2. Transactional send: active `transactional` account (site, then company), then `all`. Never `marketing`.
3. Else `ChannelNotConfigured`
4. Archived site → `ChannelNotConfigured::siteArchived()` (credentials kept, sending refused)

The marketing lane exists because Postmark forbids broadcast mail on a transactional stream and dunning/offer deliverability must not share reputation with campaigns (S30 M7). One active account per `(channel, purpose)`.

**Marketing footer (M8).** `EmailTemplateRenderer::render(..., class: SendClass::Marketing, site:, contact:, listId:)` always appends `MarketingFooter` (legal name, tax id, address from the site's legal entity; "why am I receiving this" with the list label; preferences + unsubscribe links). It is not a template block and cannot be removed; a marketing render without site and contact throws. SMS/WhatsApp marketing sends append one locale-aware line `"{trading_name}: baja {preferences_url}"` (counts toward the segment total). Transactional renders carry no footer. Lead-chase playbooks are marketing class and therefore carry it.

### Campaign engine (S31)

One pipeline for campaigns, newsletters, and announcements (`campaigns`, `campaign_sends`, `campaign_recipients`). Type changes the audience and the send class. Content stays on the pinned template version. The send has no content columns (invariant 80).

**Schedule.** Submit freezes `audience_snapshot` and pins `template_version_id` on each send. After that, audience fields and the pin do not change. A later publish of the family does not move the pin. Changing the audience or the content means cancel, then duplicate. Duplicate opens a draft with no pin.

**Dispatch.** `campaigns:dispatch-due` runs every minute (`withoutOverlapping`). A send with `status = scheduled` and `scheduled_at <= now()`, whose campaign is `scheduled` or `sending`, becomes `materialising`. `MaterialiseCampaignRecipients` inserts recipients. `SendCampaignBatch` sends them. `campaigns:complete-sends` marks a send `completed` when no row is `pending` or `claimed`, then the campaign `sent`.

**Queue.** Both jobs use the `campaigns` queue, so a broadcast does not sit in front of dunning, offers, or webhooks. The dev and prod supervisors run `queue:work --queue=campaigns`.

**Materialise.** One `INSERT … SELECT … ON CONFLICT (campaign_send_id, contact_id) DO NOTHING` per chunk of 500. Campaign and newsletter candidates are `ConsentState::grantedQuery` for the snapshotted list and channel, then the segment filter and site narrowing. An announcement's candidates are contacts with a contract in `active` or `notice_given` and an open occupancy at the snapshotted `site_ids`. A site-scoped creator is intersected with `Contact::visibleTo` (`ContactView`). A row with no address is inserted as `skipped / no_address`. Re-running a crashed job inserts nothing new (invariant 81).

**Claim.** `CampaignBatchSender` sets `status = claimed` only where `status = pending`. Zero rows means another worker holds the recipient, and this worker does not send. A stale claim older than 15 minutes returns to `pending`. Pause releases the claim and stops the batch. Cancel writes `skipped / cancelled` and stops.

**Gates at send time** (invariant 82). `CampaignSendGate` reads the frozen snapshot. The first match wins: consent still granted (`no_consent`) for a campaign or newsletter; an open delinquency (`excluded_delinquent`); an open run of an excluded playbook kind (`excluded_playbook`); the frequency cap (`frequency_capped`). An announcement skips the consent gate and the cap. The sender's suppression check records `suppressed`. A skipped row stores `skip_reason`. A provider failure retries up to three attempts, then `failed` with `error` and no skip reason.

**Send window.** When the recipient's site-local time (`SiteClock`) is outside the org window, the batch is deferred to the next opening. It is not skipped. `ignore_send_window` is announcement-only and is stored on the schedule activity.

**Throttle.** `SendCampaignBatch` uses the `campaign-account` rate limiter, keyed by `communication_account_id`. The limit is `credentials.rate_limit_per_minute` when that value is a positive number. Otherwise email is 600 per minute and SMS is 60 per minute. Batch size is 100.

**Footer and provenance.** Marketing class still appends `MarketingFooter` (invariant 78). An announcement appends `AnnouncementFooter` (site name and phone) and does not append the marketing footer. `SendContext::campaign($recipient, $class)` writes `source = campaign` and `source_ref = {campaign_id, campaign_send_id, campaign_recipient_id, subscription_list_id}`. `detail.template` records the pinned version (invariant 74).

**Statistics.** `GET /api/campaigns/{id}/report` derives sent, skipped by reason, failed, delivered, opened, bounced, complained, and unsubscribed from recipients, `messages.delivery_events`, and consent withdrawals (invariant 84). Nothing is stored on the campaign. While `sending`, the payload is cached for 60 seconds. A delivery webhook after completion changes the next read. Opens are approximate.

**Announcements.** An announcement sends as `SendClass::Transactional` to current tenants of the chosen sites. A marketing-scope suppression does not block it. An `all`-scope suppression still does: a hard bounce, a complaint, or an SMS STOP. A person who texted STOP does not receive the announcement by SMS; the audience preview reports that count as `sms_unreachable`. The message carries `AnnouncementFooter` (site name and phone) instead of the marketing footer. Announcement sends are not blocked by the frequency cap and do not count toward it. Approval stays off unless `announcement_approval_required` is on. `ignore_send_window` is recorded on the schedule activity. When the send completes, `campaign.announcement_sent` is written on each chosen site.

**Marketing settings.** `Setting::marketing()` holds the S31 defaults (frequency cap, exclude delinquent, excluded playbook kinds, approval threshold, `marketing_newsletter_bypasses_cap`, `announcement_approval_required`); `GET|PATCH /api/settings/marketing`, panel Settings → Marketing → Sending defaults.

**Newsletters.** A newsletter is a campaign with `type = newsletter` whose subscription list `kind` is `newsletter`. Submit assigns `newsletter_issue_number` as one more than the highest number already stored for that list, including cancelled issues, while the list row is locked. Cancelled issues keep their number, so the next issue does not reuse it and the published sequence can show a hole. Two submits at the same time receive consecutive integers: no shared number and no gap from the race. Rejecting an issue back to draft keeps the number; submitting it again does not take a new one. A draft has no number until submit. `marketing_newsletter_bypasses_cap` defaults to true: a sent newsletter still counts toward the frequency cap, and the newsletter itself is not blocked by the cap while the flag is on. The flag is frozen on the audience snapshot at submit. Templates may use `{{newsletter.issue_number}}` and `{{newsletter.name}}` (the list label for the contact locale, then `en`, then the first label, then the list key). `GET /api/subscription-lists/{list}/newsletters` returns issues with status, sent date, scheduled date, and recipient counts derived from `campaign_recipients`. `POST /api/subscription-lists/{list}/newsletters/next` copies the latest sent issue into a new draft: same list, segment, and template family, no schedule, no pinned version, and no issue number.

Orchestration lives in `App\Support\Communications\Senders\EmailSender` / `SmsSender` / `WhatsAppSender` (same tier as `ContractBilling` — **no** `app/Services/`). WhatsApp session sends refuse locally when the 24h window is closed; template sends skip the window but require a live `approved` registry row and a `whatsapp` contact channel (phone alone is not consent).

### Template families (S29)

Email, SMS, and contract documents share one stack. WhatsApp does not — it keeps the registry below.

```
template_families (identity) → template_versions (draft | published) → template_variants (locale content)
```

- At most one draft per family (partial unique index). Publish freezes that draft and it becomes the next version. Restore copies an older published version into a new draft; nothing is unpublished or reordered.
- "Current" is the highest published `version_number` (`TemplateFamily::currentVersion()`). It is not a stored pointer (invariant 74).
- Published versions and their variants are immutable: `PublishedTemplateImmutable` in Eloquent (`creating`, `updating`, `deleting`), and Postgres triggers `tver_reject_published_mutation` / `tv_reject_published_mutation` (`BEFORE INSERT OR UPDATE OR DELETE`). A variant cannot be inserted into a published version, and `template_version_id` / `template_family_id` never change. A `PUT` on the current published variant opens the next draft. A `PUT` while a draft already exists, a `PUT` on an older published version, and a `DELETE` of published content return 422.
- `template_variants.template_family_id` stays, denormalised and immutable, and must match its version's family (composite foreign key `tv_version_family_fk`).
- **Resolver.** `TemplateResolver::variant()` reads the latest published version and throws `TemplateNotPublished` when none exists. `TemplateResolver::variantOf()` is the pinned, preview, and test-send path. Both share the same locale ladder (contact locale → site country language → `en` → any variant).
- **Who follows, who pins.** Automations, playbooks, and inbox sends follow the latest published version. Contract documents and campaign sends store `template_version_id`. Either way, a templated outbound `messages` row records `detail.template`.
- A family with no published version is not sendable and is hidden from live pickers. Generating a contract document returns `errors.documents.template_not_published`. Activating an automation or playbook that names such a family is refused. At send time the step succeeds with `skipped_reason: template_not_published`.
- **Picker allowlists.** `TemplatePurpose::pickerAllowlist()` is what a purpose filter returns. Marketing returns `marketing` and `general`. Newsletter returns `newsletter` and `marketing`. Announcement returns `announcement` only. Debt and lead still include `general`; every other existing purpose matches itself. `CampaignType::templatePurpose()` selects the purpose a broadcast uses: a campaign uses marketing, a newsletter uses newsletter, an announcement uses announcement.

### WhatsApp template registry (S13-03)

Local `whatsapp_templates` rows are the operator-facing registry; Meta's approval state is authoritative and synced through the provider:

| Status | Meaning |
|---|---|
| `draft` / `rejected` | Editable; Submit calls `ManagesWhatsAppTemplates::submit` with samples |
| `submitted` | Awaiting Meta; content locked |
| `approved` | Content immutable (Meta rule); Clone → new draft (`{name}_v2`) with `supersedes_id` set to the source; sendable |
| `revoked` | Meta pulled approval; send refuses (`template_not_approved`) |
| `archived` | Hidden from pickers; frees the partial unique `(account, name, language)` identity |

- **Sync:** hourly `whatsapp:sync-templates` poll is authoritative; webhook events (`parseTemplateStatusEvents`) are latency. Rejection reasons are stored **verbatim**.
- **Locale ladder** (`WhatsAppTemplateResolver`): among approved rows of a `name`, pick contact.locale → site locale → `en` → any approved. `WhatsAppSender::sendResolvedTemplate` logs `{preferred, chosen, fallback}` on `messages.detail.whatsapp_template.resolution`.
- **Auth API:** `GET/POST /api/whatsapp-templates`, `GET/PUT …/{id}`, `POST …/{id}/submit|clone|archive`, `POST /api/whatsapp-templates/sync`. Panel: Marketing → Templates → WhatsApp.
- **Inbox composer (S13-04):** open window → free-form session reply + countdown from `closes_at`; closed → approved template picker, variable fill (token defaults pre-resolved), phone preview, then `sendResolvedTemplate`. Compose-context returns `whatsapp_window`, `whatsapp_consent`, and approved templates with `resolved_variables`.
- **Playbook `send_whatsapp_template`:** params `{whatsapp_template_name, variable_tokens}`. Resolve-by-name at send time. Skip-with-reason (run succeeds): `no_channel`, `template_not_approved`, `suppressed`. Category gates at save and send — debt=`utility` only; lead=`marketing|utility`. Template outbound does **not** open the 24h window (only inbound does); free-form follow-up after a tenant reply happens in the inbox, not inside the enrolment.
- **SMS templates:** `template_family_id` XOR inline body on inbox reply, `POST /inbox/compose`, and playbook `send_sms`; segment count runs on the resolved body. GSM-7 transliteration (`Gsm7Transliterator`) and the SMS segment ceiling (`warn_segments` / `max_segments`) apply to **agent drafts only** (`ChannelGuard` — `14-ai-agents.md`). Operator-authored SMS templates are unchanged.

### Normalised delivery vocabulary

`DeliveryStatus`: `queued`, `sent`, `delivered`, `opened`, `clicked`, `read`, `bounced`, `failed`, `spam`, `unsubscribed`. Not every channel emits every value (`opened`/`clicked` are email-only; `read` is WhatsApp-only). Vendors' raw status strings are preserved on `DeliveryEvent::$rawStatus` for debugging.

## Provider credentials (`communication_accounts`, `site_sender_identities`)

| Table | Holds | Scope |
|---|---|---|
| `communication_accounts` | Encrypted `credentials` JSON (shape varies by provider), webhook registration state, `is_active`, connection status | `scope = company` or `scope = site` — site scope is modeled but ships no UI yet |
| `site_sender_identities` | From-name / from-email / from-number / reply-to — **no secret**; keyed by `channel` | One row per `site_id` + `channel`. `provider_sender_id` is nulled when the site's active provider for that channel changes |

Indexes (company path): unique `(channel, provider, purpose)` per scope; partial unique one active company account per `(channel, purpose)`; one active site account per `(site_id, channel, purpose)`.

### Credential handling rules (shared with Stripe — see `05-billing-ledger.md`)

- Secrets are **never returned raw**. `credentials` casts as `encrypted:array`; the API serializes per-field masked `••••••` + last 4.
- **Blank submitted field = unchanged.** Never wipe a connected account.
- Create / rotate / remove are Tier-3 `RecordsActivity::core` events with properties limited to `site_id`, `provider`, `channel`, the masked last-4, and `result` — **never the secret itself**.
- A `DecryptException` on read degrades to `credentials_unreadable: true` instead of a 500.
- **Archived sites:** credentials left in place; sending refused; inbound webhooks for site-scoped accounts ignored.
- Credential rotation does **not** invalidate an existing webhook endpoint.

### Webhooks

- `POST /api/webhooks/{provider}/{webhook_url_token}` — public route (no Sanctum), authenticated by the per-account URL token. Looks up the account, ignores archived site-scoped accounts, records a Tier-1 `webhook.{provider}.received` `SystemEvent`, then splits by event class: `ReportsDeliveryEvents` → `ProcessDeliveryWebhookEvent`; `ReceivesInbound` → `ProcessInboundWebhookEvent`. Both persist on `comms_webhook_events` keyed by `(communication_account_id, provider_event_id)` (Stripe-shaped idempotency). Replay of an already-processed event is a no-op.
- `POST /api/webhooks/{provider}/{webhook_url_token}/inbound` — same controller; paste URL for Postmark inbound (separate from delivery webhooks). Twilio inbound MO shares the status-callback URL and is split by payload shape.
- **Delivery job** reconciles onto `messages` by `(provider, provider_message_id)`: appends `messages.delivery_events`, advances status **forward-only** via `DeliveryStatus::rank()`, touches the linked `Interaction`, back-fills playbook step `output` when `source = playbook`, legacy `OfferDelivery` for pre-store rows. Unmatched → `unmatched` + Tier-1 `comms.webhook.unmatched`. Hard bounce / complaint emit `ChannelDeliveryFailed` → `SuppressionWriter` (scope `all`). Provider `unsubscribed` → email scope `marketing`. Soft bounce writes nothing.
- **Inbound job** matches sender against `contact_channels` (normalised email / E.164-ish phone). Match → sanitize HTML, store attachments (caps → `oversize` stubs), thread via `Threading::forInbound`, create `messages` (`direction=inbound`, `status=received`) + `Interaction`, bump `unread_count` / `last_inbound_at` (skipped when `auto_generated` from Auto-Submitted/X-Autoreply), fire `InboundMessageReceived`. SMS body matching `communications.stop_keywords` → sms scope `all`. No match → `comms_triage` row (never silent Contact create). That match is a **routing** decision; a live `agent_channel_bindings` row (invariant 68) also turns it into the input to `RespondWithAgent`, which builds a `channel_asserted` principal and may run a turn (`14-ai-agents.md` Live channels). Auto-lead capture for unmatched + `audience = all` is S26-07b.
- **Suppression gate** lives in `EmailSender` / `SmsSender` / `WhatsAppSender`: active `all` blocks both classes; `marketing` blocks marketing only (dunning still sends). Blocked attempts record `messages.status=failed` + `detail.suppressed_reason`. Marketing emails get `List-Unsubscribe` (+ one-click POST) at `POST/GET /api/comms/unsubscribe/{token}`. GET does not change consent (invariant 77). One-click POST withdraws the token's list, or every list on that address when the token names none. See Marketing consent above. SMS STOP and an email complaint withdraw consent for that channel; a hard bounce does not.
- **Triage queue (auth):** `GET /api/comms-triage` (pending cursor list), `GET /api/comms-triage/{id}` (sanitized body). Resolve: `POST …/attach` `{contact_id}`, `…/create-and-attach` `{first_name?, last_name?}`, `…/discard` `{reason?}` — audits `triage.resolved` (`how`) / `triage.discarded` (optional reason in properties; no reason column). Resolve responses include `message_thread_id` for Inbox navigation.
- **Rethread (auth):** `POST /api/messages/{id}/move-thread` with `{message_thread_id}` **or** `{new_thread: true}` (email only). Audit `message.rethreaded`. Picker data: `GET /api/inbox/threads/{id}/move-targets`.
- **Inbox surface (auth):** `GET /api/inbox/threads` (+ filters / `updated_after` deltas), thread detail, reply/compose, `GET /api/inbox/badge` → `{unread_threads, triage_count, agent_drafts, agent_handoffs}`, `POST …/read` (zero unread), `POST …/unread` (set unread to 1 — thread-level model), assign, `POST …/agent/resume` (hand a human-owned thread back; does not trigger a turn). Badge writes ping `private-inbox` as `inbox.badge.updated`; the panel refetches `GET /api/inbox/badge` on that event (plus focus / reconnect) for nav count, triage indicator, document title, and favicon dot. Pending-action count uses the same pattern on `private-agent-pending-actions` / `agent.pending.badge.updated` → `GET /api/agent-pending-actions/badge`. Inbox draft-card UI is S26-08.
- **Aircall dialing (auth, S12-00):** Settings → Communication → Calls maps employees ↔ Aircall users (`GET/POST …/settings/communications/call/aircall/users`, `PUT/DELETE …/{aircallUserId}`; API paths unchanged). `POST /api/calls/dial` records a `call_intents` row and calls `POST /v1/users/:id/dial` — it never creates a `messages` row (no-optimism). Outbound `call.created` webhooks correlate exact call id or heuristic (same mapped user + number within 2 minutes) and stamp `source_ref.call_intent`. Unmatched intents age to `uncorrelated` after 10 minutes (`comms:sweep-uncorrelated-call-intents`). `GET /api/calls/availability` (60s cache) is the enable/disable truth for call buttons.
- **Call surfaces (auth, S12-01):** Inbox call-back (`POST /api/inbox/threads/{id}/reply` on call channels) dials with `context=thread`. `GET /api/inbox/badge` includes `active_calls` (ringing/ongoing from call messages + pending call triage). Delinquency case `timeline` interleaves correlated call messages with `source_ref.call_intent.context_type=delinquency`.
- **Call wrap-up (auth, S12-02):** `call_wrapups` (`message_id` unique, `disposition`, `note`, `employee_id`) — operator CRM annotation, one editable row. Disposition vocabulary is config `communications.call_dispositions` (machine keys; panel translates). `GET/PUT /api/messages/{id}/wrapup`; dismiss = upsert with null disposition. Badge `pending_wrapups` lists the current employee's recently ended correlated calls with no wrap-up row yet. Inbox message map includes `wrapup` + `has_recording` and **strips** signed `recording_url`/`voicemail_url` from client `source_ref`. Playback: `GET /api/messages/{id}/recording` fresh-fetches via Aircall `GET /v1/calls/{id}` and streams audio — never persists the signed URL; does not mark the thread read. Disposition also surfaces on delinquency timeline call entries and inbox context `recent`. GDPR: `contacts:redact` nulls wrap-up notes and sets `source_ref.recording_redacted` (see `config/redaction.php`).
- **Webhook creation is refused** if the configured public base URL (`communications.public_base_url` / `APP_URL`) is missing, `localhost`, or a private/loopback address — `App\Support\Http\PublicUrlGuard`.
- Removing a provider deletes the remote webhook endpoint first (when `AutoRegistersWebhooks`) via stored `webhook_endpoint_id`.

### Authorization

Site sender-identity routes (`GET/PUT /api/sites/{site}/sender-identities…`) and
company communication-account credential routes authorize with
`Permission::CredentialManage`. Site-bearing subjects resolve through
`SubjectSite` and the employee's grants (`employee_roles`); company-level
credential surfaces use the same permission without a site. There is no separate
`SiteAccess` helper — grants are the choke point (see `07-people-and-auth.md`).
