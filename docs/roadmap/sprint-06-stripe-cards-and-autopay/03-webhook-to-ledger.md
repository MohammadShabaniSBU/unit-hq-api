# S06-03 — Webhook → ledger

## Context

The stub becomes the system's cashier: `ProcessStripeWebhookEvent` turns verified,
idempotent Stripe events into append-only ledger rows. This is invariant 11 rail A made
real — the **only** code that converts a Stripe success into money.

## Scope

**In:** handlers for `payment_intent.succeeded`, `payment_intent.payment_failed`,
`setup_intent.succeeded`, `charge.refunded` (record-only), unknown-event policy; payment +
allocation writes; payment-request/autopay-attempt state flips.
**Out:** initiating anything (02/04 initiate), retries/dunning reaction (S07 reads what
this records), refund *issuance* (parked with deposit payouts).

## Behaviour

The queued job receives the stored `stripe_webhook_events` row (already
signature-verified + idempotently inserted by 00's controller). Processing is itself
idempotent **independently of** the insert guard — the two layers back each other up:

### `payment_intent.succeeded`

1. Resolve context from metadata: `payment_request_id` (02) or `autopay_attempt_id` (04);
   a PI with neither is logged Tier-1 `stripe.orphan_intent` and skipped (money arrived
   outside the system's flows — surfaced in 04's panel attention list, resolved by manual
   recording if real).
2. In one transaction: insert `payments` — `amount` via `MinorUnits::fromMinor`,
   currency, `stripe_payment_intent_id`, **`idempotency_key` = the PI id** (the unique
   column makes double-processing a constrained impossibility; catch duplicate-key →
   ack as already-processed); method `stripe_card`; no causer (system).
3. Allocate: targeted `charge_ids` from the request/attempt first (cap at each charge's
   open amount at processing time), surplus via `PaymentAllocator` oldest-due-first,
   remainder stays computed credit.
4. Flip the source: request → `paid` + `paid_payment_id`; attempt → `succeeded`.
5. Tier-3 `payment.recorded` (`rail: stripe`, money as strings).

### `payment_intent.payment_failed`

No ledger row (nothing happened, fiscally). Request stays `pending` (tenant may retry on
the page); attempt → `failed` + `failure_code`/`decline_code` + message (04's table —
S07's dunning input). Tier-1 event.

### `setup_intent.succeeded`

Create the 01 `payment_methods` row from the attached PM (brand/last4/exp → label),
auto-default if first. Idempotent on `stripe_pm_id` unique.

### `charge.refunded`

**Record-only this sprint:** Tier-1 + attention-list entry ("refund issued in Stripe
dashboard — reconcile manually via reversal"). Writing automatic reversal payments waits
until refunds are *issued* from the app; a dashboard-side refund without context should
alert, not auto-mutate the ledger.

### Unknown events

Ack, mark processed, Tier-1 debug. Never fail the queue on novelty.

**Failure posture:** handler exceptions → queue retry with backoff (the event row's
processed flag only set on success); poison events land in `failed_jobs` and the row stays
visible as unprocessed in an admin count. Ordering is not assumed — a `succeeded` arriving
before its request's `processing` flip must still work (it does: state flips forward only).

## Invariants

- 11 rail A verbatim; 3 (append-only; the PI-id idempotency key is the enforcement
  mechanism, name it in comments); 5 (allocation caps computed at processing time);
  10 (MinorUnits both directions).

## Acceptance criteria

- [ ] Replaying the same event N times yields one payment, one allocation set (both
      guard layers exercised: dedup insert bypassed in test, unique key catches).
- [ ] Targeted-then-oldest allocation with a partially-paid target caps correctly;
      surplus → computed credit.
- [ ] Failed PI: no ledger row, attempt recorded with codes, request retryable.
- [ ] Setup success creates the card row (01's pending assertion closes here).
- [ ] Orphan PI and dashboard refund reach the attention surface without ledger writes.
- [ ] Out-of-order and unknown events are harmless.

## Tests required

| Test | Asserts |
|---|---|
| `WebhookLedgerTest::exactly_once_under_replay` | PI-id key + dedup layers |
| `WebhookLedgerTest::allocation_targeted_then_oldest_capped` | Money math |
| `WebhookLedgerTest::failed_intent_records_no_money` | Attempt facts only |
| `WebhookLedgerTest::setup_creates_method_idempotently` | 01 closure |
| `WebhookLedgerTest::orphans_and_refunds_alert_only` | No auto-mutation |
| `WebhookLedgerTest::out_of_order_and_unknown_safe` | Robustness |
