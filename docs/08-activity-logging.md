# Activity & Event Logging

Two separate systems, deliberately split by signal-to-noise:

## 1. Business events — `spatie/laravel-activitylog`

For meaningful domain events an operator cares about:

- Contract signed
- Payment received
- Offer accepted
- Lien triggered

## 2. Operational noise — `system_events` table

A separate lightweight table for **high-frequency operational events** that would drown the activity log:

- Stripe webhooks received
- Ledger entries appended

## Mono-tenant simplification

Since the app is **mono-tenant** (single database), the earlier multi-tenant complexity is gone:

- Pruning runs against **one database** — no looping across tenants.
- Queued jobs need **no tenant context** carrying/restoring.

Keep normal retention/pruning policies for both tables (activitylog's built-in `activitylog:clean` for business events; scheduled pruning for `system_events`).
