# Status

Last updated: 2026-10-28.

Current truth lives in `docs/domain/` and `docs/reference/`. Sprint history is in `docs/history/README.md`.

## Shipped capabilities

- Facility, occupancy, holds, floor plans, access control — `docs/domain/02-facility.md`
- Prices, tax, insurance, discounts — `docs/domain/03-pricing.md`
- Leasing pipeline through signed contract — `docs/domain/04-crm-pipeline.md`
- Recurring billing and the ledger — `docs/domain/05-billing-ledger.md`
- Legal entities, fiscal invoices, Stripe cards and autopay — `docs/domain/architecture-payments-and-fiscal.md`
- Inbox, templates, marketing consent, campaigns, email blocks v2 — `docs/domain/06-communications.md`
- Employees, roles, site scope — `docs/domain/07-people-and-auth.md`
- Activity and system events — `docs/domain/08-activity-logging.md`
- Native reports and embedded analytics — `docs/domain/11-insights.md`
- Automation engine — `docs/domain/12-automation-engine.md`
- Playbooks (debt process, lead chase) — `docs/domain/13-playbooks.md`
- Copilot, concierge agent, live channels, customer voice, template assistant — `docs/domain/ai-agents/README.md`
- Paid advertising read path and conversion upload (Google Ads, Meta) — `docs/domain/15-advertising.md`

## Known gaps

- **Verifactu.** Still a gap. `EsVerifactuAdapter` writes an `invoice_fiscal_records` row with `hash` and `prev_hash` null. The class comment says the hash chain and AEAT submission are still S04. No QR, no submission client.
- **SEPA Direct Debit.** Still a gap. `payment_methods.sepa_mandate_id` is a nullable column with no foreign key. There is no `sepa_mandates` table and no `pain.008` export.

## Partially shipped / follow-ups

- **S27 unified customer agent.** Concierge fixtures exist under `tests/Fixtures/agents/concierge/`. That directory has no recorded `cassettes/`.
- **S35 template assistant breadth and quality.** Not started. No translate-variant, HTML-patch, or `assist.disabled` code.
- **S36 paid advertising follow-ups.** Not started. No Data Manager upload, platform lead-form intake, or audience sync.

## Next up

<!-- TODO(owner) -->
