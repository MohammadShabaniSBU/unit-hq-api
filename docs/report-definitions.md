# Report definitions

Canonical vocabulary for **native** Insights reports (keyed by `native_key` in
the `insight_reports` registry). The Insights surface itself — registry, embeds,
analytics accounts — is documented in `11-insights.md`. Every native report page
footer links the section it uses. Figures are computed live from fact tables —
nothing here is stored as a rollup.

The native **dashboard** (`GET /api/reports/dashboard`) is card-zoom of these
same definitions — one computation class, two zoom levels. It introduces no new
terms.

Spanish terms (ES) sit beside English so the operator and accountant share one
glossary. Review Spanish in the PR that introduces this doc (`es-reviewed`).

Date windows use the house boundary convention: `started_on <= D < ended_on`
(exclusive ends). As-of dates are site-local civil dates via `SiteClock` (D8).

**Permission split** — `rent-roll`, `ageing`, `collections`,
`deposit-liability`, `daily-close`, `revenue`, `rate-management`, and
`delinquency-trend` require the stricter `Permission::ReportFinancialView`;
every other native report (occupancy, movement, funnel, dashboard, demo,
`length-of-stay`) only requires the baseline `Permission::ReportView`.
Enforced in `ReportController::show` (`FINANCIAL_REPORTS` list).

---

## Rent roll / Lista de rentas

As-of snapshot (`OccupancyMetrics::resolveAsOf`) of every open occupancy:
contract terms are priced as of that date, but balance/overdue figures are
always **current-state** — not reconstructed as of the as-of date (flagged
directly in the report's own `notes`).

**Scope:** open `unit_occupancies` (`started_on <= as_of` and `ended_on` null
or `> as_of`) on enabled units, excluding contracts in `awaiting_signature` or
`cancelled` status. Ordered by site name, then unit number.

| Field (EN) | Field (ES) | Definition |
|---|---|---|
| Monthly rate | Tarifa mensual | Effective `unit` contract-item price as of the as-of date (`ContractItem::effectiveOn`). |
| Insurance | Seguro | Effective `insurance` contract-item price as of the as-of date, if any; otherwise blank. |
| Deposit held | Depósito retenido | If the contract has a `depositSettlement`: `0.00` when the settlement outcome is `released`, else the settlement's snapshot `deposit_amount`. Otherwise the contract's own `deposit_amount`. |
| Balance owed (current) | Saldo actual | `Σ charges.amount − Σ payments.amount` for the contract, computed **today** — not as of the report's as-of date. |
| Overdue (current) | Vencido actual | `Σ` open (unallocated) amount of charges whose `due_date` is before **today**. Same current-state caveat as balance owed. |
| Delinquency days | Días de morosidad | Age, in the contract's site-local civil day (`SiteClock::today`), of the **oldest** unpaid charge (`due_date` < today with open amount > 0). Blank when nothing is overdue. |
| Tenure days | Días de antigüedad | Days between move-in and the as-of date. |
| End date | Fecha de fin | Occupancy `ended_on`; overridden by the contract's `end_date` when status is `notice_given` and `end_date` is set. |
| Overlocked | Bloqueo (overlock) | Unit has an active `unit_holds` row with `hold_type = overlock` and no `released_at`. |
| Access suspended | Acceso suspendido | Contract has an active `AccessSuspension`. |
| Autopay | Domiciliación | `contracts.autopay_enabled` (yes/no). |

Footer totals — units, area m², monthly rent, deposits, overdue — are summed
per currency (never cross-summed, per the currency invariant below).

---

## Deposit liability / Pasivo por depósitos

Accountant view: deposits held vs pending payouts, grouped by site × currency.

| Side | EN | ES | Source |
|---|---|---|---|
| Deposits held | Deposits held | Depósitos retenidos | `Σ contracts.deposit_amount` for contracts in `active` / `notice_given` status **without** a `depositSettlement` row — i.e. still in force and not yet moved to settlement — joined through the contract's current unit item to resolve site. |
| Pending payouts | Pending payouts | Pagos pendientes | `Σ deposit_settlements.refunded_amount` where `payout_status = pending`, same site join. |

| Field (EN) | Field (ES) | Definition |
|---|---|---|
| Contracts | Contratos | Count of in-force contracts contributing to deposits held. |
| Pending settlements | Liquidaciones pendientes | Count of pending settlement rows contributing to pending payouts. |

A contract leaves "deposits held" the moment it gets a `depositSettlement` row
(any outcome): its deposit is then either released, or shows up in "pending
payouts" until the payout is made. Figures are current-state snapshots, not
reconstructed as of a historical date. Totals are also rolled up by currency
alone (`totals_by_currency` in the report meta), independent of site.

---

## Occupancy / Ocupación

### Unit occupancy / Ocupación por unidad

**Formula:** occupied units ÷ rentable units.

| Term (EN) | Term (ES) | Definition |
|---|---|---|
| Occupied unit | Unidad ocupada | An open `unit_occupancies` row covering the as-of date. |
| Rentable unit | Unidad alquilable | Non-archived unit that is **not** under a blocking non-reservation hold on that date. |
| Blocking hold | Bloqueo no alquilable | Hold types `maintenance`, `damaged`, `staff_use`, `other` — the unit cannot be rented, so it is excluded from the denominator. Reservation / overlock holds do **not** remove a unit from rentable. |

A unit you cannot rent must not count against occupancy. That is the industry
standard this product ships.

### Area occupancy / Ocupación por superficie

**Formula:** occupied m² ÷ rentable m².

Same occupied / rentable rules as unit occupancy. Area is the unit's class
`size` (`unit_classes.size`, `numeric(8,2)`), not a per-unit floor-area column.

### Economic occupancy / Ocupación económica

**Formula:** actual in-place rent ÷ gross potential rent.

| Side | EN | ES | Source |
|---|---|---|---|
| Numerator | Actual in-place rent | Renta en vigor | Σ current item-version prices of **unit** items on contracts in `active` / `notice` status as of the date (`itemsOn` read). |
| Denominator | Gross potential rent | Renta potencial bruta | Σ current catalogue price for each rentable unit’s class×site. |

Discounted or legacy-rated tenants pull economic occupancy below unit occupancy —
that gap **is** the report’s point. Rate source for the denominator is the current
catalogue price (S02 ownership model), shown on the page.

Money is grouped by currency, never summed across currencies (invariant 30).

---

## Ageing / Antigüedad de deuda

Buckets are days past due from each **charge** `due_date` (S07 delinquency
anchor vocabulary):

| Bucket (EN) | Bucket (ES) | Days past due |
|---|---|---|
| 1–7 | 1–7 | 1 through 7 |
| 8–14 | 8–14 | 8 through 14 |
| 15–30 | 15–30 | 15 through 30 |
| 31–60 | 31–60 | 31 through 60 |
| 60+ | 60+ | 61 or more |

Two views, same ledger:

1. **Contract view / Vista por contrato** — each contract lands in the bucket of its
   **oldest unpaid** charge.
2. **Totals / Totales** — each unpaid charge amount is bucketed by that charge’s
   own age.

The two views reconcile by construction (fixtures prove it). Buckets sum to total
overdue to the cent within each currency.

---

## Collections / Cobros

### Collections rate / Tasa de cobro

**Period formula:** allocated-to-period-charges ÷ charged, by charge type.

| Term (EN) | Term (ES) |
|---|---|
| Charged | Facturado / cargado |
| Allocated | Imputado / aplicado al cargo |
| Collections rate | Tasa de cobro |

### Promise-kept / Promesa cumplida

A `payment_promised` call wrap-up followed by **any** payment allocation to that
contract within **N days** (default **7**, shown on the report page).

| Term (EN) | Term (ES) |
|---|---|
| Promise-kept | Promesa cumplida |
| Payment promised | Pago prometido |

### Autopay recovery / Recuperación de domiciliación

Failed autopay attempts that are later collected by **any** payment rail
(autopay retry, card link, SEPA, manual).

| Term (EN) | Term (ES) |
|---|---|
| Autopay recovery | Recuperación de domiciliación |

---

## Movement / Movimiento

Period-filtered tenancy flow. Transfers are neither churn nor acquisition (S02).

| Event (EN) | Event (ES) | Rule |
|---|---|---|
| Move-in | Entrada | Occupancy opened in the period; excludes the destination leg of a transfer (`contract_transfers` / `transferred_out` counterparts). |
| Move-out | Salida | Occupancy ended in the period with `ended_reason` `vacated` or `non_payment`. |
| Transfer | Traslado | Counted once from `contract_transfers.transfer_date` — neither churn nor acquisition. |
| Cancelled | Anulado | `cancelled` contracts appear **nowhere** in movement reports. |

| Term (EN) | Term (ES) | Definition |
|---|---|---|
| Voluntary move-out | Salida voluntaria | `ended_reason = vacated`. |
| Involuntary move-out | Salida involuntaria | `ended_reason = non_payment`. |
| Transfer class direction | Dirección de clase | Compare origin vs destination `unit_classes.size`: up / down / same. |
| Rate delta | Delta de tarifa | Destination unit-item price − origin unit-item price, grouped by currency (never cross-summed). |
| Tenure of leavers | Antigüedad de salidas | For period move-outs: `ended_on − started_on` (days) on that occupancy span. |
| Net identity | Identidad neta | `move-ins − move-outs = Δoccupied` over the period; transfers cancel (coefficient 0). |

Deposit-settlement outcomes for leavers (`released` full refund vs `deducted` / `forfeited`) are a dispute-rate proxy, not a ledger total.

---

## Funnel / Embudo

Period cohort by **deal created** (`deals.created_at` in range), filtered by deal site.

| Stage (EN) | Stage (ES) | Fact |
|---|---|---|
| Deals | Oportunidades | Cohort size. |
| Offers sent | Ofertas enviadas | Linked offer with `sent_at` set. |
| Offers viewed | Ofertas vistas | Linked offer with `first_viewed_at` set. |
| Accepted | Aceptadas | Linked offer with `accepted_at` set. |
| Contracts signed | Contratos firmados | Linked contract with `signed_at` set. |

Stage membership is “ever reached,” not mutually exclusive. Median days between consecutive stage stamps grade pipeline speed.

| Term (EN) | Term (ES) | Definition |
|---|---|---|
| Walk-in signature | Firma presencial | Signed contract with no e-sign envelope path (immediate completion). |
| Remote signature | Firma remota | Signed contract that has/had an `esign_envelopes` row (or was created `awaiting_signature`). |
| Lead-chase enrolled | En chase de leads | Cohort deal with an automation run under the lead-chase playbook lineage (`automations.playbook_id`). |
| Correlation caveat | Aviso de correlación | Enrolled vs not comparison is **correlation, not causation**. |
| Price band | Banda de precio | Catalogue option amount buckets: `<50`, `50–99.99`, `≥100` in the option’s currency (S03 price rows; no stored band table). |

Source attribution uses `contacts.source` (join via deal) — there is no `deals.source` column.

---

## Daily close / Cierre diario

Payments for a civil day, grouped **method × employee-causer × site**.

| Source | Date used |
|---|---|
| Manual payments | `received_on` |
| Card / SEPA / other rails | Settlement date |

| Term (EN) | Term (ES) |
|---|---|
| Daily close | Cierre diario |
| Cash subtotal | Subtotal en efectivo |
| Drawer number | Cifra de caja |

The cash-method subtotal is the drawer number operators reconcile against.

---

## Revenue / Ingresos

Period report. The window is the month spine ending at `to` (default as-of),
oldest first, 12 months unless `from` says otherwise, capped at 24.

A charge counts when `charge_type` is `rent`, `insurance`, `late_fee`,
`lien_fee`, `other`, or `adjustment`, and the month of
`COALESCE(period_start, due_date)` falls in the window. `deposit`,
`write_off`, and `refund` are never revenue. Reversal pairs are excluded
(the original and the reversing row). The measure is `net_amount`, falling
back to `amount`. Streams: rent, insurance, fees (`late_fee` + `lien_fee`),
other (`other` + `adjustment`).

RevPAM is rent net divided by the sum of class `size` of enabled units in
scope, grouped by site currency. The denominator is current rentable m². It
is not reconstructed for each past month.

Money is never summed across currencies. Each currency gets its own stacked
column (streams), area (RevPAM), and donut (period mix).

| Term | ES | Meaning |
|---|---|---|
| Revenue | Ingresos | Net of the four streams above. |
| RevPAM | RevPAM | Rent net ÷ current rentable m². |
| Ancillary | Accesorios | Total − rent. |

## Rate management / Gestión de tarifas

As-of report. It answers whether an existing customer is due a rate increase.

Enabled units in scope. An occupancy is open at the as-of date when
`started_on <= as-of` and `ended_on` is null or later than as-of. Contracts
in `awaiting_signature` or `cancelled` are excluded. The in-place rent is the
unit contract line effective on the as-of date. Street rate is the current
catalogue price for that site and class. A tenant is skipped when the
in-place currency differs from the street currency.

Variance is `(in-place − street) / street × 100`. Rent age is the whole
months from the current unit-line `effective_from` to the as-of date (move-in,
rate change, transfer, or correction). Below street means in-place < street.
The monthly gap is street − in-place when below. ECRI-eligible means below
street and rent age of at least 12 months.

The ECRI monthly opportunity is the sum of those gaps. It is a ceiling. It
ignores churn.

Variance bands (lower bound inclusive): below −20%, −20% to −10%, −10% to 0%,
0% to 10%, above 10%. Rent-age bands: 0–5, 6–11, 12–17, 18–23, 24+ months.

| Term | ES | Meaning |
|---|---|---|
| Street rate | Tarifa de calle | Current catalogue price. |
| In-place rent | Renta en vigor | Unit-line price effective on the as-of date. |
| ECRI | Subida a cliente existente | Eligible when below street for at least 12 months. |

## Length of stay / Duración de estancia

A tenancy is a contract, not an occupancy row. Transfers are not exits.

Occupancies with `started_on <= as-of` are grouped by contract. Start is the
earliest `started_on`. If any occupancy is still open, the tenancy is open.
Otherwise the end is the latest `ended_on`, and the exit reason is that
occupancy's `ended_reason`. Active on date D means start ≤ D and (no end, or
end > D). `cancelled` and `awaiting_signature` contracts are excluded.

Tenure bands, in months: under 3, 3–5, 6–11, 12–23, 24–35, 36+.

Leavers ended inside `[from, to]`. Default `from` is `to` minus 12 months.
Only `vacated` (voluntary) and `non_payment` (involuntary) count, matching
Movement. `operator_terminated` and `transferred_out` are not move-outs.

The cohort heatmap is the last 12 move-in months, newest first. Columns are
M0–M12. A cell is the share of that cohort still active on the last day of
(cohort month + k). The cell is empty when that day is after the as-of date
or the cohort is empty.

| Term | ES | Meaning |
|---|---|---|
| Tenancy | Estancia | One contract, across transfers. |
| Leaver | Salida | Vacated or non-payment in the period. |
| Cohort retention | Retención por cohorte | Share still active at each later month-end. |

## Delinquency trend / Evolución de morosidad

Point-in-time overdue balance at each month-end, rebuilt from the ledger.
This is not Ageing, which is current-state only.

Checkpoints are the last 12 months up to `min(to, as-of)`. A past month uses
its last day. The current month uses the as-of date.

Charges in the delinquency trigger types, with `amount > 0` and `due_date`
before the last checkpoint, excluding reversal pairs and charges already
fully allocated before the first checkpoint. Open amount at checkpoint E is
`amount` minus allocations whose `created_at` is at or before the end of day
E, in UTC, not site-local time. A positive open amount with `due_date < E`
falls in an ageing bucket. A contract is delinquent when any open charge is
more than 30 days overdue. The denominator is distinct contracts with an
occupancy active at E.

| Term | ES | Meaning |
|---|---|---|
| Overdue | Vencido | Open amount past due at that checkpoint. |
| Delinquent | En morosidad | More than 30 days overdue. |
| Antigüedad | Antigüedad | Same ageing buckets as the Ageing report. |

## Cross-cutting rules

- **Harvest principle** — no report number is stored. Live bounded queries only.
  Nightly snapshots are a sanctioned future escape hatch if scale demands it
  (see `10-open-decisions.md`).
- **Filters** — site set, period (`from`/`to`), and/or `as_of` as each report needs.
- **Currency** — money columns always carry a currency; never sum across currencies.
- **CSV locale** — export separators/decimals follow `?locale=` (`es` → `;` + `,`;
  otherwise `,` + `.`), UTF-8 BOM for Excel.

Report page footers should link back to the matching section of this document
(e.g. `/docs` in-repo path or the Insights definitions deep-link once shipped).
