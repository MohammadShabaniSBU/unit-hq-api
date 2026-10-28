# S14-01 — Contract document templates & rendering

## Context

What gets signed. Contract documents join the S13 family model (`channel =
'document'`) — locale variants and the resolver ladder free — with a block vocabulary
extended for legal prose and a PDF render path (the S03 `InvoiceRenderer` idiom:
snapshot-in, bytes-out). The generated document is a **legal artifact**: rendered once
at send, stored, hashed, never re-rendered in place.

## Scope

**In:** `document` channel on families, document blocks (incl. the smart blocks:
parties, terms table, signature anchor), PDF renderer, per-contract generation +
snapshot storage, builder-page reuse, seeded es/en contract template.
**Out:** envelope sending (03), notices/vacate documents from the same system (the
`contract_notices.document_ref` hook from S02 finally has its counterpart — recorded
as the natural fast-follow, not built), clause libraries/conditional sections
(deferred, recorded).

## Behaviour

**Blocks.** The S13 vocabulary plus:

| Block | Renders |
|---|---|
| `legal_section` | Numbered heading + prose (tokens ok) — auto-numbered by position |
| `parties` | Smart: issuer legal entity (name, NIF, address — the S03 snapshot fields) vs tenant (billing identity where complete, else contact identity, flagged) |
| `terms_table` | Smart: unit(s), monthly amount per current item versions, deposit, move-in, notice period, cadence — from the contract row + `itemsOn(move_in)` |
| `signature_anchor` | The provider's signature placement tag (Signable's merge-field syntax; adapter supplies the token via a `signatureAnchor()` capability so the block stays provider-agnostic) |
| `page_break` | — |

Validation: exactly one `signature_anchor` v1 (co-signers deferred, recorded);
`parties` + `terms_table` required for `purpose = contract` families (a contract
without terms is a rendering of a mistake).

**Rendering.** `ContractDocumentRenderer::render(Contract $c, TemplateVariant $v):
{pdf_bytes, html}` — dompdf path per the standing engine choice, A4, page numbers,
the smart blocks reading **only** contract-row snapshots and item/price references
(never live org settings — invariant 18's spirit; the parties block reads the
entity's *current* identity because pre-send that is correct; post-send immutability
comes from storing bytes, next paragraph).

**Generation + snapshot.** `contract_documents` table: contract FK, family/variant
refs, `rendered_at`, `pdf_path` (private disk), `sha256`, `status: draft | sent |
signed | superseded`, envelope FK (03 fills). Generate renders + stores + hashes;
regenerate (pre-send only) supersedes the old row — **sent documents are frozen**;
post-send changes mean cancel-and-resend (03's flow), never mutation. The signed
final (03) is a *separate* stored artifact: what-we-sent and what-they-signed are
both kept.

**Locale.** Resolver ladder picks the variant per the tenant; the operator sees which
resolved and can override per-send (a French tenant at a Spanish site signing the es
legal text is a real choice someone must make consciously — the override is logged).

## Panel surface

Marketing → Templates gains the Documents tab (the S13 builder component with the
extended vocabulary — assert reuse, no fork); smart blocks preview with the sample-
context selector (real seeded contract). Contract page (04 wires placement): Generate
document → variant resolution display + override → preview iframe (the real renderer
endpoint, the standing rule) → stored draft listed with hash prefix.

## Acceptance criteria

- [ ] Seeded es + en contract templates render golden-filed PDFs (structure asserted
      via extracted text — dompdf bytes aren't stable enough for byte-goldens; text +
      page-count goldens instead, the honest fixture).
- [ ] Smart blocks: parties uses billing identity when `fiscalComplete`, flags
      otherwise; terms table matches `itemsOn(move_in)` to the cent; anchor emits the
      adapter token.
- [ ] Snapshot semantics: template edited after generation → stored draft unchanged;
      regenerate supersedes; sent docs refuse regeneration (03 enforces, guard here).
- [ ] Locale ladder + logged override.
- [ ] Builder-reuse asserted (component identity); required-blocks validation fires.

## Tests required

| Test | Asserts |
|---|---|
| `DocRendererTest::text_and_pagecount_goldens` | es + en |
| `SmartBlockTest::parties_terms_anchor` | The three contracts |
| `DocSnapshotTest::freeze_supersede_guard` | Legal-artifact rules |
| `DocLocaleTest::ladder_and_override_logged` | The conscious choice |
