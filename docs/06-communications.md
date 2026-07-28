# Communications & Activity Timeline

## ContactChannel

Multi-channel contact identity: email, phone, WhatsApp.

- Fields: `contact_id`, `type`, `value`, `label`, `is_primary`.
- Partial unique index: **one primary channel per type per contact**.

## Interaction — the unified CRM timeline

Model name is `Interaction` (chosen over `CommunicationLog` / `Communication`). Logs all general contact activity — calls, emails, SMS, WhatsApp.

| Field | Notes |
|---|---|
| `contact_id` | required |
| `deal_id` | optional |
| `channel` | email / sms / whatsapp / call / … |
| `direction` | inbound / outbound |
| `occurred_at` | timestamp |
| `content` / `summary` | body or summary |
| `metadata` | JSONB for channel-specific data (e.g. call duration) |

## OfferDelivery vs Interaction

`OfferDelivery` stays a **separate, specialised delivery-receipt table** (per-send status tracking). When an offer is sent, an **Interaction row is also written** so the CRM timeline stays unified. Do not merge these tables.

## Related surfaces

- Email / SMS / WhatsApp templates (marketing module); campaigns.
- Automations (top-level module — event/schedule workflows across leasing, billing, and facility).
- Inbox + AI copilot conversations (`agent conversations`, Laravel AI SDK).

## Provider credentials (`communication_accounts`, `site_sender_identities`)

Two tables split "who can send" (credential) from "who it looks like it's from" (identity):

| Table | Holds | Scope |
|---|---|---|
| `communication_accounts` | Provider API key (`encrypted`), webhook registration state, connection status | `scope = company` (one per `provider_type`) or `scope = site` (one per `site_id` + `provider_type`) — site scope is modeled but ships no UI yet |
| `site_sender_identities` | From-name / from-email / from-number / reply-to — **no secret** | One row per `site_id` + `provider_type`, points at the `CommunicationAccount` that actually sends |

Providers: `brevo` (email/SMS) and `snich` (SMS/WhatsApp, adapter stub — no live SDK). Both implement `App\Support\Communications\Providers\CommunicationProvider` (`verifyCredentials`, `registerWebhook`, `removeWebhook`); resolved via `CommunicationProviderResolver`.

### Credential handling rules (shared with Stripe — see `05-billing-ledger.md`)

- Secrets are **never returned raw**. `api_key` casts as `encrypted`; the API always serializes a masked `••••••` + last 4 (`CommunicationAccountResource`).
- **Blank submitted field = unchanged.** `PUT /api/settings/communications/{providerType}` with an empty `api_key` leaves the stored key untouched — it never wipes a connected account.
- Create / rotate / remove are Tier-3 `RecordsActivity::core` events (`communication_account.created` / `.rotated` / `.removed`) with properties limited to `site_id`, `provider_type`, the masked last-4, and `result` — **never the secret itself**.
- A `DecryptException` on read (e.g. after an `APP_KEY` rotation) degrades to `credentials_unreadable: true` in the resource instead of a 500 — the panel prompts to re-enter the key.
- **Archived sites:** credentials on a site-scoped account are left in place; the site simply stops being used as a sender, and inbound webhooks for that site are ignored (see below). Nothing is deleted on archive.

### Webhooks

- `POST /api/webhooks/brevo/{webhook_url_token}` — public route (no Sanctum), authenticated only by the per-account URL token. Looks up the `CommunicationAccount`, ignores the event if it's site-scoped and the site `isArchived()`, records a Tier-1 `webhook.brevo.received` `SystemEvent`, and dispatches `ProcessBrevoWebhookEvent` (queued stub — matches by `message_id` and will map Brevo's `event` field onto `OfferDelivery.delivery_status` / `Interaction` once the event vocabulary is finalised) before acking fast.
- **Webhook creation is refused** if the API's public base URL is missing, `localhost`, or a private/loopback address — `App\Support\Http\PublicUrlGuard`. In local dev (`APP_URL=http://localhost`) this means "Create webhook" always fails until a real public `APP_URL` is configured; this is intentional, not a bug.
- Rotating/removing an account deletes the provider-side webhook endpoint first (`CommunicationProvider::removeWebhook`) before the local row is touched.

### `Interaction` / `OfferDelivery` provider linkage

Both tables gained nullable `message_id` (provider-assigned id, used to match inbound delivery events) and `account_id` (FK to `communication_accounts`, which credential/account actually sent it).

### Authorization gap

Site-level credential/identity routes call `App\Support\Auth\SiteAccess::canManageSite()`. Since `Employee` has no site-assignment table yet (`07-people-and-auth.md`), every authenticated employee is currently treated as company-level and can manage any site's integrations. This is a structural placeholder — once Employee↔Site assignment ships, only `SiteAccess` needs to change.
