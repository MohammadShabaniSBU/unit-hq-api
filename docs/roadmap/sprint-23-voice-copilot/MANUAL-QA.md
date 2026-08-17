# Sprint 23 — Manual QA (voice copilot)

Panel CI is lint + typecheck only. Run this against a live API + Reverb + a
configured Vocal Bridge agent (`docs/roadmap/sprint-23-voice-copilot/vb-config.json`)
before merge.

1. Happy path: ask "how many units are free at the main site" → spoken answer matches the on-screen text.
2. Approval path: ask for something that triggers `CreateTask` → the agent speaks its intent and stops → approve on screen → the continuation is **pushed and spoken**, and the pre-approval half is **not** repeated.
3. Slow path: force a >25s turn (or kill Reverb) → hang-guard filler is spoken → real answer pushed after if the stream still completes.
4. Reject path: reject the approval → spoken acknowledgement, no repeat.
5. Concurrency: speak a second question while one is streaming → busy line, no second dispatch, no interleaved text.
6. Slideover: close the copilot mid-turn → answer still arrives and is spoken.
7. Socket drop: kill Reverb mid-turn → filler fires at the hang-guard, no hang; reconnect refills the transcript.
8. Barge-in: interrupt mid-answer → playback stops, turn still completes.
9. Abandoned approval: leave it 5 minutes → turn clears, mic still usable.
10. Permission: an employee without `copilot_voice.use` sees no mic button.
11. Conversation switch: start a voice turn, select another conversation (or New) before `stream_end` → agent speaks the interrupted line, turn clears, mic still usable; original conversation still receives the streamed answer on screen when reopened.
