# REVIEW-3 — Gate 10.1 (Claude Opus 5.5, read-only)

## Verdict: APPROVE

All REVIEW-1 (Opus via agy) and REVIEW-2 (interim GPT 6.1 Terra) findings fixed: 240 s per-delivery budget + 270 s SIGALRM only for
`--consumer=scheduler` (below the 5-minute mutex; refuses without PCNTL; caller alarm/handler preserved and restored; manual runs get
the budget only); fixture-only synthetic selection (URL + `story_id` + `story_key`, refuses incomplete fixture); removal docs
(throwaway stores, 30-day window); admin article counts exclude synthetic; lock TTL test.

Whole-slice pass: no behaviour change for real users — `MongoCounts` adds `synthetic $ne true` (matches missing field), real
trending stays on `KEY` and synthetic goes to a per-seed key, live rebuild uses `Boost::real()`, purge retract unchanged for real
boosts, `UnlockLedger`/`ReadingPassService` real paths unchanged, `CleanupOldGuestUnlocks` equivalent filter, dashboard filters
synthetic only, report refusal only for anonymized comments, ingest fills only null ids via conditional updates (fixture feed URLs
exist on `main`, independent of the owner's SourceSeeder WIP).

## NITs (no change required)

- `ProjectionConsumeBudget.php:37-42`: if a caller alarm shorter than 270 s fires with a non-exiting handler, the 270 s bound is not
  re-armed. Not reachable for scheduled runs (fresh artisan processes).
- `:55-57`: restored alarm can drift by up to ~1 s (`intdiv` + whole-second `pcntl_alarm`).
- Process: stage Gate 10.1 files explicitly (owner WIP present in the working tree). Owner note: `SourceSeeder` keyed on `feed_url`;
  re-seeding with changed Tuổi Trẻ URLs would leave the old sources active beside new ones.
