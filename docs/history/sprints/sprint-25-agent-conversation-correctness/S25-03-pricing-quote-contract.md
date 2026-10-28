# S25-03 — `pricing.quote` becomes a real quote

**Depends on:** S25-00
**Touches:** `unit-hq-api`, demo seeder
**Trace evidence:** trace-34, trace-35

## Problem

`pricing.quote` returns `{unit_class_id, site_id, net, tax, gross, rate,
currency}` and summarises as `€346.80 (incl. 21% tax; net €286.61)`.

**No period.** "€346.80" is not a quote. Per month? Per week? The org cadence
lives in `BillingSettings` and is snapshotted at signing, so the tool knows the
answer and simply does not say it. The customer was shown two bare numbers.

**No `price_id`.** The quote reads a `prices` row but does not name it. Between
the quote and `sales.create_offer`, a catalogue rate change can close that
window and insert a successor (which is the correct, invariant-2 way to change a
rate). The offer would then carry a different amount than the one quoted, with
nothing detecting the divergence.

**No label.** Covered by S25-00, but this tool is where it bit: the model had to
carry ids and got them wrong (see S25-01).

**Implausible demo data.** €346.80/month for 5 m² in Madrid is roughly 4× market,
and class 2 came back at €290.69 — *cheaper than the smaller unit*. Whatever
class 2 is, the seeded catalogue is not monotonic in size. A demo that quotes
implausible prices is a demo that cannot be shown to a customer.

## What to build

### Result shape

| Field | Source |
|---|---|
| `price_id` | The immutable `prices` row read |
| `unit_class_label`, `size` | `unit_classes` |
| `site_name` | `sites` |
| `net`, `tax`, `gross`, `rate`, `currency` | Unchanged; currency from `prices.currency` (invariant 29) |
| `tax_rate_id` | The version `TaxResolver` selected |
| `billing_interval`, `billing_interval_count` | `BillingSettings` |
| `as_of` | `SiteClock::today($site)` — site-local civil date (invariant 32) |

Summary line carries all of it:

> `€286.61 net / €346.80 incl. 21% tax, per month — Trastero 5 m² at Madrid Centro`

Entities: the `unit_class` and the `site`, so a quote licenses a subsequent
offer for the same class (S25-01).

### Quote → offer continuity

`sales.create_offer` accepts an optional `quoted_price_id`. When present, the
creation path asserts that row is still the current catalogue price for the
junction. If it is not, refuse with `422` / `price_superseded` and a
`ToolError` telling the agent to requote.

The agent must never silently offer a number different from the one it said out
loud. This is the same reasoning as S24's *approval never replays a stored
payload* — live state wins, and divergence surfaces rather than resolving
itself quietly.

Snapshot `tax_rate_id` through so the offer path can assert the same tax
resolution rather than re-resolving and possibly landing elsewhere.

### Demo catalogue

Recalibrate seeded catalogue prices to plausible Madrid rates and assert
monotonicity: within a site, a larger class never costs less than a smaller one.
Generation stays **deterministic** — no `mt_rand()`, `fake()`, `shuffle()`,
`Collection::random()` or `Str::random()` in the stage path, per the existing
demo convention, or every cast persona downstream shifts.

Add a seeder assertion (not just a test) so an implausible catalogue fails
`demo:seed` loudly instead of surfacing in front of a customer.

## Acceptance criteria

- [ ] Quote result names its `price_id` and its billing period.
- [ ] `sales.create_offer` with a superseded `quoted_price_id` returns 422
      `price_superseded`; test closes a price window between quote and offer.
- [ ] `sales.create_offer` with a current `quoted_price_id` creates an offer
      whose amount equals the quoted amount to the cent.
- [ ] Currency in the result comes from `prices.currency`, never from
      `sites.currency` or `BillingSettings.default_currency` (invariant 29).
- [ ] `as_of` resolves through `SiteClock`, not `Carbon::today()`.
- [ ] `demo:seed --fresh` produces catalogue prices monotonic in class size per
      site; the seeder fails if not.
- [ ] Quoting two classes in one turn (as the trace does) cannot straddle a
      catalogue version change without detection.

## Out of scope

- Discount resolution inside the quote. `GET /api/discounts/{id}/resolve`
  already exists for the offer-option picker; wiring the agent to it is a
  separate decision about whether an agent may offer a discount at all.
- Multi-period / stay-length quoting.
