# S04-01 — QR tributario & invoice legend

## Context

Every Verifactu-regime invoice must carry a QR code linking to the AEAT verification
service, plus the legend identifying it as verifiable when the entity submits. This is the
only part of the sprint a tenant ever sees.

## Scope

**In:** QR generation, placement in the S03 render (the reserved box), legend logic,
per-environment base URL.
**Out:** any change to invoice content/totals; storing rendered PDFs (still render-on-demand
until the engine decision lands — the QR derives from immutable fields, so re-renders are
byte-stable in content).

## Behaviour

**URL (verify against current AEAT QR spec — README rule):**

```
<base>/wlpl/TIKE-CONT/ValidarQR?nif=<issuer_tax_id>&numserie=<full_number>
    &fecha=<dd-mm-yyyy>&importe=<gross_total>
```

`base`: production `https://www2.agenciatributaria.gob.es`, test `https://prewww2.aeat.es` —
from `config('verifactu.environment')` (one deployment-level setting; **not** per entity —
a deployment talks to one AEAT world). URL-encode values; date is issue_date; importe uses
dot-decimal two places.

**Rendering:** `App\Support\Verifactu\Qr::for(VerifactuRecord $r): string` returning SVG
(`bacon/bacon-qr-code`, already-pure-PHP; error correction **M**), embedded into the
reserved box at 30–40 mm print size with the caption **“QR tributario”**.

**Legend:** when the record's entity regime is `verifactu`, print
**“Factura verificable en la sede electrónica de la AEAT — VERI*FACTU”** near the QR.
`no_verificable`: QR **still prints** (the spec requires it), legend omitted. `none` / non-ES:
neither — the S03 render must remain byte-identical for these (regression fixture).

Language note: the legend is statutory Spanish — never translated, regardless of template
language.

## Invariants

- Renderer stays snapshot-only (S03 rule): `Qr::for` takes the record, not live models.
- Environment is config, not data — no `mode` column creep (the Stripe lesson, `05` docs).

## Acceptance criteria

- [ ] `verifactu`-regime invoice PDF/HTML shows QR + legend; `no_verificable` QR only;
      `none`/non-ES neither, byte-identical to pre-sprint fixture.
- [ ] Decoding the generated QR yields the exact URL with correctly encoded params for a
      golden record (assert by decoding, not by trusting the encoder).
- [ ] Test/prod base switches by config alone.
- [ ] Rectificatives carry their own QR (their own number/amounts).

## Tests required

| Test | Asserts |
|---|---|
| `QrTest::url_golden_record` | Exact string, encoding, dd-mm-yyyy, dot-decimal |
| `QrTest::decode_roundtrip` | Rendered QR decodes to the URL |
| `QrTest::legend_by_regime_matrix` | 4-way regime/country matrix |
| `QrTest::non_fiscal_render_unchanged` | Regression fixture |
