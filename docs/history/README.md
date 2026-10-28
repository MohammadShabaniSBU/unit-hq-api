# Sprint history

Archived history. Read for rationale ("why was it built this way"). The current truth is in `docs/domain/` and `docs/reference/`.

Status comes from code (models, migrations, commands, tests), not from task checkboxes. Bare sprint IDs in code comments (`S15`, `S22-03`) resolve through this table.

| ID | Folder | Topic | Domain doc(s) it changed | Status |
|---|---|---|---|---|
| S01 | [sprint-01-occupancy-and-holds](sprints/sprint-01-occupancy-and-holds/) | Unit occupancy and holds | [02-facility](../domain/02-facility.md) | shipped |
| S02 | [sprint-02-pricing-model](sprints/sprint-02-pricing-model/) | Contract lifecycle and pricing | [03-pricing](../domain/03-pricing.md), [04-crm-pipeline](../domain/04-crm-pipeline.md), [05-billing-ledger](../domain/05-billing-ledger.md) | shipped |
| S03 | [sprint-03-legal-entities-fiscal-nvoices](sprints/sprint-03-legal-entities-fiscal-nvoices/) | Legal entities and fiscal invoices | [architecture-payments-and-fiscal](../domain/architecture-payments-and-fiscal.md), [05-billing-ledger](../domain/05-billing-ledger.md) | shipped |
| S04 | [sprint-04-verifactu](sprints/sprint-04-verifactu/) | Verifactu | [architecture-payments-and-fiscal](../domain/architecture-payments-and-fiscal.md) | partial |
| S05 | [sprint-05-recurring-billing](sprints/sprint-05-recurring-billing/) | Recurring billing | [05-billing-ledger](../domain/05-billing-ledger.md) | shipped |
| S06 | [sprint-06-stripe-cards-and-autopay](sprints/sprint-06-stripe-cards-and-autopay/) | Stripe cards and autopay | [architecture-payments-and-fiscal](../domain/architecture-payments-and-fiscal.md), [05-billing-ledger](../domain/05-billing-ledger.md) | shipped |
| S07 | [sprint-07-delinquency-engine](sprints/sprint-07-delinquency-engine/) | Delinquency engine | [05-billing-ledger](../domain/05-billing-ledger.md) | shipped |
| S08 | [sprint-08-automation-engine-hardening](sprints/sprint-08-automation-engine-hardening/) | Automation engine hardening | [12-automation-engine](../domain/12-automation-engine.md) | shipped |
| S09 | [sprint-09-playbooks](sprints/sprint-09-playbooks/) | Playbooks | [13-playbooks](../domain/13-playbooks.md) | shipped |
| S10 | [sprint-10-comms-infrastructure](sprints/sprint-10-comms-infrastructure/) | Comms infrastructure | [06-communications](../domain/06-communications.md) | shipped |
| S11 | [sprint-11-threads-inbox](sprints/sprint-11-threads-inbox/) | Threads and inbox | [06-communications](../domain/06-communications.md) | shipped |
| S12 | [sprint-12-voice-sms-surfaces](sprints/sprint-12-voice-sms-surfaces/) | Voice and SMS surfaces | [06-communications](../domain/06-communications.md) | shipped |
| S13 | [sprint-13-template-builder](sprints/sprint-13-template-builder/) | Template builders | [06-communications](../domain/06-communications.md) | shipped |
| S14 | [sprint-14-e-signature](sprints/sprint-14-e-signature/) | E-signature | [04-crm-pipeline](../domain/04-crm-pipeline.md) | shipped |
| S15 | [sprint-15-access-control](sprints/sprint-15-access-control/) | Access control | [02-facility](../domain/02-facility.md) | shipped |
| S16 | [sprint-16-reporting-analytics](sprints/sprint-16-reporting-analytics/) | Reporting and analytics | [11-insights](../domain/11-insights.md), [report-definitions](../reference/report-definitions.md) | shipped |
| S17 | [sprint-17-discounts](sprints/sprint-17-discounts/) | Discounts | [03-pricing](../domain/03-pricing.md) | shipped |
| S18 | [sprint-18-rbac](sprints/sprint-18-rbac/) | Authorization and RBAC | [07-people-and-auth](../domain/07-people-and-auth.md) | shipped |
| S19 | [sprint-19-ai-agent](sprints/sprint-19-ai-agent/) | Copilot background execution, broadcast, usage metering | [14-ai-agents](../domain/14-ai-agents.md) | shipped |
| S20 | [sprint-20-demo-floor-plans](sprints/sprint-20-demo-floor-plans/) | Demo floor plans | [02-facility](../domain/02-facility.md) | shipped |
| S21 | [sprint-21-insights-pluggable-surface](sprints/sprint-21-insights-pluggable-surface/) | Insights as a pluggable surface | [11-insights](../domain/11-insights.md) | shipped |
| S22 | [sprint-22-customer-facing-ai-agents](sprints/sprint-22-customer-facing-ai-agents/) | Customer-facing AI agents | [14-ai-agents](../domain/14-ai-agents.md) | shipped |
| S23 | [sprint-23-voice-copilot](sprints/sprint-23-voice-copilot/) | Vocal Bridge voice for the CRM copilot | [14-ai-agents](../domain/14-ai-agents.md) | shipped |
| S24 | [sprint-24-agent-pipeline-writes](sprints/sprint-24-agent-pipeline-writes/) | Agent pipeline writes | [14-ai-agents](../domain/14-ai-agents.md), [04-crm-pipeline](../domain/04-crm-pipeline.md) | shipped |
| S25 | [sprint-25-agent-conversation-correctness](sprints/sprint-25-agent-conversation-correctness/) | Agent conversation correctness | [14-ai-agents](../domain/14-ai-agents.md) | shipped |
| S26 | [sprint-26-agent-channels](sprints/sprint-26-agent-channels/) | Live channels | [14-ai-agents](../domain/14-ai-agents.md), [06-communications](../domain/06-communications.md) | shipped |
| S27 | [sprint-27-unified-customer-agent](sprints/sprint-27-unified-customer-agent/) | Unified customer agent | [14-ai-agents](../domain/14-ai-agents.md) | partial |
| S28 | [sprint-28-customer-facing-voice-via-vocal-bridge-delegation](sprints/sprint-28-customer-facing-voice-via-vocal-bridge-delegation/) | Customer-facing voice via Vocal Bridge | [14-ai-agents](../domain/14-ai-agents.md) | shipped |
| S29 | [sprint-29-template-versioning](sprints/sprint-29-template-versioning/) | Template versioning | [06-communications](../domain/06-communications.md) | shipped |
| S30 | [sprint-30-marketing-audience-and-consent](sprints/sprint-30-marketing-audience-and-consent/) | Marketing audience and consent | [06-communications](../domain/06-communications.md) | shipped |
| S31 | [sprint-31-campaigns-newsletters-and-announcements](sprints/sprint-31-campaigns-newsletters-and-announcements/) | Campaigns, newsletters, and announcements | [06-communications](../domain/06-communications.md) | shipped |
| S32 | [sprint-32-paid-advertising-google-ads-meta](sprints/sprint-32-paid-advertising-google-ads-meta/) | Paid advertising (Google Ads and Meta) | [15-advertising](../domain/15-advertising.md) | shipped |
| S33 | [sprint-33-email-blocks-v2](sprints/sprint-33-email-blocks-v2/) | Email blocks v2 | [06-communications](../domain/06-communications.md) | shipped |
| S34 | [sprint-34-template-assistant](sprints/sprint-34-template-assistant/) | Template assistant | [14-ai-agents](../domain/14-ai-agents.md) | shipped |
| S35 | [sprint-35-template-assistant-breadth-quality](sprints/sprint-35-template-assistant-breadth-quality/) | Template assistant breadth and quality | [14-ai-agents](../domain/14-ai-agents.md) | not started |
| S36 | [sprint-36-paid-advertising-follow-ups](sprints/sprint-36-paid-advertising-follow-ups/) | Paid advertising follow-ups | [15-advertising](../domain/15-advertising.md) | not started |

S04 is partial: `EsVerifactuAdapter` writes an `invoice_fiscal_records` row with `hash` and `prev_hash` null, and its comment says the hash chain and AEAT submission are still S04. S27 is partial: concierge fixtures exist under `tests/Fixtures/agents/concierge/`, and that directory has no `cassettes/` files. S35 is not started: no translate-variant, HTML-patch, or `assist.disabled` code. S36 is not started: no Data Manager upload, platform lead-form intake, or audience-sync code.

The original delivery roadmap is kept as [roadmap-original.md](roadmap-original.md).

## Reference artefacts

These files stayed inside the archived sprint folder, except the customer voice config, which the app writes at runtime.

- [sprint-20-demo-floor-plans/FloorPlanGenerator.php](sprints/sprint-20-demo-floor-plans/FloorPlanGenerator.php) — early copy of the floor-plan SVG renderer. The running copy is `app/Support/Facility/FloorPlanGenerator.php`. Nothing reads this file.
- [sprint-23-voice-copilot/MANUAL-QA.md](sprints/sprint-23-voice-copilot/MANUAL-QA.md) — manual checks for copilot voice (panel CI does not run them).
- [sprint-23-voice-copilot/vb-config.json](sprints/sprint-23-voice-copilot/vb-config.json) — paste-ready Vocal Bridge prompt for the staff copilot. No code reads or writes it.
- [sprint-28-customer-facing-voice-via-vocal-bridge-delegation/S28-05-findings.md](sprints/sprint-28-customer-facing-voice-via-vocal-bridge-delegation/S28-05-findings.md) — disclosure, recording, and residency findings for customer voice. Launch gates, not product behaviour.
- `vb-customer-config.json` moved to [docs/ops/voice-bridge/](../ops/voice-bridge/). `ExportVoiceBridgeConfigCommand` writes it at runtime from `VoiceBridgeCustomerConfig::RELATIVE_PATH`.
