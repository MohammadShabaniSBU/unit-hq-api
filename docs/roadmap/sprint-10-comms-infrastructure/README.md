# Sprint 10 — Comms Infrastructure

## Goal

The plumbing under the Inbox: a **canonical message store** (threads, messages,
attachments) that every send already flows into, **inbound receipt** for email and SMS,
a **delivery-event pipeline** turning provider callbacks into message state, **consent
and suppression enforced pre-send**, and the two missing provider adapters (Sinch SMS,
Aircall calls). S11 then builds the Inbox as pure UI over these tables — if S11 needs a
migration, this sprint failed.

## The standing decision, honoured

From the first planning session: *"Don't extend `Interaction` — introduce
`message_threads` / `messages` / `message_attachments` as the canonical store, and make
the CRM timeline a read over it."* Concretely: `Interaction` survives as the lightweight
timeline index (every message still writes/updates one, `interactions.message_id` links
them); bodies, attachments, threading, read/delivery state live on messages. Nothing
consuming `Interaction` today breaks.

## Verified starting points

Channel/Provider enums, `communication_accounts` (+ per-account webhook tokens, credential
discipline), `site_sender_identities`, `ProviderResolver`, `EmailSender`/`SmsSender`,
Brevo + Postmark + Twilio adapters, capability interfaces incl. `ReportsDeliveryEvents`,
`ProcessDeliveryWebhookEvent` (dispatch stub), `DeliveryStatus` vocabulary, provenance
ids on `Interaction`/`OfferDelivery`/playbook step details — the join keys this sprint
was always going to need, stored since S09/earlier.

## Exit criteria

- [ ] Every outbound send (manual, offer, playbook) creates a message in a thread;
      delivery events flip its state; the CRM timeline is unchanged to the eye.
- [ ] An email reply and an SMS reply land as inbound messages threaded correctly, with
      attachments stored; unmatched senders queue for triage instead of vanishing.
- [ ] A bounced address or a STOP reply suppresses future sends **at the sender level** —
      a playbook step against a suppressed channel skips-with-reason.
- [ ] Sinch sends and receives SMS; Aircall calls appear as messages on the contact
      thread. All four providers configure from Settings with webhook state visible.
- [ ] Replaying any provider webhook is a no-op (idempotency parity with Stripe).

## Task order

| # | Task | Est. |
|---|---|---|
| 00 | [Message store & outbound rewiring](./00-message-store.md) | 1.5 days |
| 01 | [Delivery-event pipeline](./01-delivery-events.md) | 1 day |
| 02 | [Inbound receipt & threading](./02-inbound-receipt.md) | 1.5 days |
| 03 | [Consent & suppression](./03-consent-and-suppression.md) | 1 day |
| 04 | [Sinch & Aircall adapters](./04-sinch-and-aircall.md) | 1 day |

## Risks

**Threading is heuristics; store the evidence.** Email threading by
`References`/`In-Reply-To` with subject+participant fallback will mis-file sometimes.
Persist the raw headers/keys the decision used, and make re-threading (move message to
thread) a supported operation from day one — S11's UI exposes it; the data model must
not make it a migration.

**Inbound is untrusted input.** HTML email gets sanitized with the site-map SVG
discipline (strip scripts/handlers/external refs at write); attachments size-capped and
stored privately (no public disk paths); sender claims verified only as far as the
provider verifies them.

**Suppression ≠ consent ceremony.** This sprint enforces the floor (bounce/complaint/STOP
→ suppressed; enforced in the senders so every caller inherits it). Marketing-consent
lawfulness, double-opt-in, per-purpose consent — deliberately S13-adjacent with the
template/campaign work; record it, don't build it twice.

**Aircall is receive-only here.** Accounts + inbound call logging land now so S12's
click-to-call has rails; building dialing UI this sprint duplicates S12.
