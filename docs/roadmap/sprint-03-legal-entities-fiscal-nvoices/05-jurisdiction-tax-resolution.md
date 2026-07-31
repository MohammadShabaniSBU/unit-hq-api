# S03-05 — Jurisdiction-scoped tax resolution (D2)

## Context

Decision D2 (recorded in S01-00, implementation deferred to S03): a `tax_rates` version with
`jurisdiction = NULL` applies anywhere; one with a value applies only to sites in a matching
country. ES 21%, FR 20%, UK 20% must each resolve on their own sites from the same catalogue.
`sites.country_code` exists (S01-00); `tax_rates.jurisdiction` exists as an optional short
code. This task makes the resolver honour them — the last piece invoices need to be correct
per country.

## Scope

**In:** resolution-order change wherever product/org-default tax is resolved (contract item
create, transfer re-snapshot, rate-change re-snapshot), seeded per-country rates, settings
UI touch-up.
**Out:** per-line VAT regimes (reverse charge, exemptions) — out until a real case appears;
changing any snapshotted historical value (nothing re-resolves retroactively, invariant 18
family).

## Behaviour

Resolution order becomes (replacing step 2/3 of `03-pricing.md`):

1. Explicit `tax_rate_id` override on the request — unchanged, no jurisdiction filtering
   (an explicit choice is the operator's responsibility; log it).
2. Product `tax_rate_code` → among active versions of that code, prefer
   `jurisdiction = site.country_code`, else the `jurisdiction IS NULL` version, else
   **fail loudly** (`tax_unresolvable_for_jurisdiction`, 422) — never fall back to a wrong
   country's rate.
3. Org default rate, same preference chain.
4. No tax (0%) — only reachable when neither product nor default names a code at all.

`App\Support\Fiscal\TaxResolver::resolve(?int $overrideId, ?string $code, Site $site): ?TaxRate`
centralises this; grep out the current inline resolution in contract-item create and the
S02 re-snapshot paths and route them through it. Jurisdiction matching is exact
`country_code` equality — no region hierarchies until a case demands them.

Seeder: `vat` code in three versions — `ES 21.00`, `FR 20.00`, `GB 20.00` — plus a
NULL-jurisdiction fallback only if the current seed relies on one; prefer **no** NULL
fallback for `vat` so a mis-countried site fails tests instead of silently taxing at the
wrong rate.

## Panel surface

Settings → Tax rates: show jurisdiction as a badge column; the create/new-version form's
jurisdiction select gets country names (reuse the site form's country list); helper text
explaining NULL = universal. i18n `settings.taxRates.jurisdiction.*`.

## Invariants

- TaxRate immutability (invariant 17) untouched — jurisdiction is part of a version's
  identity, set at creation.
- Snapshots stand: existing contracts/invoices keep their rates; only *new* resolutions go
  through the new order.
- Update `03-pricing.md`'s resolution order in the same PR (it currently reads
  "*implemented in S03*" — make it true).

## Acceptance criteria

- [ ] ES site + `vat` product code resolves 21%; FR 20%; GB 20% — from one catalogue.
- [ ] Matching-jurisdiction version beats NULL; NULL beats failure; failure is loud.
- [ ] Explicit override bypasses filtering and is logged.
- [ ] All three resolution call sites route through `TaxResolver` (grep-verified).
- [ ] Existing snapshots unchanged after deploy (regression fixture).
- [ ] `03-pricing.md` updated; seeded rates per country present.

## Tests required

| Test | Asserts |
|---|---|
| `TaxResolverTest::jurisdiction_match_wins` | ES site → ES version |
| `TaxResolverTest::null_fallback_when_no_match` | Universal rate path |
| `TaxResolverTest::unresolvable_fails_loudly` | 422, never wrong-country |
| `TaxResolverTest::override_bypasses_filter` | Step 1 semantics |
| `TaxResolverTest::all_call_sites_use_resolver` | Architectural test |
| `TaxResolverTest::historical_snapshots_untouched` | Invariant 18 family |
