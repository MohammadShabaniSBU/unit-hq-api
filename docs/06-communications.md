# Communications & Activity Timeline

## ContactChannel

Multi-channel contact identity: email, phone, WhatsApp.

- Fields: `contact_id`, `type`, `value`, `label`, `is_primary`.
- Partial unique index: **one primary channel per type per contact**.

## Message store — canonical communication record

Bodies, attachments, threading, and delivery state live on `message_threads` /
`messages` / `message_attachments`. Every outbound send and every inbound receipt
creates exactly one `messages` row. See invariant 38 in `09`.

| Piece | Role |
|---|---|
| `message_threads` | One conversation per contact+channel (email subject reuse; SMS/call/WhatsApp by `channel_key`) |
| `messages` | Direction, status, bodies (HTML sanitized at write), provider ids, `source` / `source_ref` |
| `message_attachments` | Private-disk files linked to a message |
| `Threading::forOutbound` / `forInbound` | Outbound subject/number reuse; inbound References → subject → new (email) or `(contact, number)` (SMS/call/WhatsApp) |
| `SendContext` | Callers pass provenance (`manual` / `offer` / `playbook` / `automation` / `system`) **and required `class`** (`transactional` / `marketing`) into `EmailSender` / `SmsSender` / `WhatsAppSender` |
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
| `Channel` | `email`, `sms`, `whatsapp`, `call` — all four are `isImplemented()` (call receive-only) |
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

Mirrors site-over-org preference:

1. Site-scoped active account for this channel (when a site is given)
2. Else company-scoped active account
3. Else `ChannelNotConfigured`
4. Archived site → `ChannelNotConfigured::siteArchived()` (credentials kept, sending refused)

Orchestration lives in `App\Support\Communications\Senders\EmailSender` / `SmsSender` / `WhatsAppSender` (same tier as `ContractBilling` — **no** `app/Services/`). WhatsApp session sends refuse locally when the 24h window is closed; template sends skip the window but require a live `approved` registry row and a `whatsapp` contact channel (phone alone is not consent).

### WhatsApp template registry (S13-03)

Local `whatsapp_templates` rows are the operator-facing registry; Meta's approval state is authoritative and synced through the provider:

| Status | Meaning |
|---|---|
| `draft` / `rejected` | Editable; Submit calls `ManagesWhatsAppTemplates::submit` with samples |
| `submitted` | Awaiting Meta; content locked |
| `approved` | Content immutable (Meta rule); Clone → new draft (`{name}_v2`); sendable |
| `revoked` | Meta pulled approval; send refuses (`template_not_approved`) |
| `archived` | Hidden from pickers; frees the partial unique `(account, name, language)` identity |

- **Sync:** hourly `whatsapp:sync-templates` poll is authoritative; webhook events (`parseTemplateStatusEvents`) are latency. Rejection reasons are stored **verbatim**.
- **Locale ladder** (`WhatsAppTemplateResolver`): among approved rows of a `name`, pick contact.locale → site locale → `en` → any approved. `WhatsAppSender::sendResolvedTemplate` logs `{preferred, chosen, fallback}` on `messages.detail.whatsapp_template.resolution`.
- **Auth API:** `GET/POST /api/whatsapp-templates`, `GET/PUT …/{id}`, `POST …/{id}/submit|clone|archive`, `POST /api/whatsapp-templates/sync`. Panel: Marketing → Templates → WhatsApp.
- **Inbox composer (S13-04):** open window → free-form session reply + countdown from `closes_at`; closed → approved template picker, variable fill (token defaults pre-resolved), phone preview, then `sendResolvedTemplate`. Compose-context returns `whatsapp_window`, `whatsapp_consent`, and approved templates with `resolved_variables`.
- **Playbook `send_whatsapp_template`:** params `{whatsapp_template_name, variable_tokens}`. Resolve-by-name at send time. Skip-with-reason (run succeeds): `no_channel`, `template_not_approved`, `suppressed`. Category gates at save and send — debt=`utility` only; lead=`marketing|utility`. Template outbound does **not** open the 24h window (only inbound does); free-form follow-up after a tenant reply happens in the inbox, not inside the enrolment.
- **SMS templates:** `template_family_id` XOR inline body on inbox reply, `POST /inbox/compose`, and playbook `send_sms`; segment count runs on the resolved body.

### Normalised delivery vocabulary

`DeliveryStatus`: `queued`, `sent`, `delivered`, `opened`, `clicked`, `read`, `bounced`, `failed`, `spam`, `unsubscribed`. Not every channel emits every value (`opened`/`clicked` are email-only; `read` is WhatsApp-only). Vendors' raw status strings are preserved on `DeliveryEvent::$rawStatus` for debugging.

## Provider credentials (`communication_accounts`, `site_sender_identities`)

| Table | Holds | Scope |
|---|---|---|
| `communication_accounts` | Encrypted `credentials` JSON (shape varies by provider), webhook registration state, `is_active`, connection status | `scope = company` or `scope = site` — site scope is modeled but ships no UI yet |
| `site_sender_identities` | From-name / from-email / from-number / reply-to — **no secret**; keyed by `channel` | One row per `site_id` + `channel`. `provider_sender_id` is nulled when the site's active provider for that channel changes |

Indexes (company path): unique `(scope, site_id, channel, provider)`; partial unique one active company account per channel; one active site account per `(site_id, channel)`.

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
- **Inbound job** matches sender against `contact_channels` (normalised email / E.164-ish phone). Match → sanitize HTML, store attachments (caps → `oversize` stubs), thread via `Threading::forInbound`, create `messages` (`direction=inbound`, `status=received`) + `Interaction`, bump `unread_count` / `last_inbound_at` (skipped when `auto_generated` from Auto-Submitted/X-Autoreply), fire `InboundMessageReceived`. SMS body matching `communications.stop_keywords` → sms scope `all`. No match → `comms_triage` row (never silent Contact create).
- **Suppression gate** lives in `EmailSender` / `SmsSender` / `WhatsAppSender`: active `all` blocks both classes; `marketing` blocks marketing only (dunning still sends). Blocked attempts record `messages.status=failed` + `detail.suppressed_reason`. Marketing emails get `List-Unsubscribe` (+ one-click POST) at `POST/GET /api/comms/unsubscribe/{token}`.
- **Triage queue (auth):** `GET /api/comms-triage` (pending cursor list), `GET /api/comms-triage/{id}` (sanitized body). Resolve: `POST …/attach` `{contact_id}`, `…/create-and-attach` `{first_name?, last_name?}`, `…/discard` `{reason?}` — audits `triage.resolved` (`how`) / `triage.discarded` (optional reason in properties; no reason column). Resolve responses include `message_thread_id` for Inbox navigation.
- **Rethread (auth):** `POST /api/messages/{id}/move-thread` with `{message_thread_id}` **or** `{new_thread: true}` (email only). Audit `message.rethreaded`. Picker data: `GET /api/inbox/threads/{id}/move-targets`.
- **Inbox surface (auth):** `GET /api/inbox/threads` (+ filters / `updated_after` deltas), thread detail, reply/compose, `GET /api/inbox/badge` → `{unread_threads, triage_count}`, `POST …/read` (zero unread), `POST …/unread` (set unread to 1 — thread-level model), assign. Panel polls badge every 20s for nav count, triage indicator, document title, and favicon dot.
- **Aircall dialing (auth, S12-00):** Settings maps employees ↔ Aircall users (`GET/POST …/settings/communications/call/aircall/users`, `PUT/DELETE …/{aircallUserId}`). `POST /api/calls/dial` records a `call_intents` row and calls `POST /v1/users/:id/dial` — it never creates a `messages` row (no-optimism). Outbound `call.created` webhooks correlate exact call id or heuristic (same mapped user + number within 2 minutes) and stamp `source_ref.call_intent`. Unmatched intents age to `uncorrelated` after 10 minutes (`comms:sweep-uncorrelated-call-intents`). `GET /api/calls/availability` (60s cache) is the enable/disable truth for call buttons.
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
