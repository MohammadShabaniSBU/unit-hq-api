# S26-10 — Relative dates resolved by a tool; grounding redrafts on a single bad token; email subject synthesis

**Depends on:** S26-02, S26-04
**Blocks:** nothing
**Touches:** `unit-hq-api`
**Trace evidence:** conversation 4 (email channel, 2026-08-26 12:27–12:30)

## Problem

Turn 3 draft asked the customer *"by 'next Monday', do you mean 2025-07-21?"*
Today was Wednesday 2026-08-26; next Monday is 2026-08-31. `GroundingGuard`
correctly blocked the unlicensed date token and the turn ended in
`handoff grounding_failure`. The guard worked; three upstream things did not.

1. The model resolved "next Monday" from its training-era sense of the date.
   The identity block emits `Today at this site:` via `SiteClock::today($site)`,
   and this conversation's principal has `site: none`. Confirm whether the
   line is emitted at all without a site. Either way, calendar arithmetic
   inside the model is fragile by design — S26-02 made relative-date
   conversion a prompt instruction, and this is the failure mode of that
   choice.
2. A grounding block on a single unlicensed date token goes straight to
   handoff. S26-04 gave licensable forbidden claims a redraft path; a single
   ungrounded date or amount deserves the same before a human is pulled in.
3. Turn 2's synthesized email subject was a sentence cut mid-clause:
   *"We found several Keevaris sites in Madrid. Could you let me know which one is"*.

## What to build

### A. Today's date is always in the prompt

`AssemblesSystemPrompt::identityBlock()` emits, on every customer-facing
turn:

```
Today: 2026-08-26 (Wednesday)
```

- Site set → `SiteClock::today($site)` in the site timezone.
- No site → `config('app.timezone')`. Never omit the line.
- Weekday name in the principal locale (en/es/fr) — the model needs it to
  reason about "next Monday" even after B lands, because it must know which
  phrase to pass.
- Stays excluded from `promptVersion()` like the rest of the identity block.

Tests: both branches; the weekday matches the date; locale names.

### B. `calendar.resolve` tool

`App\Support\Ai\Tools\CalendarResolveTool`, key `calendar.resolve`,
read-only, `requiredVerification() = Anonymous`, `isWrite() = false`, added
to both `SalesAgentDefinition` and `SupportAgentDefinition` `toolKeys()`.

| Argument | Type | Notes |
|---|---|---|
| `phrase` | string, ≤ 60 chars, required | the customer's words, verbatim |
| `site_id` | int, optional, licensed `site` ref | timezone for "today" |

Deterministic parser, no model call, evaluated against `SiteClock::today`
(site) or app timezone (no site). Accept, in en/es/fr:

- `today`, `tomorrow`, `day after tomorrow`
- `next {weekday}` — the first occurrence strictly after today; if today is
  that weekday, seven days ahead
- `this {weekday}` — the next occurrence including today
- `in N days` / `in N weeks` / `in N months` (calendar months, clamped to
  month end)
- `{1st..31st} (of) {month}` / `{day} de {mes}` / `le {jour} {mois}` — the
  next occurrence of that day/month at or after today (rolls to next year)
- `{month} {day}`, `{day}/{month}` (locale order), and ISO `YYYY-MM-DD`
  passed through after validation
- `start of next month`, `end of the month`

Anything else → `ToolError::invalidArguments` with `recovery.hint`
*"ask the customer for the exact date"* — never a guess.

Result: `display` = `"{phrase}" → 2026-08-31 (Monday)`, `FactBag::date()`
on the ISO value (which licenses the slash and dash forms for the draft),
`data = {iso, weekday, phrase, timezone}`, no entities, so no `Refs:` line.

Prompt: replace the S26-02 sentence *"Convert relative dates … using the
date in this prompt"* in `SalesAgentDefinition::roleParagraph()` (and the
support equivalent) with: *"For any relative date the customer gives, call
`calendar.resolve` with their exact words and use the returned ISO date in
tools and in your reply. Never compute a date yourself."*

`crm.create_deal`, `sales.propose_offer`, `sales.create_offer` are
unchanged: they still take an ISO date; it now arrives licensed.

Tests (`CalendarResolveToolTest`, fixed clock via `SiteClock` fake): every
pattern above in three locales; "next Monday" on a Monday → +7; "this
Monday" on a Monday → today; month-end clamp for "in 1 month" from the 31st;
year rollover for "15 January" in August; ISO pass-through; garbage phrase
→ `invalid_arguments` with the hint; `FactBag` contains the ISO date.

### C. Grounding redraft on a single ungrounded token

In `GroundingGuard::check()`:

- If the block is caused by exactly **one** unlicensed token and that token
  is a date or a money amount → return `GuardrailVerdict::retry` with
  instruction: *"The value {token} is not grounded in any tool result.
  Remove it, or ask the customer for the exact date/amount. Do not state a
  value you have not received from a tool."* Bounded by the existing
  `max_redraft_attempts`; exhausted → block + handoff as today. The
  redraft asks the customer (or drops the value); it does not call a tool
  — `applyOutboundGuards` currently treats a redraft that returns tool
  calls as a hard block.
- Two or more unlicensed tokens, or an unlicensed identifier, phone,
  postcode, or email → block + handoff immediately (unchanged).
- Guard event on the retry: `verdict: deny, detail: {token, redraft: true}`
  so the trace shows what happened.

Tests: single date token → retry then a redraft that asks the customer
passes, no handoff; single amount token → same; two tokens → block; one
identifier → block; retry exhausted → block with `grounding_failure`. Add
offline fixture `sales/grounding-date-redraft` with a scripted cassette
(first draft contains an ungrounded date, second asks the customer and
passes), sealed with `--seal`, following the S26-04 pattern.

### D. Email subject synthesis

`ChannelGuard` (email profile), `subject_synthesized` path:

1. If the draft's first line matches `^Subject:\s*(.+)$`, use it as the
   subject and strip the line from the body. Add to the sales/support prompt
   for the email channel only: *"Start your reply with a line `Subject: …`
   of at most 70 characters."*
2. Otherwise synthesize: first sentence of the body; if longer than 70
   characters, cut at the last clause boundary (`,` `;` `:` `—` `–`) before
   70; if none, cut at the last word boundary before 70 and append `…`.
   Strip trailing punctuation. Never end on a stopword (the, a, an, is, of,
   to, for, which, that, and, or) — drop trailing stopwords after the cut.
3. Locale stopword lists for es/fr in `config/ai-handoff.php` beside the
   disclosure strings.

Tests with the turn-2 draft: expected subject
*"We found several Keevaris sites in Madrid"*; a draft with an explicit
`Subject:` line uses it and the body no longer contains the line; es/fr
stopword trimming.

## Cassettes

B changes `schemaHash` (new tool in both definitions) and `promptHash`
(prompt sentence); D changes the email prompt. Every cassette goes stale:
`agent:replay --seal` for those whose outcomes don't change, and live
re-record for `sales_madrid_boxes_to_offer.yaml` (turn 9 will now call
`calendar.resolve` before `crm.create_deal`) plus any fixture that
previously hard-coded a date in a scripted draft. List them in the PR.

## Acceptance criteria

- [ ] Identity block contains `Today: {ISO} ({weekday})` with and without a
      site.
- [ ] `calendar.resolve("next Monday")` on 2026-08-26 → `2026-08-31
      (Monday)`, FactBag licenses `31/08/2026` and `2026-08-31`.
- [ ] Replay of `sales_madrid_boxes_to_offer.yaml`: turn 9 calls
      `calendar.resolve` then `crm.create_deal` with
      `expected_move_in = <resolved date>`; zero handoffs.
- [ ] Single ungrounded date in a draft → retry, then pass; no handoff row.
- [ ] Two ungrounded tokens → block + `grounding_failure` as before.
- [ ] Email subject for the turn-2 draft is a complete clause under 70
      chars; explicit `Subject:` line is honoured and stripped.
- [ ] `AgentToolCoverageTest` green with the new tool claimed by both
      definitions.

## Out of scope

- Time-of-day resolution ("Monday morning") — dates only.
- Recurring dates, ranges ("between the 1st and the 5th").
- Changing the redraft budget default.
