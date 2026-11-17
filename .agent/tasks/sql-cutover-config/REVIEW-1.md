# Gate 9.5 slice 1 — Review round 1 (independent, read-only, Claude Opus 5.5 subagent, 2026-11-18)

Verdict: **FAIL** (2 BLOCKER, 2 MAJOR). Manifest 38/38 OK; SourceSeeder works unchanged.

- BLOCKER 1: admin Category form stores `is_active` as string "1"/"0" (laravel-mongodb does not cast on write) → `Category::active()` misses edited/new categories (nav, home, search, 404). Tests never post the real form payload.
- BLOCKER 2: `config:import-sql` copies `is_active` as int 1/0 → every imported category/source inactive; FetchFeeds fetches nothing.
- MAJOR 1: snapshot ids changed from deterministic uuid5 to canonical/uuid7 + import rewrites snapshots → Neo4j MERGE by uuid keeps old FROM/IN edges → duplicate Source/Category nodes, doubled sources on StoryLens, inflated source_count; random uuid per item in some paths.
- MAJOR 2: Source Health loads the whole fetch-log history per request (limit 5 applied in PHP).
- MINOR: duplicate SQL feed_url silently merged by import; seed-then-import with owner's changed Tuổi Trẻ feed_urls duplicates sources (undocumented); `prestige` stored as string.
- NIT: FK dropped by guessed name; CategoryList has no Redis-down fallback (layout 500); stale cache ≤300 s after fill race; FetchSourceFeedJob string id vs in-flight int jobs; redundant active-id plucks.
