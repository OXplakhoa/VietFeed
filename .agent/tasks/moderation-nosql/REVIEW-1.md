# Gate 9b — Review round 1 (independent, read-only, Claude Opus 5.5 subagent, 2026-11-17)

Verdict: **PASS_WITH_NOTES**. Manifest 43/43 OK. No BLOCKER/MAJOR. TASK outcomes 1–10 PASS (5, 8, 9 with notes); 9a rules 1–14
and owner decisions 1–3 PASS. MySQL rehearsal of the widen migration was done by control (varchar(36), value and index kept,
rollback/re-migrate exit 0).

MINOR
1. applyHide: revoke between the revoke check and markHide, then crash before the re-check → comment hidden by a revoked sanction,
   invisible to repair (ModerationService.php:349-358; ModerationCases unfinished()).
2. Request stalled past the 600 s lease → claim released → late insert + silent confirm()=false → second sanction possible;
   `moderation:repair --lease=0` can release in-flight claims.
3. Cutover window: legacy SQL sanctions unenforced between deploy and `moderation:import-sql` — document back-to-back, maintenance mode.
4. Out-of-order sync (late ZADD after ZREM) leaves stale members; badge (ZCARD) inflated until the queue tab is read.
5. Rebuild: TMP key evicted between batches → partial set trusted (count == ZCARD).
6. Tests: atomicity tests are sequential; stale-version path of the conditional write never forced; no tests for 1, 2, 4.
NIT
1. Tie-break differs between Redis and Mongo fallback (documented). 2. case_version column holds the sanction version for sanction
events. 3. register() does not check report status (decided unregistered report reopens a case). 4. COMMENT_HIDDEN on revoked S
without COMMENT_UNHIDDEN in the MINOR-1 race. 5. Processing page drops the moderation section when the Story overview fails.
