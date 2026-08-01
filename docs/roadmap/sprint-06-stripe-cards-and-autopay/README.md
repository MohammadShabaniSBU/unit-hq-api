# Sprint 06 — Stripe Cards & Autopay

## Goal

**Money moves without a human.** Cards on file per tenant, a tokenised public payment page
for one-off collection ("request payment"), and autopay that collects each billing run's
charges off-session — every payment confirmed exclusively by verified webhook into the
append-only ledger. Combined with S05 this completes the demo spine: sign → bill itself →
collect itself.

## Starting point (verified in repo — reuse, don't rebuild)

| Exists | From | Fate |
|---|---|---|
| `site_stripe_settings` + key verify + per-site webhook route token + endpoint registration + `PublicUrlGuard` | early phase | **Re-homed** to legal entities (task 00); the flows port nearly verbatim |
| `stripe_webhook_events` idempotency table | early phase | Gains `payment_provider_account_id`; per-account uniqueness |
| `ProcessStripeWebhookEvent` | early phase | Stub → real ledger writer (task 03) |
| `payments.stripe_payment_intent_id` + `idempotency_key` | original ledger | Used as designed |
| `PaymentAllocator` | S03-06 | Same allocator for webhook payments (oldest-due-first default) |
| Public token-page pattern | offers | Same idiom for the payment page (invariant 6) |

**Why re-home:** Connect is dropped; each **legal entity** is the Stripe account holder and
merchant of record. A site never was — the per-site table predates the entity concept.
Credentials follow the entity (`architecture-payments-and-fiscal.md` §2); no live data
makes this a port, not a data migration.

## Exit criteria

- [ ] Each entity connects its own Stripe account (verify, webhook auto-registration,
      rotate, disconnect) from Settings; per-account inbound routes verify signatures.
- [ ] A tenant opens a payment link, pays by card, optionally saves it — and the ledger
      records the payment **only after** the verified webhook, allocated to the targeted
      charges.
- [ ] A contract with autopay enabled has its billing-run charges collected off-session;
      success lands in the ledger via webhook; failure is recorded where S07 will read it.
- [ ] Client-side success callbacks write nothing, ever (invariant 11 rail A).
- [ ] Kill the deployment's queue worker mid-flow: nothing double-writes when it resumes
      (idempotency end-to-end test).

## Task order

| # | Task | Est. |
|---|---|---|
| 00 | [Re-home Stripe to legal entities](./00-entity-provider-accounts.md) | 1 day |
| 01 | [Customers & saved payment methods](./01-customers-and-payment-methods.md) | 1 day |
| 02 | [Payment links & public pay page](./02-payment-links.md) | 1.5 days |
| 03 | [Webhook → ledger](./03-webhook-to-ledger.md) | 1 day |
| 04 | [Autopay](./04-autopay.md) | 1 day |

00 → everything; 01 → 02/04; 03 before 04's end-to-end tests.

## Risks

**Minor units.** Stripe amounts are integer céntimos/pence; the ledger is `NUMERIC(10,2)`
strings. One conversion pair (`App\Support\Payments\MinorUnits::toMinor/fromMinor`,
bcmath, zero-decimal-currency table for completeness) used everywhere — a stray `* 100`
float cast is this sprint's canonical bug.

**Webhook ≠ request context.** Signature verification is per-account (route token → that
account's secret); events for archived entities/accounts are acked and ignored, never
processed. The handler runs queued — no auth user, no site selector, no request id beyond
the restored correlation id.

**Off-session declines are normal.** `payment_intent.payment_failed`, SCA challenges
(`requires_action` off-session = failure), expired cards — all routine. Task 04 records
them as facts for S07's ladder; this sprint does **not** retry beyond one manual button.
Building retry logic here would duplicate the delinquency engine before it exists.

**Do not touch invariant 11's manual rail.** S03-06 stays as-is; `card_external` remains
for the terminal-in-the-office case. This sprint adds rail A, replaces nothing.
