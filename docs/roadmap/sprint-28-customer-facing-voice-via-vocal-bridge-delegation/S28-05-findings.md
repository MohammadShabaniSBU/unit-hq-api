# S28-05 findings — disclosure, recording, residency

S28-07 copies from here. Legal sign-off, vendor answers, and the listened-to
greeting are launch gates, not merge gates.

## Draft greeting (Art. 50 only)

Shipped in `config/ai-handoff.php` `voice_greeting` and generated into
`vb-customer-config.json`. `{company}` is unsubstituted. Replace with the
operator's registered name at Vocal Bridge paste time.

| Locale | Exact string in config |
|---|---|
| en | `I am an automated assistant for {company}.` |
| es | `Soy un asistente automatizado de {company}.` |
| fr | `Je suis un assistant automatisé de {company}.` |

Byte-identical to `disclosure.*` today on purpose. Voice will take a
recording clause later; the spoken line has a different legal sign-off
owner than chat. Do not make `voiceGreeting()` delegate to `for()`.

Greeting locale is the **site default** (`SiteLocale` from the site country
the number is bound to). Not detected from the caller. A bilingual market
needs a second number or a bilingual greeting.

## Legal review

Record **date + the exact greeting string(s) reviewed**. A later edit voids
the sign-off; `VoiceBridgeCustomerConfigTest` fails CI if config and the
committed JSON drift.

| Field | Value |
|---|---|
| Reviewed at | _pending_ |
| Reviewer | _pending_ |
| Strings reviewed | _pending — paste the exact en/es/fr lines, including `{company}`_ |

## Recording clause (launch gate)

**Intent:** recording off for milestone one (D-AI-27). Transcript is already
in `agent_conversation_messages` and `voice_session_turns.answer_text`.

**Greeting does not claim not-recorded.** Vocal Bridge docs mention platform
logs; audio may be retained for their operations whatever our setting says.
A false recording statement is worse than silence.

Blocked until:

1. Written vendor answer: recording can be left off; what platform logs
   retain even then.
2. Legal sign-off on any added clause.

Optional narrower claim if wanted before a full not-recorded statement:
"we do not store a recording of this call" — also needs legal sign-off.
Do not ship either wording in `voice_greeting` until then.

## AR-03

`voice_sessions` (`caller_number`, `contact_id`, `bridge_session_id`) and
`voice_session_turns.answer_text` added to the AR-03 table list. Processor
audio joins if Vocal Bridge retains call audio.

**Customer voice does not launch while AR-03 is open.** Closing AR-03 is a
separate piece of work.

## Vendor questions

Human sends. Do not implement replies this sprint. Egress IPs: S28-07
records; no allowlist until they reply.

### Aircall solutions

| Question | Asked | Answer |
|---|---|---|
| Real-time bidirectional media streaming? | _pending send_ | |
| SIPREC or media forking? | _pending send_ | |
| Bring-your-own SIP trunk? | _pending send_ | |

Decision recorded regardless (D-AI-28): Vocal Bridge numbers answer AI;
transfer humans to Aircall; Aircall stays CRM-level via webhooks. Plan for
no.

### Vocal Bridge

| Question | Asked | Answer |
|---|---|---|
| Egress IP ranges (published list?) | _pending send_ | |
| Where is audio processed? | _pending send_ | |
| Where does any transcript rest? | _pending send_ | |
| Which model sits behind the foreground agent, and where? | _pending send_ | |
| Processor agreement covering audio + transcript + foreground model? | _pending send_ | |
| Retention defaults, configurable? Confirm recording can be left off. What do platform logs retain even when recording is off? | _pending send_ | |
