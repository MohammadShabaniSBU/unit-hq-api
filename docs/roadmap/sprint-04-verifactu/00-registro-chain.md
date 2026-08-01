# S04-00 — Registro de facturación chain

## Context

The registro is the fiscal record Verifactu is named for: one per issued invoice, carrying
the invoice's identifying data and a SHA-256 fingerprint (*huella*) computed over its own
fields **plus the previous record's huella** — a per-entity hash chain that makes silent
alteration detectable. `architecture-payments-and-fiscal.md` §3 decided: build the chain
unconditionally for ES entities in `verifactu` **or** `no_verificable`; gate only submission.

## Scope

**In:** `verifactu_records` table, huella computation, chain head resolution under lock,
genesis handling, hook inside `InvoiceIssuer`, backstop for non-ES entities.
**Out:** submission (02), anulación records (03 — but the table supports the type now),
signature (README gap), QR (01).

## Schema changes

```sql
CREATE TABLE verifactu_records (
    id                  BIGSERIAL PRIMARY KEY,
    legal_entity_id     BIGINT NOT NULL REFERENCES legal_entities(id),
    invoice_id          BIGINT NOT NULL REFERENCES invoices(id),
    record_type         VARCHAR(12) NOT NULL,     -- alta | anulacion
    chain_position      BIGINT NOT NULL,          -- 1 = genesis, strictly sequential per entity
    is_first            BOOLEAN NOT NULL DEFAULT false,
    -- data snapshot (what the huella covers + what the XML needs)
    issuer_tax_id       VARCHAR(64) NOT NULL,
    full_number         VARCHAR(40) NOT NULL,
    issue_date          DATE NOT NULL,
    invoice_type        VARCHAR(4) NOT NULL,      -- F1 | F2 | R1..R5 (mapping below)
    rectification_type  VARCHAR(2) NULL,          -- 'I' (por diferencias) when R*
    tax_total           NUMERIC(10,2) NOT NULL,
    gross_total         NUMERIC(10,2) NOT NULL,
    tax_breakdown       JSONB NOT NULL,           -- [{rate, base, quota}] per distinct rate
    description         VARCHAR(500) NOT NULL,    -- operation description, from lines
    generated_at        TIMESTAMPTZ NOT NULL,     -- FechaHoraHusoGenRegistro, entity tz offset
    prev_huella         VARCHAR(64) NULL,         -- NULL only when is_first
    huella              VARCHAR(64) NOT NULL,
    -- submission lifecycle (task 02 writes these; columns land now)
    submission_status   VARCHAR(24) NOT NULL DEFAULT 'not_required',
        -- not_required | pending | submitted_ok | accepted_with_errors | rejected | superseded
    submitted_at        TIMESTAMPTZ NULL,
    aeat_csv            VARCHAR(64) NULL,         -- AEAT receipt code
    aeat_error_code     VARCHAR(16) NULL,
    aeat_error_message  TEXT NULL,
    is_subsanacion      BOOLEAN NOT NULL DEFAULT false,   -- task 03
    created_at TIMESTAMP
);
CREATE UNIQUE INDEX vr_chain_idx   ON verifactu_records (legal_entity_id, chain_position);
CREATE UNIQUE INDEX vr_genesis_idx ON verifactu_records (legal_entity_id) WHERE is_first;
CREATE INDEX vr_invoice_idx ON verifactu_records (invoice_id);
CREATE INDEX vr_pending_idx ON verifactu_records (submission_status)
    WHERE submission_status = 'pending';
```

**No `updated_at`, no updates** except the submission-lifecycle columns (02/03), which never
touch hashed fields. Nothing is ever deleted.

## Behaviour

### Huella (verify against current AEAT spec — README rule)

Registro de alta input, exact order, `key=value` joined by `&`, values trimmed, UTF-8,
SHA-256, **uppercase hex**:

```
IDEmisorFactura=<NIF>&NumSerieFactura=<full_number>&FechaExpedicionFactura=<dd-mm-yyyy>
&TipoFactura=<F1|F2|R1..R5>&CuotaTotal=<tax_total>&ImporteTotal=<gross_total>
&Huella=<prev_huella or empty for genesis>&FechaHoraHusoGenRegistro=<ISO8601 with offset>
```

Amounts: dot decimal, two places, no thousands separators — exactly the stored NUMERIC
rendering. Timestamp: entity-timezone offset via the D8 clock (`2026-08-02T14:03:11+02:00`).
Implement as `App\Support\Verifactu\Huella::alta(array $fields): string` — pure, static,
golden-file tested so a refactor can never silently change output.

**Invoice-type mapping:** ordinary → `F1`; simplified → `F2`; rectificative → `R1` with
`rectification_type = 'I'`, **except** a rectificative whose original was simplified → `R5`.

### Chain append

`App\Support\Verifactu\Chain::append(Invoice $invoice, string $type): VerifactuRecord`,
called by `InvoiceIssuer` **inside the issue transaction**, after number allocation:

1. Regime check: entity `country_code = 'ES'` and regime ∈ {verifactu, no_verificable} →
   proceed; otherwise return null (`submission_status` never created — non-ES invoices have
   no record at all, by design).
2. Chain head: `SELECT … FOR UPDATE` the entity's max `chain_position` row (same locking
   idiom as invoice numbering — and note both locks live in one transaction; keep the
   acquisition order *series then chain* everywhere to avoid deadlock).
3. Genesis: no head → `is_first = true`, `prev_huella = NULL`, position 1.
4. Build snapshot from **invoice snapshot columns only** (never live entity/contact —
   the S03 discipline), compute huella, insert.
5. `submission_status`: regime `verifactu` → `pending`; `no_verificable` → `not_required`.
6. Mirror `huella`/`prev_huella` onto the S03 placeholder columns
   `invoices.verifactu_hash/_prev_hash` (read-convenience denormalisation; the record row
   is authoritative).

**Failure posture — inverted from logging:** any exception here **aborts the issuance**.
An invoice without its registro is the illegal artefact; a lost sale is recoverable. No
`DB::afterCommit`, no try/report/continue. State this in code comments; it contradicts the
codebase's Tier-1 instincts and a future refactor will "fix" it otherwise.

## API surface

`GET /api/invoices/{id}` gains `verifactu: { huella, chain_position, submission_status,
csv } | null`. `GET /api/legal-entities/{id}/verifactu` → chain head, record count,
genesis date, pending/rejected counts (feeds task 04's panel card).

## Panel surface

Invoice detail: a compact *Verifactu* row (huella truncated with copy, status chip).
Nothing else this task; 04 builds the entity view. i18n `verifactu.*`; keep AEAT terms
untranslated where official (*huella*, *registro*).

## Invariants

New, add to `09`:

> **Verifactu records are append-only and hash-authoritative.** Hashed fields are never
> updated; only submission-lifecycle columns may change. The chain is per legal entity,
> gapless in `chain_position`, single-genesis. A failed record write fails the issuance.

## Acceptance criteria

- [ ] ES + regime issues chained records; genesis marked; positions gapless under the
      Postgres race test (reuse the S03-02 harness through the full issuer).
- [ ] Non-ES entity, or ES + `none`: zero records, invoice unaffected.
- [ ] Huella matches golden vectors (compute two by hand, commit as fixtures) and mirrors
      onto the invoice placeholders.
- [ ] `no_verificable` chains identically with `not_required`; only `verifactu` yields
      `pending` — the §3 "trap" test.
- [ ] Forced failure in `Chain::append` rolls back invoice + number (no gap) + charges'
      stamps.
- [ ] Rectificatives map to R1/R5 correctly with `rectification_type = 'I'`.
- [ ] Timestamps carry the entity-timezone offset, never Z/UTC-naive.

## Tests required

| Test | Asserts |
|---|---|
| `HuellaTest::golden_vectors` | Byte-exact known outputs |
| `HuellaTest::genesis_empty_prev` | `Huella=` empty-string handling |
| `ChainTest::sequential_positions_per_entity` | Two entities interleaved stay independent |
| `ChainTest::single_genesis_enforced` | Partial unique fires |
| `ChainTest::none_regime_writes_nothing` | And non-ES |
| `ChainTest::no_verificable_chains_without_pending` | The §3 trap |
| `ChainTest::failure_aborts_issuance` | Full rollback incl. number |
| `ChainTest::snapshot_sources_only` | Mutate entity post-issue, record unchanged |
| `Pgsql/ChainRaceTest::concurrent_issues_gapless` | Positions + no deadlock with series lock |
