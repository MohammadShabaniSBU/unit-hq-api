# Sprint 28 — Customer-facing voice via Vocal Bridge delegation

**Origin:** the concierge agent can now answer a prospect and a tenant on one
thread (S27). Voice is the channel it cannot answer on. `AgentChannel::Voice`
exists in the enum, `ChannelProfile::Voice` exists with its 600-character
ceiling, and `POST /api/agent-conversations` returns 422 for
`channel=voice`. Everything else is missing.

Vocal Bridge is already in the codebase for the **copilot** — the panel holds
the SDK, `POST /api/copilot/voice/token` mints access, `copilot_voice.use`
gates it, and `copilot_voice_sessions` records it. That integration runs
client-side: the browser holds the media stream and answers `onAIAgentQuery`
over the data channel. Customer voice is the other mode of the same product:
Vocal Bridge hosts the conversation, and when its foreground agent decides a
question is domain-specific it **POSTs a query to an HTTPS endpoint we host**
and speaks our answer back.

That shape is why this sprint is small. We are not building a voice pipeline.
We are building one endpoint, opening one channel, and being careful about
four things that voice breaks.

## The four things voice breaks

**1. The guards protect our draft, not what gets spoken.** Vocal Bridge
offers adaptive or verbatim response modes. In adaptive mode its foreground
model paraphrases our answer before speaking it. `GroundingGuard`,
`ForbiddenClaimGuard` and `DisclosureGuard` validated *our* text; a paraphrase
of it is unvalidated speech in the company's name, with our numbers
rearranged by a model we don't control. Verbatim is best-effort on the native
voice and guaranteed only with external TTS. See the launch gate below,
and invariant 73.

**2. There is a second LLM talking to our customer.** The foreground agent
is not ours and will answer from its own knowledge if its prompt lets it.
Number discipline has to be applied one layer up: it states no price,
availability, date, balance or unit identifier, ever, and delegates all of
them. See S28-02.

**3. Caller ID is not identity.** It is trivially spoofable, so voice tops
out at `channel_asserted`. S27-04 made `verified` reachable by OTP over a
channel already on the contact; a code read aloud on a call is a different
threat model and is **not** in this sprint. Account questions on voice are
transfers. See invariant 74.

**4. Sixty seconds is not a phone call.** `agents.turn_timeout_ms` is 60,000.
The whole runtime — nine gates, guards, redraft loop — runs synchronously
inside a request the caller is waiting through in silence. See S28-04.

## Prerequisite

**S27-01's recording pass must land first.** `tests/Fixtures/agents/concierge/cassettes/`
is empty, so `agent:replay` cannot be green today. This sprint adds a voice
`ChannelProfile` to the prompt and voice fixtures to the suite; recording
those against a suite that is already red gives no signal about either.
S27's roadmap exit criterion already records this as outstanding.

## Findings → tasks

| # | Finding | Evidence | Task |
|---|---|---|---|
| 1 | `AgentChannel::Voice` is excluded from `bindable()` and 422s on conversation create | `AgentChannel.php`, `AgentConversationController::store` | S28-00 |
| 2 | `deriveAudience()` returns `Internal` when `contact_id` is null and origin isn't `demo` — an unknown caller would open an employee-audience conversation | `AgentConversationController` | S28-00 |
| 3 | Every `agent-conversations` route sits inside `auth:sanctum`; a PAT in a third-party dashboard is an employee identity with the whole API behind it | `routes/api.php` | S28-01 |
| 4 | Vocal Bridge sends `query` + `turn_id` per delegation and offers `late_response_behavior` — we need idempotent replay and a session mapping | Vocal Bridge integration docs | S28-01 |
| 5 | `ChannelProfile::Voice` already exists at 600 chars / 2 sentences | `ChannelProfile.php` | S28-02 |
| 6 | `agent.escalate` writes an `agent_handoffs` row and stops; on voice that must also transfer | `EscalateTool` | S28-03 |
| 7 | `agents.turn_timeout_ms` is 60,000 and `RespondWithAgent` holds an `ai` queue worker per turn | `config/agents.php` | S28-04 |
| 8 | The foreground agent speaks first, so `DisclosureSentence` in our first delegated reply is too late for EU AI Act Art. 50 | `DisclosureSentence` | S28-05 |

## Sequencing

```
S28-00 ──┬── S28-01 ──┬── S28-02 ──┬── S28-04
         │            │            └── S28-03
         │            └── S28-06 (panel)

S28-05 (compliance — starts day one, blocks launch not merge)
S28-07 (last — invariants + docs)

[launch gate] Vocal Bridge dashboard configuration — not a task, a
             checklist. Blocks the first live call, not any merge.
```

S28-05 is the one to start immediately and finish last: the Art. 50 wording
and the recording-consent posture need a legal read, and a legal read is not
something to begin in the final week.

## Launch gate — Vocal Bridge dashboard configuration

Not a task and not code. A checklist that blocks the first live call, owned
by whoever holds the Vocal Bridge account. Half of this sprint's safety
properties are settings in someone else's dashboard, so they are written down
here and re-checked before launch rather than trusted to memory.

| Setting | Value | Why it matters |
|---|---|---|
| AI agent endpoint URL | the S28-01 bridge URL, per number | |
| Protocol | HTTP | the A2A option buys nothing here |
| **Response mode** | **Verbatim** | the sprint's load-bearing setting — see below |
| **External TTS** | **on** | verbatim is best-effort on the native voice, guaranteed only with external TTS |
| Custom header | the HMAC header from S28-01 | the endpoint is public; this is what authenticates it |
| Max characters per turn | 600 | matches `ChannelProfile::Voice` |
| Late response behaviour | **Store**, never Speak | S28-04 — a late answer spoken after the conversation moved on is worse than none. Paired with `agents.voice.late_response_behavior` and the 8s turn budget. |
| Per-query timeout | **10s** (just above `channel.voice.turn_timeout_ms` = 8s) | S28-04 — Vocal Bridge must wait longer than we will spend |
| Filler audio | **on**, short hold phrase (must not loop past the 8s budget) | S28-04 — configuration, not code; silence is the latency the caller feels |
| Transfer | enabled, with only the approved destinations from S28-03 | a model that can dial arbitrary numbers is a different product |
| Worker pool | php-fpm / nginx for `/api/voice/bridge`; `queue:work --queue=ai` is email/copilot only | S28-04 — voice never shares the `ai` queue worker |
| Greeting `{company}` | replace with the operator's registered name before saving | `vb-customer-config.json` ships the unsubstituted template; a seed or env name must never be baked in |

**Verbatim is the setting the sprint rests on.** In adaptive mode the
foreground model paraphrases our answer before speaking it. Our draft passed
`GroundingGuard`, `ForbiddenClaimGuard` and `DisclosureGuard`; a paraphrase of
it passed nothing, and every number in it can move. If verbatim plus external
TTS cannot be confirmed by listening to a real call, invariant 73 must be
written as aspirational rather than as fact (S28-07).

**The foreground prompt is ours, even though it runs elsewhere.** Keep the
text in the repo alongside the API changes and paste it into the dashboard, so
it is reviewed and diffable rather than living only in a text box. It must say:

- Never state a price, rate, discount, availability count, date, balance,
  invoice figure, unit number, or access code. Delegate any question needing
  one.
- Never answer a question about a specific customer's account. Delegate it.
- Never speculate about what the company offers. Delegate it.
- Open every call with the fixed disclosure line (S28-05) for the **site
  default locale** before anything else. Do not infer locale from the
  caller. Replace `{company}` with the operator's registered name at paste
  time.
- Speak the delegated answer as given.

That is number discipline applied one layer up. Our tools enforce it on our
side; only this prompt enforces it on theirs.

**One number, one site.** A number serving several sites has to establish
which site before quoting, and that logic does not exist. Do not configure
one.

**Before the first live call:** place a real call, and compare the spoken
audio against `agent_conversation_messages` for the same turn. That comparison
is the only evidence that verbatim is actually verbatim, and S28-07 depends on
its result.


## Definition of done

One phone number, one site, a real call:

1. An unknown caller reaches the number. The foreground agent opens with the
   fixed disclosure line and takes the call.
2. The caller asks what sizes are available. Vocal Bridge delegates; our
   endpoint answers from `facility.size_guide` and `facility.find_sites`;
   the answer is spoken **verbatim**.
3. The caller asks a price. The agent gives the price and offers to text the
   quote; the figure that lands in writing came through `pricing.quote` and
   `GroundingGuard`.
4. The caller asks about their balance. The agent does not answer, does not
   guess, and transfers to an approved destination.
5. p95 endpoint latency is measured and recorded.

Steps 3 and 4 are the sprint. Steps 1, 2 and 5 are the plumbing that makes
them possible.

## Not in this sprint

- **Tier 2** (realtime speech-to-speech with tools called directly). It is
  sub-second and it deletes every outbound guard. Revisit when the Tier 1
  latency measurement says it must be.
- **Voice OTP.** Caller ID stays at `channel_asserted`; account questions are
  transfers. A code read aloud is a separate threat model.
- **Aircall.** No evidence of first-party bidirectional media streaming.
  Pragmatic split is recorded in S28-07; the integration stays at CRM level
  via webhooks.
- **Multi-site voice.** One number, one site. A multi-site number must
  establish the site before quoting and that logic isn't built.
- **Outbound calling.** Inbound only.
