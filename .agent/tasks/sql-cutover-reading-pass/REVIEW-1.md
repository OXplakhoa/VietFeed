# Gate 9.5 slice 3b — Review round 1 (independent, read-only, Claude Opus 5.5 subagent, 2026-11-18)

Verdict: **PASS_WITH_NOTES**. Manifest 25/25 OK. No BLOCKER/MAJOR; no crash path grants an extra unlock; Lua DECIDE, windows (VN
day via Asia/Ho_Chi_Minh, rolling 5 h), TTLs, Pro/admin, in-flight reservation counting, Redis-down reserve-then-count, session
hashing consistency, labels byte-identical, admin counts, cleanup, Cassandra buckets/idempotency, import guard, SQL readers: OK.

1. MINOR: second concurrent request for the same new article waits only ~150 ms for the first to grant; slower first request
   (cold rebuild, 1 s lock wait, Redis timeout) → transient locked page while `remaining > 0`.
2. MINOR: `decide()` ignores a failed forced rebuild and runs DECIDE on the pre-outage window → bounded over-grant (≤ number of
   fallback grants for that subject); mixed Redis/fallback concurrency has the same bounded gap.
3. MINOR (docs): outage guarantee overstated; bound not stated (pure fallback never over-grants; only the mixed case, ≤1 per
   in-flight fallback grant).
4. MINOR: missing `reading-pass:v1:gen` key reads as '' so pre-import windows (`~ready:`) can be trusted again after eviction.
5. MINOR: transfer with the user lock held elsewhere does not drop the user window → stale allowance() until next unlock.
6. NIT: "lock key evicted while held" residual wording pessimistic (LOAD re-checks owner); real residual is the transfer lock.
7. NIT: guest request granted after transfer stays under the old guest subject (legacy same).
8. NIT: admin users list makes one quota call per user (legacy similar).
