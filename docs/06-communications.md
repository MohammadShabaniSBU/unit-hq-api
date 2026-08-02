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
| `message_threads` | One conversation per contact+channel (email subject reuse; SMS/call by `channel_key`) |
| `messages` | Direction, status, bodies (HTML sanitized at write), provider ids, `source` / `source_ref` |
| `message_attachments` | Private-disk files linked to a message |
| `Threading::forOutbound` / `forInbound` | Outbound subject/number reuse; inbound References → subject → new (email) or `(contact, number)` (SMS) |
| `SendContext` | Callers pass provenance (`manual` / `offer` / `playbook` / `automation` / `system`) **and required `class`** (`transactional` / `marketing`) into `EmailSender` / `SmsSender` |
| `channel_suppressions` | Address-keyed pre-send gate (`all` / `marketing` scope). Writers: hard bounce, complaint, SMS STOP, unsubscribe, manual. Enforced only in senders. |
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
| `Channel` | `email`, `sms`, `whatsapp`, `call` — email/sms/call are `isImplemented()`; WhatsApp later |
| `Provider` | `brevo`, `postmark`, `mandrill`, `twilio`, `sinch`, `aircall` |
| `AccountScope` | `company`, `site` |

Registered adapters today: Brevo + Postmark (email), Twilio + Sinch (SMS), Aircall (call — inbound lifecycle webhooks + click-to-dial). Mandrill remains commented. WhatsApp is additive later.

### Capability-by-interface

Adapters do **not** expose a `capabilities()` boolean map. Capability is interface presence:

| Interface | Purpose |
|---|---|
| `ProviderAccount` | `make`, `verify`, `credentialFields`, `channels` |
| `SendsEmail` / `SendsSms` | outbound send |
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

Orchestration lives in `App\Support\Communications\Senders\EmailSender` / `SmsSender` (same tier as `ContractBilling` — **no** `app/Services/`).

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
- **Suppression gate** lives only in `EmailSender` / `SmsSender`: active `all` blocks both classes; `marketing` blocks marketing only (dunning still sends). Blocked attempts record `messages.status=failed` + `detail.suppressed_reason`. Marketing emails get `List-Unsubscribe` (+ one-click POST) at `POST/GET /api/comms/unsubscribe/{token}`.
- **Triage queue (auth):** `GET /api/comms-triage` (pending cursor list), `GET /api/comms-triage/{id}` (sanitized body). Resolve: `POST …/attach` `{contact_id}`, `…/create-and-attach` `{first_name?, last_name?}`, `…/discard` `{reason?}` — audits `triage.resolved` (`how`) / `triage.discarded` (optional reason in properties; no reason column). Resolve responses include `message_thread_id` for Inbox navigation.
- **Rethread (auth):** `POST /api/messages/{id}/move-thread` with `{message_thread_id}` **or** `{new_thread: true}` (email only). Audit `message.rethreaded`. Picker data: `GET /api/inbox/threads/{id}/move-targets`.
- **Inbox surface (auth):** `GET /api/inbox/threads` (+ filters / `updated_after` deltas), thread detail, reply/compose, `GET /api/inbox/badge` → `{unread_threads, triage_count}`, `POST …/read` (zero unread), `POST …/unread` (set unread to 1 — thread-level model), assign. Panel polls badge every 20s for nav count, triage indicator, document title, and favicon dot.
- **Aircall dialing (auth, S12-00):** Settings maps employees ↔ Aircall users (`GET/POST …/settings/communications/call/aircall/users`, `PUT/DELETE …/{aircallUserId}`). `POST /api/calls/dial` records a `call_intents` row and calls `POST /v1/users/:id/dial` — it never creates a `messages` row (no-optimism). Outbound `call.created` webhooks correlate exact call id or heuristic (same mapped user + number within 2 minutes) and stamp `source_ref.call_intent`. Unmatched intents age to `uncorrelated` after 10 minutes (`comms:sweep-uncorrelated-call-intents`). `GET /api/calls/availability` (60s cache) is the enable/disable truth for call buttons.
- **Call surfaces (auth, S12-01):** Inbox call-back (`POST /api/inbox/threads/{id}/reply` on call channels) dials with `context=thread`. `GET /api/inbox/badge` includes `active_calls` (ringing/ongoing from call messages + pending call triage). Delinquency case `timeline` interleaves correlated call messages with `source_ref.call_intent.context_type=delinquency`.
- **Webhook creation is refused** if the configured public base URL (`communications.public_base_url` / `APP_URL`) is missing, `localhost`, or a private/loopback address — `App\Support\Http\PublicUrlGuard`.
- Removing a provider deletes the remote webhook endpoint first (when `AutoRegistersWebhooks`) via stored `webhook_endpoint_id`.

### Authorization gap

Site-level identity routes call `App\Support\Auth\SiteAccess::canManageSite()`. Since `Employee` has no site-assignment table yet (`07-people-and-auth.md`), every authenticated employee is currently treated as company-level and can manage any site's integrations. This is a structural placeholder — once Employee↔Site assignment ships, only `SiteAccess` needs to change.
