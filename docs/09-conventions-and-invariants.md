# Conventions & Hard Invariants

> Read this before writing any code. These rules override convenience.

## Data invariants — never break

1. **Mono-tenant** — one database, one company. No `company_id` / `tenant_id` columns, no tenancy package, no tenant scoping anywhere.
34. **`legal_entities` is a fiscal domain concept, not a tenancy boundary.** It identifies the
    issuer of an invoice and the holder of payment credentials. It must never appear in a
    global scope, a middleware-applied filter, a queue payload context, or a default query
    constraint. Filtering an invoice *series* by entity is correct; filtering *contacts* by
    entity is a defect.
34b. **Issued fiscal identity is immutable.** Once any invoice exists for a legal entity,
    `legal_entities.tax_id` and `country_code` are frozen. Contact-style fields may still
    change; identity changes require a new entity.
34c. **Invoice numbers are gapless per series.** Numbers are allocated by row-locking the
    series (`SELECT … FOR UPDATE`) inside the issuing transaction via
    `App\Support\Fiscal\InvoiceNumbering::allocate`. Numbers are never reserved, reused,
    edited, or back-filled; a rolled-back issuance consumes nothing. Series `code` and
    `legal_entity_id` freeze once any invoice references the series.
34d. **Issued invoices are immutable and snapshot-complete.** Every field needed to
    re-render an issued invoice lives on the `invoices` / `invoice_lines` rows. No
    PATCH/DELETE endpoints. Corrections are rectificative invoices. `charges.invoice_id`
    is written once (null → id) and never cleared.
2. **Price immutability** — `prices.amount`, `currency`, `scope`, `priceable_type` and `priceable_id` are never updated. The only permitted write to an existing price row is `effective_to`: NULL → a date, exactly once, in the same transaction that inserts the successor catalogue row. Nothing is ever deleted. Catalogue history lives on price windows; junctions (`unit_class_rates` / `insurance_rates`) are static.
2b. **Contract item immutability** — item versions are superseded, never updated for amount or subject. A price or subject change closes the current version via `effective_to` and inserts a successor linked by `supersedes_id`. Every version carries a required `price_id` (amount/currency read through the price). Charges keep referencing the version that produced them via `charges.contract_item_id`.
3. **Ledger immutability** — charges/payments are append-only; corrections are opposing rows via `reversal_of_*_id`.
4. **Notes are append-only.**
5. **Derived state only** — never store `is_available`, balance owed, overdue flags, or unallocated credit as columns. Always compute:
   - Availability = no covering `unit_occupancies` / `unit_holds` row — via `App\Support\Occupancy\Availability` only (invariant 36).
   - Balance = Σ charges − Σ payments
   - Overdue = per-charge, by due date
   - Exception: `contracts.billed_through` is a **stored billing cursor** (date), not cached money — the recurring job advances it; balances stay computed.
   - Clarification: `unit_occupancies` and `unit_holds` are **fact tables** (who occupies / holds a unit over a date range). They are not cached derived state. Availability remains computed from those facts.
   - Clarification: views and materialized views in the `analytics` schema are a **reporting projection** of facts, refreshed on a schedule and read only by external analytics tools via `metabase_ro`. No application code queries `analytics`. This is not cached derived state on a business table.
   - Clarification: `insight_reports.validation_status` caches an **external** system's state (whether the remote dashboard/question and its embed params still match), not ours. It is not derived business state.
   - **Delinquency severity is computed; delinquency history is facts.** No stage/severity/amount column exists on cases. Cases and steps are append-only; ladder steps fire at most once per case (partial unique on `(delinquency_id, policy_step_id)`); every step references the artefact it produced.
   - **Floor map shapes join on `data-unit-number`.** `id` is a fallback for legacy maps only. A map is never partially matched across both conventions in one document. Match buckets (`id_match`) are computed on read/upload, never stored.
6. **Offer token** — public offer links use the crypto-random `offers.token`, never the PK.
7. **One selected option per offer** — enforced by partial unique index on `offer_id WHERE selected_at IS NOT NULL`.
8. **One primary channel per type per contact** — partial unique index on `contact_channels`.
9. **One layout placement per native field / attribute definition per entity** — partial unique indexes on `layout_fields (entity_type, native_field_key) WHERE native_field_key IS NOT NULL` and `(entity_type, attribute_definition_id) WHERE attribute_definition_id IS NOT NULL`. `entity_type` is denormalized onto `layout_fields` and immutable after insert.
10. **Money is `NUMERIC(10,2)`** — never floats. Same in activitylog `properties`: store amounts as strings (e.g. `"184.90"`), never floats. PHP money math uses **bcmath** (`App\Support\Billing\BillingMath`): multiply-before-divide, single half-up `round2`.
11. **Payment confirmation is rail-specific, and never optimistic from the client.**
    - **Stripe:** payments are written only on receipt of a verified webhook, with per-account
      idempotency keys. Never from a client-side success callback.
    - **Bank SEPA DD:** generating a direct-debit collection file is **not** a payment. A
      payment is written on the run's settlement date. A return, imported from the bank's
      returns file, writes a reversal payment via `reversal_of_payment_id` — never an edit or
      delete.
    - **Manual (cash, transfer):** written by an authenticated employee with a recorded causer.
    In all cases the ledger is the system of record; provider events are reconciled inputs.
12. **Offer acceptance is one transaction** — `selected_at` + status flip + reservation insert together.
13. **Offer expiry is read-time**, not a background job.
14. **Activitylog `description` is a machine key** (e.g. `deal.stage_changed`, `contract.signed`) — never a human sentence. Panel translates via i18n.
15. **Activity / system_events are append-only** — no update/delete endpoints. Prune and GDPR redaction commands are the only writers that mutate history.
16. **Manual activity rows go through `RecordsActivity`** with a `LogChannel` — never bare `activity()` without `log_name`.
17. **TaxRate immutability** — never `UPDATE` `tax_rates.rate` in place. New version same `code` + close old `effective_to` (mirrors prices). Signed contracts keep tax via item/charge snapshots.
18. **Contract billing snapshots** — cadence, anchor model/date, proration, deposit, and tax % are snapshotted at signing; later org settings changes never rewrite existing contracts. Exception: `BillingSettings.billing_horizon_days` is operational (when a period is generated), never contractual — it is not snapshotted and may change freely without rewriting windows or amounts.
    - **Scoped exception (S07 delinquency ladder):** `delinquency_policies` / steps are operational conduct, not contracted billing terms. No snapshot onto contracts — live-policy edits affect only *future* evaluation; already-executed case steps remain append-only history. Operators can soften escalation for everyone at once. Caveat: late-fee `type`/`amount`/`percent` are often contractual in practice; v1 accepts the live-policy reading — fee-terms snapshotting is the known follow-up in `10-open-decisions.md`.
19. **Deposit charges are not revenue** — `charge_type = deposit` is excluded from revenue semantics.
20. **First-period charges, the first invoice, and occupancy open are written in one transaction with the contract becoming signed** — at creation for immediate signing, at signature completion for remote. `ContractSigning::complete` is the only implementation; both paths call it. Preview must call the same `BillingMath` / `ContractBilling` path.
21. **Attribute definitions are archive-only** — never hard-delete `attribute_definitions` (or their values). Operators set `archived_at`; archived definitions stay out of the add-field picker but may remain on existing layouts / values until removed.
22. **`attribute_definitions.group_name` is not a layout group** — free-text catalog metadata only. Overview cards are `attribute_groups`; placement is via `layout_fields`. Never treat `group_name` as an FK to `AttributeGroup`.
23. **Automations are archive-only** — never hard-delete an automation with run history. `archived_at` + `POST …/archive` / `…/unarchive`; `DELETE` aliases archive.
24. **Automation runs / run steps are append-only** — no update/delete API. Retry = new `automation_runs` row (optionally `root_run_id` → original). Log skipped branch paths as `status=skipped` steps, not silence.
25. **Automation-originated model writes do not re-trigger automations** — `AutomationContext` suppresses `Model*` event dispatch from `HasAutomationTriggers`. Tier-2 activity diffs still log. Causer morph is stamped at event dispatch (request lifecycle), never re-resolved inside queue workers. **Authorization:** while `AutomationContext` is active, `Actor::current()` is `SystemActor` (Gate allows). An automation that vacates a contract does not need `contract.vacate` — the operator authorised the automation graph, not each of its writes.
25b. **Automation run transitions are single-funnel and claim-based.** Every status change goes through `RunLifecycle::transition()`; executors and cancellers race via conditional updates on the status column, never checks-then-writes. A parked wait is `waiting`, never `running`. Cancelled runs record cause and a synthetic step; unexecuted nodes are not marked skipped.
26. **Credentials are encrypted at rest and never returned in API responses** — secrets serialize as masked last-4 (`••••••ab12`); a blank submitted field means unchanged, never wipe. Shared via `App\Support\Credentials\CredentialMasker` (masking + `DecryptException` handling) and `CredentialField` (blank-means-unchanged), used by both comms provider accounts and per-entity `payment_provider_accounts`.
27. **Credential lifecycle events are Tier-3 `RecordsActivity::core`** — create / rotate / remove only; properties limited to site/provider identifiers, masked last-4, and result — never the secret. Shared via `App\Support\Credentials\CredentialAudit`.
28. **Sites are archive-only** — never hard-delete a site. `archived_at` + `POST …/archive` / `…/unarchive`; `DELETE` aliases archive. No global scope (historical relations must resolve); list/options apply an explicit `active()` scope.
29. **Currency lives on the price row.** `prices.currency` is authoritative. `sites.currency`
    and `BillingSettings.default_currency` are form prefill defaults — never read at
    transaction time, in a resource, or in a rollup. A contract snapshots the amount **and**
    the currency, not just the `price_id`.
30. **Revenue is grouped by currency, never summed across it.** No aggregate returns a scalar
    money total spanning more than one currency.
31. **No money value crosses an API boundary without its currency**, and no panel component
    contains a currency symbol literal. Rendering is
    `Intl.NumberFormat(locale, { style: 'currency', currency })` with `currency` from the
    record. Locale never determines currency.
32. **Date boundaries resolve through the owning site's timezone** (`App\Support\Time\SiteClock`).
    Bare `Carbon::today()` and `->toDateString()` on a timestamp are defects in any code that
    produces or compares a DATE. Cross-site "today" is computed per site.
33. **`tax_rates.jurisdiction` is `NULL` (applies anywhere) or ISO 3166-1 alpha-2 with an
    optional ISO 3166-2 subdivision** (`ES`, `ES-CN`, `FR`). Validated on write.
35. **One contract, one currency.** `contracts.currency` is resolved from the contract's items
    at signing and is immutable thereafter. Every `charge`, `payment`, and `allocation`
    attached to that contract carries or matches it. `balance = Σ charges − Σ payments` is
    only ever computed within a single currency. Amounts are never converted between
    currencies — anywhere, for any reason.
36. **A unit is available on date D when it has no `unit_occupancies` row covering D and no
    unreleased blocking `unit_holds` row covering D.** Ranges are half-open — `[started_on,
    ended_on)` and `[starts_on, ends_on)` — so an end date is the first day *not* covered. Never
    store availability as a column.
37. **Recurring billing is cursor-serialised, and the cursor has one writer.**
    `contracts.billed_through` is written in exactly one circumstance: forward, to a billed
    period's end, inside the per-contract billing transaction, under the row lock. It is
    never written on a skip, at the stop line, or by any other code path. Charges, invoice
    and cursor advance commit atomically per contract per run; no secondary dedup state may
    be introduced. Run and run-item rows (`billing_runs` / `billing_run_items`) are append-only.

38. **`messages` is the canonical communication record.** Every send and every receipt
    creates exactly one message; `Interaction` is a linked timeline index, never the body
    store. `(provider, provider_message_id)` is unique — it is the reconciliation key for
    delivery events and the idempotency key for inbound receipt. Bodies are sanitized at
    write; attachments live on the private disk.

39. **Delivery webhooks are idempotent and status is forward-only.** Provider callbacks
    persist on `comms_webhook_events` under unique `(communication_account_id,
    provider_event_id)` before processing — replay is a no-op (parity with Stripe).
    Message status advances only when `DeliveryStatus::rank()` of the new event is
    strictly greater than the current rank; full history accumulates on
    `messages.delivery_events`. Bounce/spam emit `ChannelDeliveryFailed` facts;
    suppression is a separate concern (listener → `SuppressionWriter`, never inline
    in the delivery job). Address-keyed `channel_suppressions` are enforced only
    inside `EmailSender` / `SmsSender`; they survive `contacts:redact`.

40. **Inbound receipt never creates contacts silently.** Unknown senders park on
    `comms_triage`; contacts are created only via explicit triage resolve
    (`create-and-attach`). Inbound HTML is sanitized at write (scripts, handlers,
    external http(s) refs); attachments are size-capped with honest `oversize`
    stubs on the private disk. Auto-Submitted / X-Autoreply messages are stored
    with `auto_generated` and do not increment unread.
41. **Discounts compile at signing; billing never interprets them.** A discount
    materializes as contract-scoped price versions (+ linked provenance) inside the
    signing/convert transaction. No code in billing runs, settlements, or reports may
    branch on discount presence. Removal and rate-change recompute emit versions
    through the same rate-change path.
42. **Every API route is authenticated unless it appears in the public allowlist
    in `routes/api.php` with a comment naming what authenticates it instead.**
    Enforced by `RouteAuthCoverageTest`, which fails on any route that is neither
    inside the `auth:sanctum` group nor on the allowlist. Every authenticated
    route must also reach an authorization decision — enforced by
    `PermissionCoverageTest` against `RoutePermissions` (Permission or reasoned
    Exempt). A manifest entry naming a Permission without a matching
    `authorize()` / `Gate::authorize()` call also fails that test.
43. **Permissions are a PHP enum, never database rows.** `role_permissions` stores
    `App\Support\Auth\Permission` enum values. A permission that does not exist as
    an enum case is a defect, not data. Every enum case must appear in a
    manifest entry, policy, or system role (`PermissionCoverageTest`); the panel
    mirror in `app/types/permissions.ts` must match both ways
    (`PanelPermissionMirrorTest`).
44. **At least one employee holds a company-wide `owner` grant at all times.**
    Revocation that would empty the set is refused inside the transaction (via
    `OwnerFloor` + `SELECT … FOR UPDATE`), not by UI affordance alone.
45. **Authorization failure is fail-closed.** An unmapped subject in
    `SubjectSite` throws; it never resolves to `null` and never resolves to
    "allowed". Adding a model to the morph map without adding it to
    `SubjectSite` fails `SubjectSiteCoverageTest`. System-actor writes
    (`Actor::current()` / `Gate::before`) authorize headless paths; they do not
    change activity causer stamping (invariant 25).
46. **Authorization scoping is explicit and local.** Row visibility is applied
    via an explicit `visibleTo()` call at the query site. It must never be a
    global scope, middleware-set context, or queue payload key. A company-wide
    grant applies no filter at all.
47. **Deactivating an employee revokes their Sanctum access tokens in the same
    transaction.** An account that cannot sign in but whose issued tokens still
    authenticate is not deactivated. Employees are archive-only (`deactivated_at`);
    never hard-deleted — they are causers on append-only history.
48. **`ai_usage_events` is operational telemetry, not the ledger.** It never produces a
    charge, a payment, or a revenue figure, and never uses the `NUMERIC(10,2)` money path.
    Its `status` is deliberately mutable — the reserve/settle lifecycle is not an
    append-only ledger and invariant 3 does not apply to it. Estimated cost is derived at
    read time from `ai_model_prices` (invariant 5 / 2 pattern: effective-dated catalogue,
    never an in-place rate `UPDATE`). Estimated cost never reconciles to the provider
    invoice — retries, failovers, cached tokens and rounding all diverge. The figure
    attributes spend between employees; it is not an accounting record and must not be
    presented as one. Never return a single summed cost across currencies (invariant 30).
49. **Analytics provider credentials follow the shared credential rules.** Invariants 26 and
    27 apply unchanged to `analytics_accounts` — encrypted at rest, masked last-4 in responses,
    blank submitted field means unchanged, Tier-3 `RecordsActivity::core` on create / rotate /
    remove, `DecryptException` degrades to `credentials_unreadable`. Shared helpers in
    `App\Support\Credentials\`.
50. **A dynamic insight param is always locked.** `value_source = 'dynamic'` implies
    `binding = 'locked'`, enforced by a database `CHECK`. Only static params may be editable
    defaults. Dynamic values resolve from the `App\Support\Insights\DynamicParams` whitelist —
    never from a client-supplied key, never from an expression language.
51. **Embed tokens are minted server-side and short-lived.** The provider's signing secret
    never appears in an API response, a queue payload, an activity log, a system event, an
    exception message, or the panel bundle. The panel receives a URL and renders it; it never
    constructs one. TTL ≤ 10 minutes.
52. **Insight reports and analytics accounts are archive-only.** `archived_at`,
    `POST …/archive` / `…/unarchive`, `DELETE` aliases archive. Built-in (`is_system`) reports
    can be hidden and relabelled but never archived or repointed.

## Code conventions

### API (Laravel)

- **No `app/Services/` layer** — fat controllers/models; DB transactions for multi-step operations.
- Shared pure helpers live under **`App\Support\`** (e.g. `App\Support\Billing\BillingMath`, `ContractBilling`, `App\Support\Filtering\FilterBuilder`) — same tier as `RecordsActivity`, never a Services directory.
- **Advanced list filters** — never trust client column names. Native fields come from `FilterableFields` whitelist; custom attrs via `attr:{definitionId}` + correlated `whereExists` on `attribute_values` (no join-per-condition). Validate tree (`FilterTreeValidator`) before `FilterBuilder` runs.
- Response shape via `ApiResponsable`: `{ message, data }` / paginated `{ meta }`.
- Morph map registered explicitly — e.g. `contact`, `deal`, `offer`, `reservation`, `unit`, `contract`, `insurance`.
- Tests: PHPUnit + SQLite in-memory.
- **Demo stage generation path performs no random draws.** Anything added to `StageSeeder` after unit creation must be deterministic (no `mt_rand()`, `fake()`, `shuffle()`, `Collection::random()`, `Str::random()`), or every cast persona downstream shifts. Floor assignment is a pure sort.

### Panel (Nuxt)

- SPA only (`ssr: false`).
- All UI strings through i18n (`locales/en.json`, `es.json`, `fr.json`) — never hardcoded.
- HTTP via `useApi()`; types in `app/types/`; composables `useXxx` / `useXxxList`.
- TypeScript arrays as `Array<T>`, not `T[]`.
- CI = `bun run lint` + `bun run typecheck`.

## Naming

- The pipeline's final entity is **`Contract`** in code (docs/ERD may say "Lease").
- Activity comments are **`Notes`** in code (ERD may say "comments").
- Prefer **card** when talking about overview UI (`AttributeGroup`); reserve **`group_name`** for the optional definition catalog string.
