# S04-02 — AEAT submission

## Context

Under regime `verifactu`, registros must reach the AEAT web service in near-real-time —
batched "flujo continuo": send what's pending, read the mandated wait-time from the
response, send again no sooner. Transport is SOAP over mutual-TLS with the entity's
certificate. This task is the sprint's centre of gravity and the one gated on external
dependency #1 (the certificate).

## Scope

**In:** certificate storage per entity, SOAP client, XML building from records, the
submission queue job honouring wait-times, per-record response handling, status surfaces.
**Out:** rejection *repair* (03), event log (04), production endpoint activation (config
flip at go-live after gestor sign-off).

## Certificate management

`legal_entities` side-table (secrets never on the main row):

```sql
CREATE TABLE legal_entity_certificates (
    id               BIGSERIAL PRIMARY KEY,
    legal_entity_id  BIGINT NOT NULL REFERENCES legal_entities(id),
    certificate_pem  TEXT NOT NULL,          -- encrypted cast
    private_key_pem  TEXT NOT NULL,          -- encrypted cast
    passphrase       TEXT NULL,              -- encrypted cast
    subject          VARCHAR(255) NOT NULL,  -- parsed CN, display
    serial           VARCHAR(64)  NOT NULL,
    valid_from       DATE NOT NULL,
    valid_to         DATE NOT NULL,
    is_active        BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMP, updated_at TIMESTAMP
);
CREATE UNIQUE INDEX lec_active_idx ON legal_entity_certificates (legal_entity_id) WHERE is_active;
```

Upload accepts PFX/P12 or PEM pair; parse with `openssl_pkcs12_read`/`openssl_x509_parse`
to validate + extract metadata **at upload**, storing PEM. The full existing credential
discipline applies (`App\Support\Credentials`, invariants 26–27): encrypted casts, masked
serial in resources, blank-unchanged, Tier-3 create/rotate/remove events,
`DecryptException → credentials_unreadable`. Panel warns at 60/30 days before `valid_to` —
an expired certificate silently stops fiscal submission, which is an incident.

## Submission

**Endpoints (verify against current WSDL — README rule):**
prod `https://www1.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP`,
test `https://prewww1.aeat.es/...` — same single `config('verifactu.environment')` as the QR.

`App\Support\Verifactu\AeatClient` — PHP `SoapClient` with a stream context carrying the
entity cert/key (mutual TLS); one client per entity per run. XML per the AEAT XSD:
`RegFactuSistemaFacturacion` envelope with `Cabecera` (issuer) + up to **1000** registros.
The system-info block (`SistemaInformatico`: producer name, NIF, system name/id, version)
comes from `config/verifactu.php` — these values also appear in the *declaración
responsable* (04); keep them in one place.

**The job.** `SubmitVerifactuRecords` (queued, per entity):

1. Skip unless regime `verifactu`, active in-date certificate, and pending records exist.
2. Respect the throttle: a `verifactu_submission_state` row per entity stores
   `next_allowed_at` — from the previous response's `TiempoEsperaEnvio` (seconds; default
   60 when absent). Re-dispatch `->delay()`ed if too early.
3. Batch pending records **in chain order**, oldest first, ≤1000.
4. Send; on transport failure: exponential backoff retries, records stay `pending`, Tier-1
   event — transport failure is not rejection.
5. Per-record response (`EstadoRegistro`):
   `Correcto` → `submitted_ok` + `aeat_csv`;
   `AceptadoConErrores` → `accepted_with_errors` + error code/message (registered but must
   be repaired — feeds 03);
   `Incorrecto` → `rejected` + code/message (not registered — must be subsanated, 03).
6. Store `next_allowed_at`; re-dispatch if pending remain; Tier-1 events throughout with
   batch size + envelope status (`Correcto|ParcialmenteCorrecto|Incorrecto`).

Scheduler: every minute dispatch per qualifying entity (the throttle row makes this cheap
and self-limiting). Only the **submission-lifecycle columns** are written — hashed fields
stay frozen (00's invariant).

## Panel surface

Entity detail (extends 00's summary): certificate card (subject, masked serial, validity,
upload/rotate), submission card (pending / accepted / with-errors / rejected counts,
last submission, next allowed, environment badge — **test environment gets a loud amber
banner**). Invoice detail status chip gains the CSV on hover. i18n `verifactu.submission.*`.

## Invariants

- Invariants 26–27 verbatim for certificates.
- 00's rule: lifecycle columns only; chain order preserved in batches.
- No optimism: a record is `submitted_ok` only on AEAT's say-so — the invariant-11 family
  applied to fiscal data.

## Acceptance criteria

- [ ] Certificate upload validates, encrypts, masks, Tier-3 logs; expiry warnings render.
- [ ] XML for a golden two-record batch validates against the downloaded XSD (commit the
      XSD; schema-validate in tests — no network).
- [ ] Job honours `TiempoEsperaEnvio` (fake responses; assert delay), batches in chain
      order, caps at 1000.
- [ ] Three per-record outcomes map to three statuses with codes stored; transport failure
      leaves `pending` and retries.
- [ ] `no_verificable` entities never submit despite having chains.
- [ ] End-to-end against **prewww** succeeds once dependency #1 lands (manual checklist
      item, not CI).

## Tests required

| Test | Asserts |
|---|---|
| `CertificateTest::upload_parse_encrypt_mask` | Credential discipline |
| `AeatXmlTest::envelope_validates_against_xsd` | Offline schema validation |
| `SubmitJobTest::throttle_honoured` | Delay from fake TiempoEsperaEnvio |
| `SubmitJobTest::chain_order_batching` | Oldest-first, ≤1000 |
| `SubmitJobTest::three_outcomes_three_statuses` | + csv / error codes |
| `SubmitJobTest::transport_failure_keeps_pending` | Retry, Tier-1 event |
| `SubmitJobTest::no_verificable_never_submits` | Regime gate |
