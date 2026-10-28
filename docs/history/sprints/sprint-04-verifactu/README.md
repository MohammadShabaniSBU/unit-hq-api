# Sprint 04 — Verifactu

## Goal

Every invoice issued by a Spanish entity produces a **registro de facturación**: hash-chained
to its predecessor, QR-stamped on the rendered invoice, and — when the entity's regime is
`verifactu` — submitted to the AEAT in near-real-time. Cancellation happens by *registro de
anulación*, tampering trips an integrity alarm, and the regime can be enabled per entity
without ever being able to break an existing chain.

Legal basis: RD 1007/2023 + Orden HAC/1177/2024. Penalties for non-compliant software reach
€150k/year **for the vendor** — this sprint is as much your protection as the client's.

## ⚠ Spec-verification rule (read first)

The hash-input format, XML structures, endpoints and QR parameters in these task files are
written from the 2024 ministerial order and are believed stable — but **before implementing
each task, download the current AEAT technical pack** (XSD schemas, *especificaciones de la
huella*, QR spec, web-service WSDL) from the AEAT Verifactu developer page and diff against
the task file. Where they disagree, **AEAT wins**, and the task file gets corrected in the
same PR. Do not let Cursor "reasonably assume" any field format in this sprint.

## External dependencies (start chasing now — they gate testing, not coding)

| # | Need | From | Gates |
|---|---|---|---|
| 1 | **Digital certificate** of the issuing entity (certificado de representante / sello), or authorisation as *colaborador social* | Client / their gestor | Task 02 submission against the AEAT **test** environment (`prewww`) |
| 2 | Real entity NIF(s) replacing the `PENDING-GESTOR` seed | Client | End-to-end test realism |
| 3 | Confirmation of go-live cutover date + series continuity (S03 gestor #5) | Gestor | Chain genesis timing |
| 4 | Whether the client will run `verifactu` (submitting) or `no_verificable` | Client + gestor | Which mode gets production-hardened first |

On #4: **`no_verificable` additionally requires XAdES electronic signature of each record**,
which this sprint does *not* implement (see Deliberate gaps). If the client answers
`no_verificable`, signature work must be scheduled before their go-live.

## Exit criteria

- [ ] Issuing an invoice for an ES entity (regime `verifactu` or `no_verificable`) writes a
      registro de alta in the same transaction, correctly chained; the first record is a
      marked genesis.
- [ ] The rendered invoice carries a spec-conformant QR and the VERI*FACTU legend when
      submitting.
- [ ] With a certificate configured, registros submit to the AEAT test environment, honour
      the returned wait-time, and record per-record acceptance states.
- [ ] A rejected record can be corrected and resubmitted as *subsanación*; an invoice issued
      in error can be annulled by registro de anulación.
- [ ] `verifactu:verify-chain` detects any tampering; detection raises a persistent panel
      alarm and a Tier-3 event.
- [ ] Regime transitions obey the ratchet: enabling starts a chain; `verifactu ⇄
      no_verificable` legal; anything → `none` blocked once records exist.

## Task order

| # | Task | Est. |
|---|---|---|
| 00 | [Registro chain](./00-registro-chain.md) | 1.5 days |
| 01 | [QR & invoice legend](./01-qr-and-legend.md) | 0.5 day |
| 02 | [AEAT submission](./02-aeat-submission.md) | 2 days |
| 03 | [Anulación & subsanación](./03-anulacion-and-subsanacion.md) | 1 day |
| 04 | [Integrity, event log & regime rules](./04-integrity-and-regime.md) | 1 day |

00 → 01 and 00 → 02 are hard dependencies; 01 and 02 are independent of each other; 03 and
04 need 02's response handling.

## Deliberate gaps (recorded, not forgotten)

- **XAdES signature of records** (`no_verificable` hard requirement) — deferred pending
  dependency #4. If needed: `robrichards/xmlseclibs`, sign the registro XML, store alongside.
- **Registro de eventos full catalogue** — task 04 ships the chained event table and the
  events this system actually emits; the exhaustive SIF event taxonomy fills in when the
  *declaración responsable* is drafted.
- **Declaración responsable** — a signed producer statement you must author before any
  production use; template task noted in 04, content is a legal document, not code.

## Risks

**The clock is compliance-relevant.** `FechaHoraHusoGenRegistro` carries a UTC offset and
AEAT cross-checks plausibility. Everything goes through `SiteClock`-style entity-timezone
handling (D8) — a bare `now()` in this sprint is a defect, not a style issue.

**The chain write cannot be "best effort".** Unlike Tier-1 logging, a failed registro write
must fail the issuance — an invoice without its registro is precisely the illegal state.
This inverts the usual logging posture; task 00 is explicit about it.

**Submission is async, acceptance is not guaranteed.** Correcto / AceptadoConErrores /
Incorrecto are three different follow-ups. Never mark submitted-and-forgotten; task 02's
state machine and task 03's subsanación exist because rejection is a *normal* operational
event, not an exception.
