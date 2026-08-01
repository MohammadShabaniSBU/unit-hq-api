# S10-01 — Delivery-event pipeline

## Context

`ProcessDeliveryWebhookEvent` has been a dispatch stub since the comms slice shipped.
With messages as the canonical record, it becomes the state machine: provider callbacks
(delivered, opened, bounced, spam…) reconcile onto messages via `provider_message_id`,
with Stripe-grade idempotency. It also produces the *facts* task 03 turns into
suppression.

## Scope

**In:** `comms_webhook_events` idempotency table, real processing job, per-provider
event parsing through the existing `ReportsDeliveryEvents` interface (Brevo, Postmark,
Twilio; Sinch joins in 04), message state transitions, playbook-step back-fill,
unmatched-event handling.
**Out:** inbound *content* (02 — some providers mix both on one endpoint; the router
here splits by event class), consent decisions (03 consumes the facts).

## Behaviour

**Idempotency.** `comms_webhook_events` mirrors the Stripe pattern:
`(communication_account_id, provider_event_id)` unique, raw payload stored, processed
flag, received timestamp. Providers without stable event ids (some Twilio callbacks)
get a derived key: `hash(provider_message_id + raw_status + timestamp-bucket)` —
documented per adapter, honest about its coarseness.

**Processing.** The inbound controller (existing per-account token route) inserts +
dispatches; the job: parse via the account's adapter → normalized `DeliveryEvent`
(existing vocabulary) → locate message by `(provider, provider_message_id)` →
**forward-only** state transition (delivered may follow sent; sent may not follow
delivered; opened/clicked never regress bounced — encode the lattice as data:
`DeliveryStatus::rank()`), stamp `raw_status` into a `delivery_events` JSONB append on
the message (full history, not just latest), touch the linked `Interaction`, and — when
`source = playbook` — append the outcome onto the automation step's detail (the S09
provenance ids finally close their loop: "sent" becomes "delivered/bounced" in the run
log).

**Unmatched events** (no message row — pre-S10 sends, foreign traffic): recorded on the
event row as `unmatched`, Tier-1 counter; the legacy `OfferDelivery` path still gets its
status update for pre-store rows (keep the old reconciliation until those age out —
delete it in a later cleanup, noted).

**Bounce/spam classification.** Hard bounce vs soft bounce vs complaint mapped per
adapter into the normalized event (`is_permanent` flag); the job emits a
`ChannelDeliveryFailed` domain event carrying contact-channel + permanence — task 03's
sole input. No suppression logic here.

## Acceptance criteria

- [ ] Replaying any event (real id or derived key) is a no-op; parallel delivery races
      settle on the lattice-highest state.
- [ ] Full status history accumulates; latest state renders on the message; Interaction
      touched.
- [ ] Playbook-sourced messages back-fill their step detail (run log shows delivered/
      bounced).
- [ ] Hard bounce/complaint emit `ChannelDeliveryFailed(permanent)`; soft bounce
      non-permanent; nothing suppresses yet.
- [ ] Unmatched events counted, legacy OfferDelivery path still updates.
- [ ] Per-adapter parse fixtures (recorded real payloads, committed) for all three
      current providers.

## Tests required

| Test | Asserts |
|---|---|
| `DeliveryPipelineTest::idempotent_including_derived_keys` | Both key modes |
| `DeliveryPipelineTest::forward_only_lattice` | Out-of-order safety |
| `DeliveryPipelineTest::playbook_step_backfill` | S09 loop closed |
| `DeliveryPipelineTest::classification_events` | 03's contract |
| `AdapterParseTest::{brevo,postmark,twilio}_fixtures` | Real payloads |
