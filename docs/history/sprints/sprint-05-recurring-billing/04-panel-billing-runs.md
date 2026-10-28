# S05-04 — Panel: billing runs & next-bill surfaces

## Context

A money job nobody can inspect is a support nightmare. Operators need three answers at a
glance: *did billing run and how did it go*, *why did this contract fail*, and *when does
this tenant bill next*. This task is read-surfaces only — the engine already records
everything.

## Scope

**In:** Billing → Billing runs list + detail, failure-attention affordances, next-bill
info on contract detail, "Run billing now" action.
**Out:** editing anything (runs are append-only), payment status (S06), dunning surfaces
(S07).

## Panel surface

### Billing → Billing runs (new page)

List: started (site-relative display), trigger badge, duration,
`billed / skipped / failed` counts with the failed count red when > 0, total billed
(grouped per currency — do not sum across currencies). Header: **Run billing now** →
confirm modal offering *Preview (dry run)* first; preview renders the would-bill table
inline (contract link, periods, window, est. amount) before a real run is offered.

Detail: run header + items table, filter tabs `All · Billed · Skipped · Failed`
(failed default-selected when any exist). Per item: contract link, outcome, periods,
amount, invoice links, and for failures the reason key translated + full message in a
disclosure. Failure rows get a context action: `fiscal_blocker` → "Complete fiscal data"
deep-links the contact's fiscal card (S03-01); `catch_up_cap` / `currency_mismatch` →
"View contract".

### Contract detail

Billing summary card gains: **Next bill** — next period window + estimated gross (computed
via `BillingMath::nextPeriod` + `itemsOn`, displayed, never stored), and **Billed through**
already shown gains a tooltip explaining the cursor. A contract whose last run item was
`failed` shows an amber banner linking the run detail — the operator should not need to
find the runs page to learn their contract is stuck.

### Empty/edge states

No runs yet → explain the schedule and offer the first manual run. A run with zero
considered contracts renders honestly ("nothing due"), not as an error.

i18n `billing.runs.*`; es: run → *Ejecución de facturación*, billed → *Facturado*,
skipped → *Omitido*, failed → *Fallido*, next bill → *Próxima factura*.

## Implementation notes

`useBillingRunList` / `useBillingRun` composables; types in `app/types/billing.ts`
(`Array<T>`); reuse the S01 badge-component pattern for outcomes. The next-bill estimate
endpoint: `GET /api/contracts/{id}/next-bill` returning `{ window, amount, currency } |
null` — computed server-side through the same helpers as the job (never a panel-side
reimplementation; the S02-03 preview lesson).

## Acceptance criteria

- [ ] Runs list + detail render seeded runs incl. every failure reason with its context
      action working.
- [ ] Dry-run preview table renders before any real manual run is possible.
- [ ] Contract detail shows next-bill window/amount matching what the next run then
      actually bills (fixture asserts equality).
- [ ] Failed-contract banner appears and links; clears after a successful run.
- [ ] Multi-currency run totals grouped, never summed.
- [ ] `lint` + `typecheck`; `en/es/fr` complete.

## Tests required

API-side: `NextBillEndpointTest::matches_job_output` (the equality fixture),
`BillingRunApiTest::list_detail_filters`. Panel: manual script in the PR — seeded runs
show all outcomes (1), preview-then-run flow (2), fiscal-blocker deep link lands on the
fiscal card (3), failed banner on contract clears post-fix (4), currency grouping (5).
