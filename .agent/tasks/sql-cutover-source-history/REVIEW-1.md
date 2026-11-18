# Gate 9.5 slice 4 — Review round 1 (independent, read-only, Claude Opus 5.5 subagent, 2026-11-18)

Verdict: **PASS_WITH_NOTES**. Manifest 25/25 OK. Fetch path never touches Cassandra (publish catches everything; Mongo summary saved
first); no runtime reader of source_fetch_logs/SourceFetchLog/fetchLogs except the offline import; ConfigSqlImport fine; page times
still Asia/Ho_Chi_Minh; partitions (source, day)/(day), UUIDv7, revision write-timestamps, TTL 30 d; import deterministic ids + guard.

1. MINOR: a silent Cassandra peer hangs readers/consumer (driver stream_select with null timeout); with runInBackground +
   withoutOverlapping(1) a new hung consumer piles up every minute (shared client issue; pile-up new here).
2. MINOR: failed outcome publish (invalid UTF-8 error_message, Redis blip) leaves an attempt "running" for 30 d; "queued" published
   before dispatch can stay "queued" if dispatch throws.
3. MINOR (docs): stream/DLQ eviction loses history; long Cassandra outage dead-letters everything (needs inspect/replay).
4. MINOR: Health page up to ~75 CQL calls per page and global look-back up to 31 day partitions (bounded).
5. MINOR: null fields on queued/running snapshots create tombstones.
6. NIT: scheduler entry is the only one with runInBackground — comment why. 7. NIT: error_message content unchanged vs SQL.
