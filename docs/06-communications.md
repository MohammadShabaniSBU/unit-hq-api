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

- Email templates + builder, automations/campaigns (marketing module).
- Inbox + AI copilot conversations (`agent conversations`, Laravel AI SDK).
