# Conventions & Hard Invariants

> Read this before writing any code. These rules override convenience.

## Data invariants — never break

1. **Mono-tenant** — one database, one company. No `company_id` / `tenant_id` columns, no tenancy package, no tenant scoping anywhere.
2. **Price immutability** — never `UPDATE` a price amount. History via new rows + `effective_to` closing.
3. **Ledger immutability** — charges/payments are append-only; corrections are opposing rows via `reversal_of_*_id`.
4. **Notes are append-only.**
5. **Derived state only** — never store `is_available`, balance owed, overdue flags, or unallocated credit as columns. Always compute:
   - Availability = no active contract + no non-expired reservation
   - Balance = Σ charges − Σ payments
   - Overdue = per-charge, by due date
   - Exception: `contracts.billed_through` is a **stored billing cursor** (date), not cached money — the recurring job advances it; balances stay computed.
6. **Offer token** — public offer links use the crypto-random `offers.token`, never the PK.
7. **One selected option per offer** — enforced by partial unique index on `offer_id WHERE selected_at IS NOT NULL`.
8. **One primary channel per type per contact** — partial unique index on `contact_channels`.
9. **Money is `NUMERIC(10,2)`** — never floats. Same in activitylog `properties`: store amounts as strings (e.g. `"184.90"`), never floats. PHP money math uses **bcmath** (`App\Support\Billing\BillingMath`): multiply-before-divide, single half-up `round2`.
10. **Payments confirmed from Stripe webhooks + idempotency keys** — never optimistically from the client. Ledger is the system of record; Stripe events are reconciled inputs.
11. **Offer acceptance is one transaction** — `selected_at` + status flip + reservation insert together.
12. **Offer expiry is read-time**, not a background job.
13. **Activitylog `description` is a machine key** (e.g. `deal.stage_changed`, `contract.signed`) — never a human sentence. Panel translates via i18n.
14. **Activity / system_events are append-only** — no update/delete endpoints. Prune and GDPR redaction commands are the only writers that mutate history.
15. **Manual activity rows go through `RecordsActivity`** with a `LogChannel` — never bare `activity()` without `log_name`.
16. **TaxRate immutability** — never `UPDATE` `tax_rates.rate` in place. New version same `code` + close old `effective_to` (mirrors prices). Signed contracts keep tax via item/charge snapshots.
17. **Contract billing snapshots** — cadence, anchor model/date, proration, deposit, and tax % are snapshotted at signing; later org settings changes never rewrite existing contracts.
18. **Deposit charges are not revenue** — `charge_type = deposit` is excluded from revenue semantics.
19. **Contract create writes first-period charges in the same DB transaction** as the contract + items (store / convert). Preview must call the same `BillingMath` / `ContractBilling` path.

## Code conventions

### API (Laravel)

- **No `app/Services/` layer** — fat controllers/models; DB transactions for multi-step operations.
- Shared pure helpers live under **`App\Support\`** (e.g. `App\Support\Billing\BillingMath`, `ContractBilling`) — same tier as `RecordsActivity`, never a Services directory.
- Response shape via `ApiResponsable`: `{ message, data }` / paginated `{ meta }`.
- Morph map registered explicitly — e.g. `unit → Unit`, `insurance → Insurance`.
- Tests: PHPUnit + SQLite in-memory.

### Panel (Nuxt)

- SPA only (`ssr: false`).
- All UI strings through i18n (`locales/en.json`, `es.json`) — never hardcoded.
- HTTP via `useApi()`; types in `app/types/`; composables `useXxx` / `useXxxList`.
- TypeScript arrays as `Array<T>`, not `T[]`.
- CI = `bun run lint` + `bun run typecheck`.

## Naming

- The pipeline's final entity is **`Contract`** in code (docs/ERD may say "Lease").
- Activity comments are **`Notes`** in code (ERD may say "comments").
