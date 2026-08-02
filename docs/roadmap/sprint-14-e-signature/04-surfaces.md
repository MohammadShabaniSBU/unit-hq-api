# S14-04 — Surfaces & integration

## Context

Making the machinery operable: the contract page's signature card, the awaiting tab,
the offer→remote-signature path, attention surfaces, and the docs/i18n close-out.

## Scope

**In:** contract signature card + documents list, contracts board `Awaiting
signature` tab, creation-flow `signature_mode` choice (incl. reservation-convert),
attention chips (post-cancellation signature, declined, expiring), inbox context-
panel awareness, docs + es sweep. **Out:** signer-side customization (Signable's
page is theirs v1), reminder playbook steps (recipe noted).

## Panel surface

**Contract page — Signature card** (replaces nothing; appears for contracts with any
document/envelope history): current state prominently (Awaiting — sent 3d ago ·
viewed yesterday · expires in 11d), actions per state (Send for signature / Resend /
Cancel envelope), the document list (each: variant locale, rendered date, hash
prefix, status chip, preview link; signed rows add the signed PDF + certificate
downloads). The signed contract's card collapses to the artifact: "Signed 2 Aug via
Signable · SHA-256 3f9a…" with downloads — the thing an operator shows a dispute.

**Creation flows.** Contract create (+ reservation convert) gains the mode choice:
*Sign now* (default, unchanged) / *Send for signature* — the latter routes into:
create awaiting → generate document (variant resolution shown, override per 01) →
review preview → send (signer prefilled, expiry). One guided sequence, not four
pages. Offers: an accepted option's convert path offers the same fork — the
lead-to-signed journey closing remotely end-to-end is the sprint's demo.

**Board + attention.** Contracts index gains the `Awaiting signature` tab (count
badge; rows show sent/viewed/expiry aging, expiring-soon amber ≤3d). The attention
chips join the delinquency board's header pattern on the contracts index: declined
(n), signed-after-cancellation (n, red — the human-conversation queue). Inbox
context panel: awaiting contracts render in the tenancy block with their signature
state ("Contract awaiting signature — sent 3d ago") instead of balance figures.

**Docs.** `04-crm-pipeline.md` gains the awaiting stage; `09` carries amended
invariant 20 (00 wrote it — verify landed); the settings map + `01-stack.md` gain
the Integrations section. es sweep: *Pendiente de firma*, *Enviar para firma*,
*Firmado*, *Rechazado* — legal-adjacent vocabulary, have the operator review.

## Acceptance criteria

- [ ] The guided remote flow end-to-end from both entry points (create, convert)
      against the fake adapter, finishing on the signed artifact card.
- [ ] Board tab + aging + expiring amber; both attention chips filter correctly;
      the post-cancellation queue renders its Tier-3 entries.
- [ ] Context panel shows signature state for awaiting contracts.
- [ ] Walk-in create UX unchanged (screenshot regression in PR).
- [ ] Docs updated; `en/es/fr` complete, es reviewed.

## Tests required

API: `AwaitingBoardTest::tab_counts_aging`, `AttentionTest::two_chips_filter`.
Panel manual script: guided flow both entries (1), state-by-state card sweep incl.
signed artifact (2), attention queues (3), context panel (4), walk-in unchanged (5).
