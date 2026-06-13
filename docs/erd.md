# Entity Relationship Diagram

Self-Storage SaaS — unit-hq

---

## Multi-tenancy architecture

This platform uses a **database-per-tenant** model. Two distinct database scopes exist.

```mermaid
flowchart LR
    subgraph platform_db [Platform_DB]
        users["users (admins)"]
        tenants["tenants (routing registry)"]
    end

    subgraph tenant_db [Tenant_DB_per_company]
        employees
        contacts
        sites
        unit_classes
        units
        pricing["prices / rates / discounts"]
        crm["deals / offers / reservations / leases"]
        ledger["charges / payments / allocations"]
        stripe_events["stripe_webhook_events"]
    end

    users -->|"manage"| tenants
    tenants -->|"routes to"| tenant_db
```

- **Platform DB** — one shared database for the platform. Holds admin users and a tenant routing table only.
- **Tenant DB** — one database per storage company. Holds all domain data. No `company_id` FK columns; isolation is enforced at the DB connection level.

---

## Design principles

| Rule | Implication |
|------|-------------|
| DB-per-tenant isolation | No `company_id` FK on tenant DB tables; scope enforced by connection routing |
| Three human models | `users` (platform admins), `employees` (company staff), `contacts` (prospective renters) |
| No Building model | Facility hierarchy is Site → Unit only |
| Money never as float | `NUMERIC(10,2)` on all monetary columns |
| Price immutability | `effective_from` / `effective_to`; rate changes = new `prices` row, never UPDATE in place |
| Unit availability derived | Not stored — computed from active leases + non-expired reservations |
| Ledger immutability | Append-only `charges` / `payments`; reversals via opposing rows with `reversal_of_*_id` |
| Invoice is a grouping view | `invoices` groups `charges`; not the atomic unit of money |
| Offer token security | `offers.token` is a crypto-random URL-safe string, not the PK |
| Single option selection | Partial unique index on `offer_options(offer_id) WHERE selected_at IS NOT NULL` |
| Pipeline order | Contact → Deal → Offer → OfferOption → Reservation → Lease |
| Stripe Connect | One connected account per tenant; Stripe info lives in Platform DB `tenants` table |

---

## High-level domain map (Tenant DB)

```mermaid
flowchart TB
    subgraph facility [Facility_Management]
        Site --> Unit
        UnitClass --> Unit
    end

    subgraph pricing [Pricing]
        Price
        UnitClassRate
        InsurancePlan
        InsurancePlanRate
        Discount
    end

    subgraph crm [CRM_and_Offers]
        Contact --> Deal --> Offer --> OfferOption
        Offer --> OfferDelivery
        OfferOption -->|"accept"| Reservation --> Lease
    end

    subgraph billing [Billing_and_Ledger]
        Lease --> Charge
        Lease --> Payment
        Charge --> Allocation
        Payment --> Allocation
        Charge --> Invoice
    end

    subgraph stripe [Stripe_Reconciliation]
        Payment --> StripeWebhookEvent
    end

    UnitClassRate --> Price
    UnitClassRate --> Site
    OfferOption --> Price
    OfferOption --> UnitClass
```

---

## Offer-accept transaction sequence

When a contact selects an offer option, the following three steps execute in a **single database transaction**. No partial state is ever visible.

```mermaid
sequenceDiagram
    actor Contact
    participant App
    participant offer_options
    participant offers
    participant reservations

    Contact->>App: SELECT option (offer token + option id)
    App->>offer_options: SET selected_at = now()
    App->>offers: SET status = 'accepted', accepted_at = now()
    App->>reservations: INSERT (unit_id, contact_id, offer_option_id, expires_at)
    App-->>Contact: Reservation confirmed
```

**Rules:**
- `offers` does **not** hold a back-reference to `reservations` — the FK is one-way: `reservations.offer_option_id → offer_options.id`
- The partial unique index on `offer_options(offer_id) WHERE selected_at IS NOT NULL` enforces that only one option per offer can ever be selected at the database level

---

## 1. Platform DB

```mermaid
erDiagram
    users {
        bigint id PK
        varchar name
        varchar email UK
        varchar password
        varchar role "superadmin|admin"
        timestamptz created_at
        timestamptz updated_at
    }

    tenants {
        bigint id PK
        varchar name
        varchar slug UK
        varchar db_connection "connection name or DSN"
        varchar stripe_connect_account_id UK "nullable until onboarded"
        varchar stripe_onboarding_status "pending|active|restricted"
        timestamptz created_at
        timestamptz updated_at
    }
```

**Notes:**
- `users` are platform administrators only — they can create, edit, and delete tenants.
- `tenants` is the routing registry — the application reads `db_connection` to resolve which database to use for a request.
- Stripe Connect account info lives here because it is tenant-wide, not tied to any domain row inside the tenant DB.

**Indexes:** `tenants(slug)` UNIQUE, `tenants(stripe_connect_account_id)` UNIQUE

---

## 2. Tenant DB — People

```mermaid
erDiagram
    employees {
        bigint id PK
        varchar name
        varchar email UK
        varchar password
        varchar role "manager|staff"
        timestamptz created_at
        timestamptz updated_at
    }

    contacts {
        bigint id PK
        varchar first_name
        varchar last_name
        varchar email "nullable"
        varchar phone "nullable"
        timestamptz created_at
        timestamptz updated_at
    }
```

**Notes:**
- `employees` authenticate into the operator dashboard. `role` controls what they can do within the tenant.
- `contacts` are durable identity records for people who enquire or rent. Not all contacts will become tenants.
- No `company_id` — every row in the tenant DB is implicitly scoped to that company by the DB connection.

**Indexes:** `employees(email)` UNIQUE, `contacts(email)`

---

## 3. Tenant DB — Facility Management

Hierarchy: **Site → Unit** (no Building layer).

```mermaid
erDiagram
    sites ||--o{ units : contains
    unit_classes ||--o{ units : instantiates

    sites {
        bigint id PK
        varchar name
        text address "nullable"
        timestamptz created_at
        timestamptz updated_at
    }

    unit_classes {
        bigint id PK
        varchar code_slug UK
        varchar label
        varchar tier "grouping label"
        numeric width "nominal, metres"
        numeric depth "nominal, metres"
        numeric height "nominal, metres"
        jsonb amenities "array of amenity codes"
        bigint current_price_id FK "convenience pointer to active price"
        timestamptz created_at
        timestamptz updated_at
    }

    units {
        bigint id PK
        bigint site_id FK
        bigint unit_class_id FK
        varchar unit_number
        numeric actual_width "nullable override"
        numeric actual_depth "nullable override"
        numeric actual_height "nullable override"
        timestamptz created_at
        timestamptz updated_at
    }
```

**Indexes:**
- `unit_classes(code_slug)` UNIQUE
- `units(site_id, unit_number)` UNIQUE
- `units(unit_class_id)`

**Derived (not columns):** `units.is_available` — computed by absence of active `leases` and non-expired `reservations` for that unit. Billing and listings use class dimensions; surveys and compliance use `actual_*` overrides when populated.

---

## 4. Tenant DB — Pricing

```mermaid
erDiagram
    employees ||--o{ prices : created_by
    unit_classes ||--o{ unit_class_rates : priced_via
    sites ||--o{ unit_class_rates : priced_via
    prices ||--o{ unit_class_rates : referenced_by
    unit_classes ||--o| prices : current_price
    insurance_plans ||--o{ insurance_plan_rates : priced_via
    prices ||--o{ insurance_plan_rates : referenced_by
    prices ||--o{ offer_options : quoted_on

    prices {
        bigint id PK
        numeric amount "NUMERIC(10,2)"
        char currency "ISO 4217, 3 chars"
        varchar billing_period "monthly|weekly|annual"
        date effective_from
        date effective_to "NULL = current"
        bigint created_by FK "employees.id"
        timestamptz created_at
    }

    unit_class_rates {
        bigint id PK
        bigint unit_class_id FK
        bigint site_id FK
        bigint price_id FK
        timestamptz created_at
    }

    insurance_plans {
        bigint id PK
        varchar name
        text description "nullable"
        timestamptz created_at
        timestamptz updated_at
    }

    insurance_plan_rates {
        bigint id PK
        bigint insurance_plan_id FK
        bigint price_id FK
        timestamptz created_at
    }

    discounts {
        bigint id PK
        varchar code "nullable"
        varchar label
        varchar discount_type "percentage|fixed_amount"
        numeric value "NUMERIC(10,2)"
        date effective_from "nullable"
        date effective_to "nullable"
        timestamptz created_at
    }
```

**Indexes:**
- `unit_class_rates(unit_class_id, site_id)` — active-rate resolution per site
- `unit_class_rates(unit_class_id, site_id, price_id)` UNIQUE
- `prices(effective_from, effective_to)` for temporal lookups

**Constraint notes:**
- Rate change = INSERT new `prices` row + INSERT new `unit_class_rates` row; SET `effective_to` on old `prices` row — never UPDATE amount in place.
- `unit_classes.current_price_id` is a convenience pointer; authoritative pricing history lives in `unit_class_rates` + `prices`.

---

## 5. Tenant DB — CRM, Offers, and Pipeline

Pipeline: **Contact → Deal → Offer → OfferOption → Reservation → Lease**

```mermaid
erDiagram
    contacts ||--o{ deals : pursues
    deals ||--o{ offers : proposes
    contacts ||--o{ offers : recipient
    offers ||--o{ offer_options : contains
    offers ||--o{ offer_deliveries : sent_via
    unit_classes ||--o{ offer_options : quotes
    discounts ||--o{ offer_options : applies
    offer_options ||--o| reservations : selected_into
    units ||--o{ reservations : holds
    contacts ||--o{ reservations : holds_for
    reservations ||--o| leases : converts_to
    units ||--o{ leases : occupies
    contacts ||--o{ leases : tenant
    deals ||--o{ leases : originated_from

    deals {
        bigint id PK
        bigint contact_id FK
        varchar pipeline_stage
        numeric expected_value "NUMERIC(10,2)"
        date expected_move_in "nullable"
        text intent_notes "nullable"
        timestamptz created_at
        timestamptz updated_at
    }

    offers {
        bigint id PK
        bigint deal_id FK
        bigint contact_id FK
        varchar token UK "crypto-random URL-safe"
        varchar status "draft|sent|viewed|accepted|expired"
        timestamptz expires_at
        timestamptz sent_at "nullable"
        timestamptz first_viewed_at "nullable"
        timestamptz accepted_at "nullable"
        timestamptz created_at
        timestamptz updated_at
    }

    offer_options {
        bigint id PK
        bigint offer_id FK
        bigint unit_class_id FK
        bigint price_id FK
        bigint discount_id FK "nullable"
        varchar label
        text description "nullable"
        int display_order
        timestamptz selected_at "nullable; max 1 per offer"
        timestamptz created_at
        timestamptz updated_at
    }

    offer_deliveries {
        bigint id PK
        bigint offer_id FK
        varchar channel "email|sms|whatsapp"
        varchar recipient_address
        timestamptz sent_at
        timestamptz delivered_at "nullable"
        varchar delivery_status "queued|sent|delivered|failed"
        timestamptz created_at
    }

    reservations {
        bigint id PK
        bigint unit_id FK
        bigint contact_id FK
        bigint offer_option_id FK
        timestamptz expires_at
        text hold_notes "nullable"
        timestamptz created_at
        timestamptz updated_at
    }

    leases {
        bigint id PK
        bigint unit_id FK
        bigint contact_id FK
        bigint reservation_id FK "nullable if walk-in"
        bigint deal_id FK "nullable"
        date start_date
        date end_date "nullable"
        numeric actual_rate "NUMERIC(10,2)"
        numeric actual_insurance "NUMERIC(10,2) nullable"
        varchar status "active|terminated|expired"
        timestamptz signed_at
        timestamptz created_at
        timestamptz updated_at
    }
```

**Indexes and constraints:**
- `offers(token)` UNIQUE
- `offer_options(offer_id) WHERE selected_at IS NOT NULL` — partial UNIQUE (PostgreSQL); one selection per offer
- `offer_deliveries(offer_id, sent_at)`
- `reservations(unit_id)` + temporal filter for availability derivation
- `leases(unit_id, status)` for active-lease lookup

**Application event rules:**
- Offer `status` transitions: draft → sent → viewed → accepted; expiry checked at read time against `expires_at` — never flipped by a background job
- On option accept: single transaction writes `offer_options.selected_at`, sets `offers.status = accepted`, inserts `reservations`
- `offers` does not hold a back-reference to `reservations`

**Derived (not columns):** lease duration, size variance (actual unit dims vs class dims) — computed at query time.

---

## 6. Tenant DB — Billing and Ledger

Three layers: **ledger entries** → **invoice grouping** → **payment-to-charge allocation**.

```mermaid
erDiagram
    leases ||--o{ invoices : billed_via
    leases ||--o{ charges : debits
    leases ||--o{ payments : credits
    invoices ||--o{ charges : groups
    charges ||--o{ allocations : paid_by
    payments ||--o{ allocations : applies_to
    charges ||--o| charges : reversal_of
    payments ||--o| payments : reversal_of

    invoices {
        bigint id PK
        bigint lease_id FK
        date billing_period_start
        date billing_period_end
        varchar status "draft|issued|paid|void"
        timestamptz issued_at "nullable"
        timestamptz created_at
    }

    charges {
        bigint id PK
        bigint lease_id FK
        bigint invoice_id FK "nullable until grouped"
        varchar charge_type "rent|insurance|late_fee|lien_fee|other"
        numeric amount "NUMERIC(10,2) debit"
        date due_date
        text description "nullable"
        bigint reversal_of_charge_id FK "nullable; opposing entry"
        timestamptz created_at
    }

    payments {
        bigint id PK
        bigint lease_id FK
        numeric amount "NUMERIC(10,2) credit"
        varchar stripe_payment_intent_id "nullable"
        varchar idempotency_key UK
        bigint reversal_of_payment_id FK "nullable; opposing entry"
        timestamptz created_at
    }

    allocations {
        bigint id PK
        bigint payment_id FK
        bigint charge_id FK
        numeric amount "NUMERIC(10,2)"
        timestamptz created_at
    }
```

**Indexes:**
- `charges(lease_id, due_date)` — overdue calculation per charge
- `charges(invoice_id)`
- `allocations(payment_id)`, `allocations(charge_id)`
- `payments(idempotency_key)` UNIQUE

**Derived (not columns):**
- `balance_owed(lease)` = SUM(charges.amount) − SUM(payments.amount)
- `is_overdue(charge)` = charge.due_date < today AND SUM(allocations for charge) < charge.amount
- Overpayment = unallocated payment credit on lease — no row deletion, no flag stored

**Rules:**
- All rows append-only; reversals INSERT opposing entries with `reversal_of_*_id`
- Late-fee and lien eligibility driven by `charges.charge_type` + `charges.due_date` + configurable jurisdiction rules (future table)

---

## 7. Tenant DB — Stripe Reconciliation

```mermaid
erDiagram
    payments ||--o| stripe_webhook_events : confirmed_by

    stripe_webhook_events {
        bigint id PK
        varchar stripe_event_id UK
        varchar event_type
        jsonb payload
        varchar processing_status "pending|processed|failed"
        bigint payment_id FK "nullable; linked after reconcile"
        timestamptz received_at
        timestamptz processed_at "nullable"
    }
```

**Integration notes:**
- No `company_id` column — the row is already in the tenant's own DB.
- Webhook routing: platform receives event, reads `account` from Stripe payload, looks up `tenants(stripe_connect_account_id)`, routes to that tenant's DB.
- Payment confirmation from webhooks + `idempotency_key` — never optimistically from the client.
- Ledger is the system of record; Stripe events are inputs reconciled against it.
- Accounts v2; connected account is the merchant of record wherever possible.

---

## 8. Tenant DB — Dynamic Properties (Polymorphic)

Operator-defined custom fields for any entity. Currently attached to `units`; extensible to `contacts` or any other model with no schema change.

```mermaid
erDiagram
    property_definitions ||--o{ property_values : defines

    property_definitions {
        bigint id PK
        varchar entity_type "morph class e.g. App\\Models\\Unit"
        varchar key "machine-readable slug e.g. floor_level"
        varchar label "human label e.g. Floor Level"
        varchar data_type "text|integer|decimal|boolean|date|select"
        jsonb options "nullable; allowed values for select type"
        boolean required
        int display_order
        timestamptz created_at
        timestamptz updated_at
    }

    property_values {
        bigint id PK
        bigint property_definition_id FK
        varchar propertable_type "morph type"
        bigint propertable_id "morph id"
        text value "always text; cast at read time using data_type"
        timestamptz created_at
        timestamptz updated_at
    }
```

**Indexes:**
- `property_definitions(entity_type, key)` UNIQUE
- `property_values(propertable_type, propertable_id)`
- `property_values(property_definition_id, propertable_type, propertable_id)` UNIQUE

**Notes:**
- `value` always stored as text — cast to correct type at read time using `data_type`
- `options` as JSON on the definition row — select choices live with their definition
- Use `Relation::morphMap()` to shorten entity_type strings (e.g. `unit` instead of `App\Models\Unit`)

---

## 9. Tenant DB — Tasks

Polymorphic task system. Attachable to `Deal` and `Contact` today; extensible to any entity with no schema change.

```mermaid
erDiagram
    employees ||--o{ tasks : assigned_to
    employees ||--o{ tasks : created_by

    tasks {
        bigint id PK
        varchar taskable_type "App\\Models\\Deal | App\\Models\\Contact"
        bigint taskable_id
        bigint assigned_to FK "employees.id nullable"
        bigint created_by FK "employees.id"
        varchar title
        text description "nullable"
        varchar priority "low|medium|high|urgent"
        varchar status "open|in_progress|done|cancelled"
        timestamptz due_at "nullable"
        timestamptz remind_at "nullable"
        timestamptz completed_at "nullable"
        timestamptz created_at
        timestamptz updated_at
    }
```

**Indexes:**
- `tasks(taskable_type, taskable_id)`
- `tasks(assigned_to, status)`
- `tasks(due_at, status)`
- `tasks(remind_at) WHERE remind_at IS NOT NULL AND status NOT IN ('done','cancelled')` — partial index for reminder scheduler (PostgreSQL)

---

## 10. Tenant DB — Comments

Append-only comment log. Attachable to `Contact`, `Deal`, `Task`, and `Reservation`.

```mermaid
erDiagram
    employees ||--o{ comments : created_by

    comments {
        bigint id PK
        varchar commentable_type
        bigint commentable_id
        bigint employee_id FK "employees.id"
        text content
        timestamptz created_at
    }
```

**Indexes:**
- `comments(commentable_type, commentable_id)`
- `comments(employee_id)`

**Notes:**
- No `updated_at` — comments are never edited; corrections are new comments
- Redaction extension path: add `is_redacted boolean` + `redacted_by bigint FK` without breaking existing queries

---

## 11. Relationship cardinality summary

| From | To | Cardinality | Notes |
|------|----|-------------|-------|
| Site | Unit | 1:N | Flat hierarchy — no Building |
| UnitClass | Unit | 1:N | Class = product definition; Unit = physical instance |
| UnitClass + Site | Price | N:M via UnitClassRate | Site-specific class pricing |
| Contact | Deal | 1:N | Identity vs pursuit |
| Deal | Offer | 1:N | Multiple proposals per deal |
| Offer | OfferOption | 1:N | Max 1 selected (partial unique index) |
| OfferOption | Reservation | 1:0..1 | Created on accept in same transaction |
| Reservation | Lease | 1:0..1 | Created at contract signing |
| Lease | Charge, Payment | 1:N | Ledger anchor |
| Payment | Charge | N:M via Allocation | Partial payments, cross-invoice |
| Charge | Invoice | N:1 | Grouped by billing period |

---

## 12. Open / TBD items

1. **Discount model** — `discount_type`, `value`, and effective-date rules need formalization before this table is used in production logic.
2. **Revenue model** — application fee vs SaaS subscription (or both) affects a future `platform_charges` table in the Platform DB.
3. **Jurisdiction rules** — configurable late-fee / lien thresholds need a future `jurisdiction_rules` table in the Tenant DB.
4. **InsurancePlanRate scoping** — whether rates vary by site is not yet explicit in the spec; current ERD shows plan-level junction only.
5. **Promotions** — separate from `Discount` per the original spec; stub only until further definition.
6. **Property validation** — server-side validation of `property_values.value` against `data_type` and `options` (application layer vs DB check constraint) is undecided.
7. **Task reminder delivery** — channel (in-app, email, push) not yet decided; `remind_at` column is channel-agnostic.
8. **Comment redaction** — no delete/edit in schema by design; GDPR/compliance redaction extension path is documented above.
