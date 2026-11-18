# Gate 9.5 slice 3b — Review round 2 (fix round, independent, read-only, Claude Opus 5.5 subagent, 2026-11-18)

Verdict: **PASS_WITH_NOTES**. Manifest 26/26 OK. R1 (settle loop up to the configurable bound), R2 (failed forced rebuild → Mongo
count), R3 (outage bound wording), R4 (missing gen = not built, SET NX), R5 (transfer always drops windows when Redis reachable,
never releases a foreign lock), R6 (wording): all RESOLVED. R1 question: a loser outliving the bound reads only when the Mongo count
(excluding the winner) shows room — never without quota; an extra unrecorded read needs the winner to stall > 3.5 s and then be denied.
MINOR: crashed winner → reopen within 60 s may show the article without recording (bounded); allowance() can under-count after a
transfer that met a foreign rebuild lock until the next decision. NIT: `nosql` connection has no read timeout. All three documented
by control in docs/database/nosql-local.md (known limitations).
