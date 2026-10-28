# Render matrix — S33-02

Checked 15 Oct 2026. No Litmus or Email on Acid account in this environment, so the mail clients below were not sent a message. Do not treat this file as a pass for those clients.

Chromium (local preview of `tests/Fixtures/email-render/v2/`, layout viewport) is the only client that was opened.

## Chromium

| Check | Result |
|---|---|
| `50_50` at 900px | Side by side. Each column 276px. Same row. Left then Right. |
| `33_33_33` at 900px | Side by side. Each column 184px. 184+184+184 = 552 inner width. Same row. Order One, Two, Three. |
| `50_50` and `33_33_33` with `stack_on_mobile: true` at 400px | Columns stack. `display: block`, `max-width: 100%`. Reading order kept. |
| `50_50` with `stack_on_mobile: false` at 400px | Stays `inline-block`, `max-width: 276px`, same row. Class is `kv-col-50_50` only. |

The 600px card is wider than a 400px viewport, so the preview scrolls sideways. That shell is the existing fixed card. Stacking still happens inside it.

## Mail clients

Not run.

| Client | Side by side | Stack at 480px | `stack_on_mobile: false` stays side by side |
|---|---|---|---|
| Outlook 2016 Windows | not run | not run | not run |
| Outlook 2019 Windows | not run | not run | not run |
| Outlook 365 Windows | not run | not run | not run |
| Outlook web | not run | not run | not run |
| Gmail web | not run | not run | not run |
| Gmail iOS | not run | not run | not run |
| Gmail Android | not run | not run | not run |
| Apple Mail macOS | not run | not run | not run |
| Apple Mail iOS | not run | not run | not run |

Markup aimed at those clients: MSO conditional tables with integer `td` widths, inline-block columns, and the `.kv-col` media query. Outlook desktop ignores that query and uses the ghost table.

## Follow-ups

- Dark mode. Out of scope for S33-02. No `prefers-color-scheme` rules.
- Live inbox pass for the clients listed above.
