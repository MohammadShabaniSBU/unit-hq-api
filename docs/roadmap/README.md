# Keevaris — Delivery Roadmap

> **Audience:** the solo maintainer + Cursor.
> **Horizon:** 21 one-week sprints to a Spain-deployable product.
> **Read first:** `09-conventions-and-invariants.md`. Every task file below assumes those
> invariants hold. If a task appears to require breaking one, stop and flag it — do not
> silently comply.

---

## 1. Where we are

Keevaris is a full-featured self-storage operations platform, not just a leasing pipeline.
All 21 sprints (S01–S21) have shipped and are tested, covering:

- **Facility management & leasing** — unit occupancy/holds and a CRM/leasing pipeline through
  to a signed contract, plus a full contract lifecycle (vacate, transfer, scheduled re-rate)
  with correct ledger effects (`ContractTransfer`, `VacatesContracts`/`TransfersContracts`,
  `ContractRateChangeController`)
- **Recurring billing** — an idempotent `billing:run` (`BillingRunCommand`), scheduled hourly,
  bills every active contract past its first period with no manual step
- **Fiscal invoicing** — numbered, immutable, rectifiable invoices issued per legal entity,
  fully shipped (Spain-ready except for Verifactu, see below)
- **Card payments & autopay** — a Stripe integration scoped per legal entity (`StripeCustomer`,
  `PaymentMethod`), with an hourly `AutopayAttempt`-driven autopay command that takes payment
  without a human
- **Delinquency & collections** — a configurable escalation ladder (`Delinquency`,
  `DelinquencyPolicy`/`DelinquencyPolicyStep`) plus purpose-built `Playbook`/`PlaybookStep`
  flows for debt process and lead chase, run daily via `delinquency:run`
- **Unified multi-channel comms** — a full inbox (`InboxController`, `Message`,
  `MessageThread`) spanning email (Postmark, Brevo), SMS/WhatsApp (Sinch), and voice
  (Aircall/`CallController`), with inbound webhooks and suppression/consent enforcement
- **E-signature** — an adapter interface with Signable as the first vendor; signed contract
  PDFs stored immutably
- **Access control** — an adapter interface with Sensorberg; overdue accounts get access
  revoked automatically
- **Automation & authorization** — a hardened, branching automation engine underlies the
  playbooks, and real RBAC replaces the old `canEdit` stopgap
- **Reporting & insights** — rent roll, occupancy measures, delinquency ageing, and a
  pluggable insights registry that later sprints' reports attach to

Two real gaps remain:

- **Verifactu.** Basic fiscal invoicing (S03) is shipped, but the compliance layer required by
  Spain's RD 1007/2023 — the hash-chained `registro de facturación`, QR code, AEAT submission,
  and integrity alarm — is schema-only. Three placeholder columns (`verifactu_hash`,
  `verifactu_prev_hash`, `verifactu_submitted_at`) exist on `invoices`, but no code writes to
  them: there is no AEAT client, no QR/legend rendering, and no chain-verify command. This is
  the single largest remaining launch blocker for Spain.
- **SEPA Direct Debit.** Never started. There is no `sepa_mandates` table or model — only a
  stub `sepa_creditor_id` column on `legal_entities`. No Cuaderno 19-14/`pain.008` export and
  no returns import exist. Card collection via Stripe works; bank-rail collection does not.

Everything in this roadmap either documents what has shipped or closes one of those two
remaining gaps.

## 2. Deployment context (drives sequencing)

| Fact | Consequence |
|---|---|
| First deploy is **Spain** | Verifactu compliance is a launch blocker, not a backlog item |
| Clients also in **France, UK** | Multi-currency, multi-VAT, `fr` locale, Factur-X-shaped export later |
| **Mono-tenant**, one install per operator | No tenancy work; but config must be data, not env constants |
| **Solo maintainer + Cursor** | Sprints are one week, tasks are single-session sized |
| **Tenant portal: out of scope** | Contacts still do not log in. Revisit after S21. |
| **E-sign: third-party, multi-vendor** | Adapter interface; Signable is adapter #1 |
| **Access control: third-party, multi-vendor** | Adapter interface; Sensorberg is adapter #1 |
| **Stripe Connect dropped** — one Stripe account **per legal entity** | No platform account, no money transmission. Credentials are per-entity config. See `architecture-payments-and-fiscal.md`. |
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
with their gestor before implementing S04** — the autónomos deadline moved under RD 254/2025
and sources disagree.

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

> **Status.** All 21 sprints below are shipped and tested, except where the exit criterion
> says otherwise. S04 (Verifactu) is schema-only. SEPA Direct Debit was never scheduled as a
> sprint at all — see §1 — and is not in the table below because no directory for it exists.

### Phase A — Make it operable (S01–S02)

| Sprint | Theme | Exit criterion |
|---|---|---|
| **S01** | Unit occupancy & holds | A unit cannot be double-booked; availability is a query over fact tables, not a scan of contract items |
| **S02** | Contract lifecycle | Vacate, transfer, and scheduled rate change work end-to-end with correct ledger effects |

### Phase B — Fiscal & billing (S03–S06)

| Sprint | Theme | Exit criterion |
|---|---|---|
| **S03** | Legal entities & fiscal invoices | Invoices are numbered fiscal documents with series, immutability, and credit notes, issued by a legal entity — no longer a display grouping |
| **S04** | Verifactu | **Not met.** Schema-only: `verifactu_hash`, `verifactu_prev_hash`, `verifactu_submitted_at` exist on `invoices`, but no hash chain, QR, AEAT submission, or integrity alarm is implemented |
| **S05** | Recurring billing | Month two bills itself, idempotently, via the hourly-scheduled `billing:run` (`BillingRunCommand`) |
| **S06** | Stripe cards & autopay | Each legal entity holds its own Stripe credentials; cards on file; hourly `AutopayCollectCommand` takes payment without a human; webhooks routed and signature-verified per payment-provider account |

### Phase C — Money recovery (S07–S09)

| Sprint | Theme | Exit criterion |
|---|---|---|
| **S07** | Delinquency engine | Overdue contracts progress through a configurable escalation ladder with late fees, overlock state, and a notice audit trail; `delinquency:run` scheduled daily |
| **S08** | Automation engine hardening | Branching is correct under test; `waiting` status exists; a fixture-driven run harness proves it |
| **S09** | Playbooks: debt process & lead chase | Both ship as linear step sequences with exit conditions, on the same execution engine, with purpose-built UIs |

### Phase D — Communications (S10–S13)

| Sprint | Theme | Exit criterion |
|---|---|---|
| **S10** | Comms infrastructure | Provider adapters (Postmark, Brevo, Sinch, Aircall) configurable from Settings; inbound webhooks idempotent; consent enforced pre-send |
| **S11** | Threads & Inbox | One unified inbox across email/SMS/WhatsApp/calls, with assignment and read state |
| **S12** | Voice & SMS surfaces | Aircall click-to-call + call logging; Sinch two-way SMS/WhatsApp |
| **S13** | Template builders | Email builder v2 + WhatsApp template manager with provider approval sync |

### Phase E — Integrations, reporting & pricing (S14–S17)

| Sprint | Theme | Exit criterion |
|---|---|---|
| **S14** | E-signature | Adapter interface + Signable; signed PDF stored immutably against the contract |
| **S15** | Access control | Adapter interface + Sensorberg; overdue → access revoked automatically |
| **S16** | Reporting & analytics | Rent roll, three occupancy measures, delinquency ageing, daily close |
| **S17** | Discounts | A discount catalogue (fixed/percent menu + approval) closes the oldest open pricing decision |

### Phase F — Authorization, AI & platform surfaces (S18–S21)

| Sprint | Theme | Exit criterion |
|---|---|---|
| **S18** | Authorization & RBAC | Real authorization decisions replace the `canEdit` stopgap |
| **S19** | AI agent | The CRM copilot moves from a request-scoped SSE stream to a queued agent with broadcast transport and usage metering |
| **S20** | Demo floor plans | `demo:seed --fresh` leaves every demo site with a complete, plausible set of floor plans |
| **S21** | Insights as a pluggable surface | Insights becomes a registry other sprints' reports plug into, rather than a fixed, empty nav group |

Note: SEPA Direct Debit — mandates, pre-notification, Cuaderno 19-14/`pain.008` batch export,
returns import — was planned in an earlier draft of this roadmap but was dropped before any
sprint directory was created for it. It remains unbuilt; see §1.

## 4. Cross-cutting decisions to lock before S01

These get more expensive every sprint. Resolve them in the S01 kickoff, record them in
`10-open-decisions.md`.

1. **Currency authority.** ~~`sites` carries a currency and `BillingSettings.default_currency`
   exists. With clients in ES/FR/UK, **site-level must win**; org-level becomes the default
   for new sites.~~ **Superseded by D1 (S01-00):** `prices.currency` is the sole authority;
   site and org currency are form prefill defaults only. See `10-open-decisions.md` and
   `09-conventions-and-invariants.md` §29.
2. **VAT is per-site, not per-org.** ES 21% / FR 20% / UK 20% with different codes. The
   `tax_rates` catalogue needs a `site_id` scope or a jurisdiction filter that the resolution
   order in `03-pricing.md` step 3 honours. (Jurisdiction vocabulary locked in S01-00 D2;
   resolution implemented in S03.)
3. **Charge types to add now:** `adjustment`, `write_off`, `refund`. Dunning and vacate both
   produce them, and adding enum values later means backfilling.
4. **Locale `fr`** added alongside `en`/`es` in the panel, even if translations lag.
5. **Naming:** the fiscal document is `Invoice`; the tenant-facing statement of what is owed
   right now is a **Statement** (a view, never a stored row). Do not conflate them.

### D6 — Payments architecture (supersedes Stripe Connect)

> **Superseded by S01-00 D6/D7 and `architecture-payments-and-fiscal.md`.** Credentials and
> fiscal regime scope to `legal_entities`, not to sites. Retained for context; do not
> implement.

Stripe Connect is dropped because there is no platform distributing money — that removes
the PSD2 money-transmitter question outright. Credentials and fiscal identity scope to
**`legal_entities`**, not sites. See `architecture-payments-and-fiscal.md` and
`10-open-decisions.md`.

~~- Each **site** holds its own payment provider credentials (`payment_provider_accounts`,
  encrypted at rest, `site_id` scoped).~~
- Each **legal entity** holds payment provider credentials (S03 schema). Sites belong to one
  legal entity.
- Stripe handles **cards**. Webhooks are routed and signature-verified per entity
  via `payment_provider_accounts` (`account_token` + per-account signing secret).
- **SEPA Direct Debit is bank-file based**, not processor based: Cuaderno 19-14 / `pain.008`
  export handed to the operator's bank, returns imported days later.

**Invariant 11 is rewritten** (rail-specific; see architecture doc). The critical rule:

> Generating a direct-debit collection file is **not** a payment. It creates a `collection`
> in `submitted` state. A `payments` row is written only when provider-authoritative evidence
> arrives — a verified Stripe webhook, or an imported bank settlement/returns file.

Getting this wrong marks every Spanish contract paid at export time and silently stops
dunning against real debtors.

### D7 — Fiscal regime is per-site and toggleable

> **Superseded by S01-00 D6/D7 and `architecture-payments-and-fiscal.md`.** Credentials and
> fiscal regime scope to `legal_entities`, not to sites. Retained for context; do not
> implement.

~~`sites.fiscal_regime` — `none` (default) | `verifactu`. Plus `fiscal_regime_enabled_from`.~~

`fiscal_regime` scopes to **`legal_entities`**, not sites. See
`architecture-payments-and-fiscal.md`.

Enabling begins a hash chain. **Disabling stops new registros but never breaks, edits, or
deletes an existing chain** — chain integrity is precisely what is being attested. The switch
is "start/stop issuing", not "feature off".

Same shape later accommodates `ticketbai` (Basque Country) and `factur_x` (France) without
another rework. Design the enum and the `FiscalRegime` adapter interface accordingly in S03;
`verifactu` is the only regime whose S04 columns exist, and even those are not yet written to
by any code (see §1).

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

The first three are the strongest candidates for S22+.
