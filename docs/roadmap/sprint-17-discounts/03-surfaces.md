# DISC-03 — Surfaces

## Context

Where operators and tenants meet it: pickers at the two granting moments (offer
option, walk-in create), the tier resolved and *shown* before anyone commits, the
public offer page saying "First 4 weeks free," and the contract page owning the
schedule + the remove button.

## Scope

**In:** offer-option picker + tier display, walk-in create picker + commitment
field, public offer line, contract billing-card schedule + removal UI, convert-
preview rendering, es sweep + docs. **Out:** campaign automation ("auto-attach
promo to all September offers" — a future playbook/campaign concern, recorded),
mid-contract granting (02's note).

## Panel surface

**Offer option editor** gains Discount select (active catalogue; kind badges).
Picking `free_time` immediately shows the resolution against the deal's stay
(`stay_length × period` → weeks): *"2 months declared → first 4 weeks free"* —
or the honest warning when the deal lacks a stay length (*"No stay length on the
deal — no free time will apply"*, link to edit the deal). The resolved tier
renders on the option card (list price struck through where period 1 differs,
"then €184.90 / 4 weeks").

**Public offer page** (the token page): the option shows the promo line in the
tenant's resolved locale — *"Primeras 4 semanas gratis"* — and the schedule
summary. This is the conversion surface; it gets the es review first.

**Walk-in create** (+ reservation convert): Discount select +, when `free_time`
chosen without a deal, a required commitment input (n × weeks/months). The
**convert-preview panel renders the compiled schedule** — the €0/€0/list rows with
dates — before signing; the operator reads the tenant exactly what will bill (the
preview-equals-commit law, made customer-facing).

**Contract page — billing card**: active discount chip (name, kind, "tracks rate
changes" glyph), the version schedule where non-trivial ("Free until 12 Sep ·
50% until 10 Oct · €184.90 thereafter" — computed from versions, displayed never
stored), and **Remove discount** (confirm, reason required, the 02 semantics
restated in the modal: "from the next billing period; the current period is not
affected"). Removed/closed provenance shows in the card's history disclosure and
the activity timeline.

**Reports touch nothing** — but 01's consistency re-run gets a panel manual check:
rent roll shows the €0-period tenant honestly; economic occupancy's drag visible
on the dashboard gap.

i18n `discounts.*`; es: *Descuento aplicado*, *Quitar descuento*, *Primeras N
semanas gratis*, *Compromiso de estancia*. Operator-review — promo language sells.

## Acceptance criteria

- [x] Both granting moments attach with the tier shown pre-commit; the no-stay
      warning honest; option card + public page render the promo per locale.
- [x] Preview schedule = billed reality on the seeded personas (fixture reuse).
- [x] Contract card schedule + chip + removal flow end-to-end with the modal copy.
- [x] Provenance/history visible; activity entries translated.
- [x] `lint`+`typecheck`; `en/es/fr`; es reviewed; docs (`03-pricing.md` cross-link,
      panel map) updated.

## Tests required

API: `AttachSurfaceTest::both_moments_tier_resolution_warnings`,
`PublicOfferTest::promo_line_localized`. Panel manual script: offer flow with tier
display (1), public page es (2), walk-in with commitment + preview schedule (3),
contract card + removal (4), rent-roll honesty spot-check (5).
