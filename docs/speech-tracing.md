# Speech trace diagnostics

Search `log/chim.log` and the client's `AIAgent.log` for `[SPEECH_TRACE]` and the same `utterance_id`. These are diagnostic log records, not gameplay events or NPC memories. Existing log levels, trimming, and diagnostic collection apply; no new table or setting is required.

Server stages cover sentence preparation, connector attempts, actual cache returns, audio-ready validation, voice filtering, emission, and client acknowledgements. `run_id` links to existing request-performance logs when available; `request_id` and `sentence` group streamed lines. NPC dialogue and separately voiced inline narration each retain their own ID. Director scenes retain their existing line IDs and report `queued_for_delivery` after publication.

The paired client records receipt into the speech manager, queueing, dequeue delay, download, audio start, and outcomes. `subtitle_only_completed` explicitly means audio initialization failed and the existing subtitle timing fallback ran. `client_acknowledged` means the existing speech callback arrived; it is not independent proof that audio was audible. Emission does not prove delivery either.

Durations use each process's monotonic clock. Never subtract a client monotonic timestamp from a server timestamp. Join by utterance ID instead. Cache-hit markers are emitted at the existing cache-return branches; connectors without local caching have no cache-hit stage. Logs omit dialogue, audio, credentials, and provider URLs.

Incomplete traces remain incomplete: an absent client stage can mean a lost line, stopped process, older client, or unavailable logs. Older clients and legacy lines without IDs retain their existing behavior. No playback, cancellation, retry, or cache policies change.
