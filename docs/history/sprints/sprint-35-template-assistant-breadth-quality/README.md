# Sprint 35 — Template assistant: breadth & quality

## Goal

Take the assistant from "good first drafts" to something operators rely on: create
locale variants by translation, use their own images, work in HTML mode, and put a
measurable quality loop (evals + a report) around it before every role gets it.

## Design decisions (lock at kickoff)

| # | Decision | Rationale |
|---|---|---|
| Q1 | **Translate keeps structure.** A translated variant has the same sections, columns, block types, tokens, links and images as the source; only text params and the subject change. | Locales of one version must stay one layout (S13 rationale for blocks). Token parity is checkable. |
| Q2 | Images enter only through the **template asset store** (`template_assets`). The model sees the image and its asset id; blocks reference `asset_id`. | Same rule as S34 (`assist.image_requires_asset`); no remote images in email. |
| Q3 | HTML-mode assistant edits HTML with **search/replace patches** and never converts HTML ↔ blocks. Every result is sanitised server-side before the panel sees it. | Conversions are lossy both ways; sanitising at the server keeps one gate. |
| Q4 | Evals assert **properties**, not golden outputs. They run nightly and on prompt/agent changes, not on every PR. | Model output varies; properties (valid doc, right tokens, constraints) don't. Cost control. |
| Q5 | Quality metrics are a **native Insights report** over `template_assist_turns` + `ai_usage_events`. No new store. | Decision I1: one reporting system. |
| Q6 | Rollout via a **company setting** plus the S34 permission; off is fail-closed. | Owners can stop it instantly without a deploy. |

## Exit criteria

- [ ] From an `en` variant, **Add language → Translate from en** produces an `es`
      variant with identical structure and token multiset, in one turn.
- [ ] An operator attaches a photo in the chat and the assistant places it as an
      `image` block with the uploaded asset.
- [ ] In HTML mode, "make the button orange and shorten the intro" patches the HTML;
      the result is sanitised and accepted or rejected as a diff.
- [ ] The eval suite passes ≥ 90 % on `dev`.
- [ ] Owners see undo rate, conflict rate, failure rate, median latency and cost per
      currency in Insights.
- [ ] Turning the setting off makes the tab disappear and the API return 403
      `assist.disabled`.

## Task order

| # | Task | Est. |
|---|---|---|
| 00 | [Translate variant](./00-translate-variant.md) | 1.5 days |
| 01 | [Images from chat](./01-images-from-chat.md) | 1 day |
| 02 | [HTML-mode assistant](./02-html-mode-assistant.md) | 2 days |
| 03 | [Eval suite](./03-eval-suite.md) | 2 days |
| 04 | [Quality report](./04-quality-report.md) | 1 day |
| 05 | [Rollout setting](./05-rollout-setting.md) | 0.5 day |
| 06 | [Invariants, docs & tests](./06-invariants-and-docs.md) | 0.5 day |

**Total ≈ 8.5 days.** 00, 01, 02 are independent. 03 should start early (it catches
regressions in 00–02). 04 needs only S34 data. 05 can land any time; ship it before
widening access.

## Prerequisites

- S34 merged.
- HTML mode in the builder (the Blocks / HTML toggle) merged to `dev`. It is not on
  `dev` at the time of writing; 02 is blocked until it is. Confirm where HTML mode
  stores content (`legacy_html` vs a single `raw_html` block) and update 02 before
  starting it.

## Out of scope

- SMS and WhatsApp template assistants (WhatsApp also needs Meta approval).
- Contract-document assistant (needs a legal review flow; recorded in `10`).
- Image generation.
- Assistant inside campaigns/newsletter wizards (they pick templates; they don't edit them).

## Next

Paid advertising follow-ups (moved from S33): S36.
