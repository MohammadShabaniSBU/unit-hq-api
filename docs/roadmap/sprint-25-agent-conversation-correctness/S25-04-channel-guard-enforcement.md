# S25-04 — Channel guard enforces: segment ceiling and GSM-7 transliteration

**Depends on:** nothing (parallel with S25-00)
**Touches:** `unit-hq-api`
**Trace evidence:** trace-5, 11, 18, 25, 32, 40

## Problem

Segment counts across six agent turns, in order:

| Trace | Segments | Encoding |
|---|---|---|
| trace-5 | 1 | gsm7 |
| trace-11 | 2 | gsm7 |
| trace-18 | 2 | gsm7 |
| trace-25 | 4 | **ucs2** |
| trace-32 | 5 | ucs2 |
| trace-40 | **7** | ucs2 |

Verdict on every one of them: `pass`.

The encoding flips at trace-25 — the exact turn the agent first writes *"m²"*
and *"€"*. Neither character is in the GSM-7 alphabet, so a single one of them
drops the whole message from 160 characters per segment to 70. A seven-segment
UCS-2 SMS costs roughly seven times a one-segment message and fragments badly on
some carriers and handsets.

This is not incidental. **The unit of measure in this industry is m² and the
currency symbol is €.** Every availability list, every quote, and every size
recommendation will trip it. A guard that counts to seven and says `pass` is
not guarding anything — it is a metric with a verdict field attached.

## What to build

### Enforcement thresholds

Config `agents.channel.sms`:

| Key | Default |
|---|---|
| `warn_segments` | 3 |
| `max_segments` | 5 |
| `max_redraft_attempts` | 2 |

- At or above `warn_segments`: verdict `warn`, detail records the count, send
  proceeds. Surfaced in the panel (S25-06).
- Above `max_segments`: verdict `deny`, reason `sms_too_long`, detail carries
  the segment count and the ceiling. The draft returns to the model to shorten,
  bounded by `max_redraft_attempts`, then escalates.

Applies to SMS only. Email has no segment concept; WhatsApp has its own limits
and its own window and template rules.

### `App\Support\Communications\Gsm7Transliterator`

Runs on the SMS body **before** segment counting.

| From | To |
|---|---|
| `²` `³` | `2` `3` |
| `€` | `EUR` |
| `—` `–` | `-` |
| `“` `”` `‘` `’` | `"` `"` `'` `'` |
| `…` | `...` |
| NBSP, narrow NBSP | space |

Characters already in the GSM-7 basic or extended set are left alone — Spanish
accented vowels, `ñ`, `¿`, `¡` and `º`/`ª` must survive untouched. Getting this
wrong turns correct Spanish into mangled Spanish, which is worse than an
expensive message.

`10 m²` becomes `10 m2`; `€346.80` becomes `EUR 346.80`. The trace's
seven-segment draft should land comfortably inside the ceiling as GSM-7.

Record `detail.gsm7_transliterated: true` on the message when the body was
rewritten, so an operator reading the thread knows the sent text differs from
the drafted text.

**Never applied to email or WhatsApp.** Rendering `€` as `EUR` in an email is a
downgrade with no compensating benefit.

### Reuse

Segment counting already exists in `SmsSender` for the inbox composer's segment
display. Use it. Do not fork a second counter — the two would drift and the
displayed count would stop matching the enforced one.

## Acceptance criteria

- [ ] The trace's trace-40 draft, transliterated, counts as GSM-7 and lands at
      or below `max_segments`.
- [ ] A draft still above the ceiling after transliteration is denied and
      re-drafted; a fixture proves the redraft loop terminates.
- [ ] Spanish accented characters, `ñ`, `¿`, `¡` pass through unmodified and the
      message is still counted as GSM-7.
- [ ] Email and WhatsApp bodies are byte-identical before and after.
- [ ] The panel's displayed segment count equals the enforced count (same
      counter).
- [ ] `detail.gsm7_transliterated` is set only when the body actually changed.

## Out of scope

- Deciding SMS-vs-WhatsApp channel selection by cost.
- Transliterating operator-authored templates. This applies to agent drafts;
  applying it to human-written SMS templates is a separate decision.
