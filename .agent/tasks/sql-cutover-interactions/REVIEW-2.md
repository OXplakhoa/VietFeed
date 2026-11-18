# Gate 9.5 slice 3a — Review round 2 (fix round, independent, read-only, Claude Opus 5.5 subagent, 2026-11-18)

Verdict: **PASS_WITH_NOTES**. Manifest 38/38 OK. F1 import guard (--force, no duplicates), F2 marker removal / trending sentinel +
lazy rebuild + Mongo fallback / throttle 30/min per user with JSON 429 handled by unchanged JS, F3 separate demo key: all RESOLVED.
Rate limiter backed by the default cache store (array in tests); Gate 8 login/API limiters unchanged.
MINOR: a home-triggered full rebuild holds the trending lock and can push live consumer deliveries through retries toward the DLQ
(replayable, idempotent; fine at demo scale); the 30 s lock without renewal means a very large rebuild never finishes and the
swallowed error is not logged. NIT: limiter lookup unguarded if RateLimiter were resolved early (no such path today); demo key has
no lock/sentinel.
