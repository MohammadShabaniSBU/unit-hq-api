# DISC-00 — Catalogue & governance

## Context

Discounts are admin-defined rows operators *pick* — the fixed 5/10/20/30/50 menu is
governance by construction: nobody free-types a percentage at a counter. One table,
two kinds, archive-only, cadence-alignment warnings at save.

## Scope

**In:** `discounts` table + kind param validation, settings CRUD + UI, seeded rows
for both client schemes, the D-DISC record + new invariant landed in docs.
**Out:** compilation (01), any attachment surface (03), stacking (one discount per
contract v1 — recorded), time-boxed campaign validity windows (recorded).

## Schema changes

```sql
CREATE TABLE discounts (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(128) NOT NULL,                -- "20% off" / "Long-stay promo"
    kind VARCHAR(16) NOT NULL,                 -- percent | free_time
    params JSONB NOT NULL,
    -- percent:   { "percent": "20.00" }
    -- free_time: { "tiers": [ { "min_commitment_weeks": 4, "free_weeks": 2 }, … ] }
    applies_to VARCHAR(16) NOT NULL DEFAULT 'unit',   -- v1 fixed; column for later
    tracks_rate_changes BOOLEAN NOT NULL DEFAULT true, -- percent only; free_time ignores
    archived_at TIMESTAMP NULL,
    created_by BIGINT NULL, created_at TIMESTAMP, updated_at TIMESTAMP
);
```

(The long-dormant `offer_options.discount_id` + contract-item discount columns get
audited here: keep `offer_options.discount_id` — 03 uses it; item-level provenance
lands in 01 as `contract_item_versions`-adjacent linkage, whatever S02 named the
version rows — verify in repo and use the real names.)

## Behaviour

- **Validation.** `percent`: `0 < percent < 100`, string money-style scale-2.
  `free_time`: tiers non-empty, strictly increasing on both fields,
  `free_weeks < min_commitment_weeks` per tier (free everything is a config error).
  **Alignment warning** (non-blocking): each `free_weeks × 7` checked against the
  org cadence length; misaligned tiers save with a visible warning badge
  ("compiles to a 37.5% period on monthly billing").
- **Archive-only** with in-use guard semantics matching policies (S07-00 idiom):
  archived rows vanish from pickers, stay resolvable for provenance.
- **Docs:** D-DISC block + the invariant into `09`/`10`; `03-pricing.md` Discount
  section rewritten from "in progress" to this model (it has said "formalising"
  since the beginning — end it).

## Panel surface

Settings → Facility → Discounts (the nav slot has existed since day one): list
(kind badge, params summary — "5 tiers, up to 6 weeks free", usage count, alignment
warnings), create/edit forms per kind (percent: one field; free_time: tier row
editor with live compile-preview against the org cadence — "8w commitment → period
1 free"), archive with confirm. i18n `settings.discounts.*`; es: *Descuento*,
*Semanas gratis*, *Compromiso mínimo*.

## Acceptance criteria

- [ ] Both kinds validate per the matrix (incl. tier monotonicity and the alignment
      warning firing on a constructed misfit).
- [ ] Seeds: the five percent rows + the exact client tier table from the brief.
- [ ] Archive-only + picker exclusion + provenance resolvability.
- [ ] Docs surgery landed (grep `03-pricing.md` for "in progress" — gone).

## Tests required

| Test | Asserts |
|---|---|
| `DiscountCatalogueTest::validation_matrix` | Both kinds, all edges |
| `DiscountCatalogueTest::alignment_warning` | Non-blocking, visible |
| `DiscountCatalogueTest::archive_semantics` | The S07 idiom |
