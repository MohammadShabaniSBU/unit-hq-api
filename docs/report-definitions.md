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

---

## Occupancy / Ocupación

### Unit occupancy / Ocupación por unidad

**Formula:** occupied units ÷ rentable units.

| Term (EN) | Term (ES) | Definition |
|---|---|---|
| Occupied unit | Unidad ocupada | An open `unit_occupancies` row covering the as-of date. |
| Rentable unit | Unidad alquilable | Non-archived unit that is **not** under a blocking non-reservation hold on that date. |
| Blocking hold | Bloqueo no alquilable | Hold types `maintenance`, `damaged`, `staff`, `other` — the unit cannot be rented, so it is excluded from the denominator. Reservation / overlock holds do **not** remove a unit from rentable. |

A unit you cannot rent must not count against occupancy. That is the industry
standard this product ships.

### Area occupancy / Ocupación por superficie

**Formula:** occupied m² ÷ rentable m².

Same occupied / rentable rules as unit occupancy. Area comes from unit
dimensions (`units` floor area).

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
