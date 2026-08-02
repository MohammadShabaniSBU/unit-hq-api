# S16-03 — Movement & funnel

## Context

The flow reports: tenancy movement (who came, who left, who moved within — with
transfers finally counted as the third thing they are) and the pipeline funnel
(enquiry → deal → offer → signed, with the lead-chase playbook's contribution
visible). These grade the S09 promise and vindicate the S02-03 one-contract-two-
occupancies decision in a single chart.

## Scope

**In:** movement report, tenure distribution, funnel report + lead-chase
effectiveness, offer performance (small — the offers data has waited since the
beginning). **Out:** marketing-source attribution beyond the deal's source field,
cohort retention curves (recorded — the occupancy facts support them later).

## Movement

Period-filtered: move-ins (occupancies opened, excluding transfer-destinations),
move-outs (ended `vacated` / `non_payment`, split — the involuntary line matters),
transfers (counted once, with up/down/same-class split and the rate delta —
"transfers grew revenue €X/mo" is the upsell machinery graded), net units + net m² +
net monthly-rent effect, all per site. Below: the churn context — average tenure of
the period's leavers (occupancy spans make this one query), move-out reasons from
`ended_reason`, and deposit-settlement outcomes for the leavers (full refund vs
deductions — the dispute-rate proxy). The fixture: a seeded quarter where
`ins − outs + 0×transfers = Δoccupied` — transfers cancelling out *is* the test.

## Funnel

Period cohort by deal-created: deals → offers sent → offers viewed (the
`first_viewed` stamps) → accepted → contracts signed (incl. the S14 remote path,
split walk-in vs remote — the e-sign adoption number), with stage-drop counts and
median days between stages. Lead-chase columns: enrolled vs not (the S09 filters
create a natural comparison — labelled **correlation**, the 02 caveat verbatim),
exits by cause, steps-received distribution of converters. Offer performance: options
per offer vs acceptance, price-band acceptance (the S03 pricing data earning its
keep).

Sources: deals + offers + `offer_deliveries` + playbook enrolments (runs by
lineage — the S09 aggregate endpoint's query reused, not reimplemented).

## Acceptance criteria

- [ ] Movement fixture: the transfers-cancel identity holds; involuntary split
      correct; rate-delta math to the cent; tenure query bounded.
- [ ] Funnel: a seeded cohort's every stage count hand-verified; median-days on
      known gaps; walk-in/remote split ties to `signature_mode` reality.
- [ ] Lead-chase comparison renders with the correlation caveat; enrolment counts
      tie to the S09 endpoint (consistency fixture — one lineage query, two
      consumers).
- [ ] Standard contract: filters, CSV, print, bounded, currency-grouped where money.

## Tests required

| Test | Asserts |
|---|---|
| `MovementTest::transfers_cancel_identity` | The vindication |
| `MovementTest::involuntary_split_and_rate_delta` | The grades |
| `FunnelTest::cohort_stages_and_medians` | Hand-verified seed |
| `FunnelTest::enrolment_consistency_with_s09` | One query, two consumers |
