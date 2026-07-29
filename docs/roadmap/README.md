# unit-hq — Delivery Roadmap

> **Audience:** the solo maintainer + Cursor.
> **Horizon:** 17 one-week sprints to a Spain-deployable product.
> **Read first:** `09-conventions-and-invariants.md`. Every task file below assumes those
> invariants hold. If a task appears to require breaking one, stop and flag it — do not
> silently comply.

---

## 1. Where we are

The current build is a **leasing pipeline with a first-period charge generator**. It can
take a contact through to a signed contract and produce the opening charges. It cannot yet:

- bill month two (the recurring job does not exist — only the `billed_through` cursor)
- end, transfer, or re-rate a contract
- take money without a human
- issue a fiscally valid invoice in Spain
- chase a debt
- talk to a tenant on any channel

Everything in this roadmap closes one of those gaps, in dependency order.

## 2. Deployment context (drives sequencing)

| Fact | Consequence |
|---|---|
| First deploy is **Spain** | Verifactu compliance is a launch blocker, not a backlog item |
| Clients also in **France, UK** | Multi-currency, multi-VAT, `fr` locale, Factur-X-shaped export later |
| **Mono-tenant**, one install per operator | No tenancy work; but config must be data, not env constants |
| **Solo maintainer + Cursor** | Sprints are one week, tasks are single-session sized |
| **Tenant portal: out of scope** | Contacts still do not log in. Revisit after S17. |
| **E-sign: third-party, multi-vendor** | Adapter interface; Signable is adapter #1 |
| **Access control: third-party, multi-vendor** | Adapter interface; Sensorberg is adapter #1 |
| **Stripe Connect dropped** — one Stripe account **per legal entity** | No platform account, no money transmission. Credentials are per-entity config. Supersedes `05-billing-ledger.md` §Stripe Connect. |
| **SEPA Direct Debit is the primary rail in ES/FR** | Bank-file based (Cuaderno 19-14 / `pain.008`), not processor-based. Collection ≠ payment. |
| **Verifactu is a toggle** | Per-entity `fiscal_regime`, off by default. Enabling starts a chain; disabling never breaks one. |
| **`legal_entities` is a new first-class concept** | The invoice issuer. Sites belong to one. Verifactu chains, invoice series, VAT registration and payment credentials are all scoped to it — **never used to scope queries.** See `architecture-payments-and-fiscal.md`. |

### Regulatory notes

**Spain — Verifactu (RD 1007/2023).** Mandatory for companies subject to corporate income
tax since 2026-01-01. Requires a *registro de facturación* per issued invoice, cryptographically
chained to the previous record (carrying prior issuer NIF, series+number, issue date), a QR
code on the invoice, an unalterable event log, an integrity alarm, and correction by
*registro de anulación* rather than mutation. Penalties reach €50k/year for the operator and
€150k/year for the software vendor. **Verify the exact obligation for the client's entity type
with their gestor before S05 starts** — the autónomos deadline moved under RD 254/2025 and
sources disagree.

**France — e-invoicing reform.** Reception obligatory for all VAT-registered companies from
2026-09-01; emission for SMEs from 2027-09-01, in structured formats (Factur-X / UBL / CII)
via an accredited platform. B2C is excluded. Self-storage is mostly B2C, so this is a 2027
concern — but the invoice model built in S03 must be able to carry the structured fields
(SIREN/SIRET, VAT number, buyer identifiers) without another migration.

**Spain/France payments.** SEPA Direct Debit dominates over cards. Mandates, pre-notification,
and R-transaction (return) handling are first-class, not an afterthought. SDD failure feedback
is slow (days), which sets the minimum spacing of dunning steps.

**Delinquency in the EU.** There is no US-style lien-and-auction statute. Recovery is
contractual right of retention + civil claim. The delinquency module models **notice sequence,
overlock, access revocation, and a defensible audit trail** — the escalation ladder is
configuration, not hard-coded law.

## 3. Sprint sequence

Each phase leaves the product materially more sellable than the last.

### Phase A — Make it operable (S01–S04)

| Sprint | Theme | Exit criterion |
|---|---|---|
| **S01** | Unit occupancy & holds | A unit cannot be double-booked; availability is a query over fact tables, not a scan of contract items |
| **S02** | Contract lifecycle | Vacate, transfer, and scheduled rate change work end-to-end with correct ledger effects |
| **S03** | Fiscal invoice model | Invoices are numbered fiscal documents with series, immutability, and credit notes — no longer a display grouping |
| **S04** | Recurring billing job | Month two bills itself, idempotently, with an observable billing run |

### Phase B — Compliance & collection (S05–S07)

| Sprint | Theme | Exit criterion |
|---|---|---|
| **S05** | Verifactu | Under a `verifactu` site, every issued invoice produces a chained, hashed registro with QR; AEAT test submission works; integrity alarm implemented; regime toggles per site without breaking existing chains |
| **S06** | Payment methods & per-site Stripe | Each site holds its own Stripe credentials; cards on file; webhooks routed and signature-verified per site |
| **S07** | SEPA Direct Debit | Mandates, pre-notification, Cuaderno 19-14 / `pain.008` batch export, returns import; collection state is strictly separate from payment |

### Phase C — Money recovery (S08–S10)

| Sprint | Theme | Exit criterion |
|---|---|---|
| **S08** | Delinquency engine | Overdue contracts progress through a configurable escalation ladder with late fees, overlock state, and a notice audit trail |
| **S09** | Automation engine hardening | Branching is correct under test; `waiting` status exists; a fixture-driven run harness proves it |
| **S10** | Playbooks: debt process & lead chase | Both ship as linear step sequences with exit conditions, on the same execution engine, with purpose-built UIs |

### Phase D — Communications (S11–S14)

| Sprint | Theme | Exit criterion |
|---|---|---|
| **S11** | Comms infrastructure | Provider adapters (Postmark, Brevo, Sinch, Aircall) configurable from Settings; inbound webhooks idempotent; consent enforced pre-send |
| **S12** | Threads & Inbox | One unified inbox across email/SMS/WhatsApp/calls, with assignment and read state |
| **S13** | Voice & SMS surfaces | Aircall click-to-call + call logging; Sinch two-way SMS |
| **S14** | Template builders | Email builder v2 + WhatsApp template manager with provider approval sync |

### Phase E — Integrations & operations (S15–S17)

| Sprint | Theme | Exit criterion |
|---|---|---|
| **S15** | E-signature | Adapter interface + Signable; signed PDF stored immutably against the contract |
| **S16** | Access control | Adapter interface + Sensorberg; overdue → access revoked automatically |
| **S17** | Reporting & RBAC | Rent roll, three occupancy measures, delinquency ageing, daily close; `canEdit` stopgap removed |

## 4. Cross-cutting decisions to lock before S01

These get more expensive every sprint. Resolve them in the S01 kickoff, record them in
`10-open-decisions.md`.

1. **Currency authority.** `sites` carries a currency and `BillingSettings.default_currency`
   exists. With clients in ES/FR/UK, **site-level must win**; org-level becomes the default
   for new sites. Document it, add the fallback resolution helper, stop reading org currency
   directly.
2. **VAT is per-site, not per-org.** ES 21% / FR 20% / UK 20% with different codes. The
   `tax_rates` catalogue needs a `site_id` scope or a jurisdiction filter that the resolution
   order in `03-pricing.md` step 3 honours.
3. **Charge types to add now:** `adjustment`, `write_off`, `refund`. Dunning and vacate both
   produce them, and adding enum values later means backfilling.
4. **Locale `fr`** added alongside `en`/`es` in the panel, even if translations lag.
5. **Naming:** the fiscal document is `Invoice`; the tenant-facing statement of what is owed
   right now is a **Statement** (a view, never a stored row). Do not conflate them.

### D6 — Payments architecture (supersedes Stripe Connect)

**Stripe Connect is dropped.** There is no platform account and no money distribution, which
removes the PSD2 money-transmitter question entirely rather than mitigating it.

- Each **site** holds its own payment provider credentials (`payment_provider_accounts`,
  encrypted at rest, `site_id` scoped).
- Stripe handles **cards**. Webhooks are routed and signature-verified per site — each site's
  Stripe account has its own endpoint secret.
- **SEPA Direct Debit is bank-file based**, not processor based: Cuaderno 19-14 / `pain.008`
  export handed to the operator's bank, returns imported days later.

**Invariant 11 is rewritten** (see D7 note below). The critical rule:

> Generating a direct-debit collection file is **not** a payment. It creates a `collection`
> in `submitted` state. A `payments` row is written only when provider-authoritative evidence
> arrives — a verified Stripe webhook, or an imported bank settlement/returns file.

Getting this wrong marks every Spanish contract paid at export time and silently stops
dunning against real debtors.

### D7 — Fiscal regime is per-site and toggleable

`sites.fiscal_regime` — `none` (default) | `verifactu`. Plus `fiscal_regime_enabled_from`.

Enabling begins a hash chain. **Disabling stops new registros but never breaks, edits, or
deletes an existing chain** — chain integrity is precisely what is being attested. The switch
is "start/stop issuing", not "feature off".

Same shape later accommodates `ticketbai` (Basque Country) and `factur_x` (France) without
another rework. Design the enum and the `FiscalRegime` adapter interface accordingly in S03,
even though only `verifactu` is implemented in S05.

## 5. Task file conventions (how Cursor should consume this)

Every sprint is a directory:

```
docs/roadmap/sprint-NN-slug/
  README.md          # goal, exit criteria, task order, risks
  01-task-slug.md
  02-task-slug.md
  ...
```

Every task file has the same eight sections:

| Section | Purpose |
|---|---|
| **Context** | Why this exists; what breaks without it |
| **Scope** | Explicit in/out list — the guard against Cursor drifting |
| **Schema changes** | Exact migrations, column types, indexes, constraints |
| **Implementation notes** | Where code goes, which existing helpers to reuse |
| **API surface** | Endpoints, request/response shapes |
| **Panel surface** | Pages/components, i18n keys, states to handle |
| **Invariants** | Which rules in `09` this must respect, quoted |
| **Acceptance criteria** | Checklist; each item independently verifiable |
| **Tests required** | Named test cases that must exist and pass |

### Rules for Cursor, to repeat at the top of each session

- Read `09-conventions-and-invariants.md` and the task file before writing code.
- No `app/Services/`. Shared helpers go under `App\Support\`.
- Money is `NUMERIC(10,2)`, bcmath via `BillingMath`, never floats, never a stored balance.
- Multi-step writes are one DB transaction.
- Every panel string goes through i18n. Arrays are `Array<T>`.
- Migrations are additive; never edit a shipped migration.
- If a task conflicts with an invariant, stop and report — do not improvise a workaround.

## 6. Definition of done (per sprint)

- [ ] All task acceptance criteria checked
- [ ] `php artisan test` green; new tests named as specified
- [ ] `bun run lint` + `bun run typecheck` green
- [ ] `en.json` and `es.json` complete for new strings (`fr.json` may lag)
- [ ] New invariants appended to `09-conventions-and-invariants.md`
- [ ] Decisions resolved during the sprint moved out of `10-open-decisions.md`
- [ ] Sprint README exit criterion demonstrably met on a seeded database

## 7. Deliberately not in this roadmap

Recorded so they are not accidentally reintroduced:

- Tenant self-service portal / online move-in (deferred by decision)
- Retail POS and inventory (locks, boxes, packing materials)
- Maintenance work orders and unit condition audits
- Multi-tenancy of any kind
- Revenue management / dynamic pricing
- Native mobile apps

The first three are the strongest candidates for S18+.
