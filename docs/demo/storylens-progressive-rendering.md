# StoryLens — Scenario 1 in the browser (Gate 6 PR-B / #19)

The web process and every CLI command must point at the same stores. Run this only
on a disposable/demo dataset. The implementation worker has not executed this
walkthrough end to end (including its browser steps). Use the four
stores healthy, no cached config, and the usual local app and Vite/build setup.
Do not use production data. Do not restart/stop containers or delete volumes.
The app layout/navigation reads Mongo configuration alongside canonical Mongo Stories;
run `php artisan migrate --force` (Mongo indexes) and the schema commands first (Gate 9.5 slice 5: no SQL).

Routes:

- `GET /stories/{story}` — public canonical UUID, evidence first; malformed/unknown IDs return 404.
- `GET /stories/{story}/sections` — public JSON Story/evidence/section response, `Cache-Control: no-store`.
- `GET /admin/processing` — existing auth + admin middleware; guests go to login, other users get 403.
- `POST /admin/processing/retry` — admin + CSRF; fields `section` (`timeline` or `graph`) and `id` (DLQ stream ID).

Use the same environment/store selection for every command and the web process.
Test isolation is documented in [nosql-local.md](../database/nosql-local.md#tests-and-boundaries):
Mongo `vietfeed_test`, Redis DB15 with a live guard, no SQL connection, Cassandra
`vietfeed_test`, and UUID-scoped graph cleanup. The automated HTTP walkthrough is
`vendor/bin/phpunit tests/Integration/StoryPageProjectionsTest.php`; it uses one
Article, creates no server, and cleans only its owned projections. A separately
started web process must use the same isolated store names (Mongo `vietfeed_test`, DB15).

Automated checks (run sequentially on the isolated test stores):

```bash
vendor/bin/phpunit tests/Feature/StoryPageTest.php
vendor/bin/phpunit tests/Integration/StoryPageProjectionsTest.php
npm run test:storylens
```

The npm script runs the dependency-free Node VM polling harness; it does not
perform browser or manual acceptance.

## Seven steps

1. **Disable the graph projection consumer.** Do not start any normal graph worker
   during the fault phase. These are bounded manual consumers, with no daemon or
   supervisor added. Keep Neo4j itself running. Only the failing consumer commands
   below receive `NEO4J_BOLT_PORT=1`; this forces the same connection-refusal fault
   used by the integration tests, without changing `.env` or container state.
   Browser: keep the app home page and an admin session available.

2. **Replay the frozen real-RSS fixture into an empty disposable demo dataset.**
   Run the explicit schema bootstrap once, then ingest:

   ```bash
   php artisan storylens schema
   php artisan storylens ingest
   php artisan storylens inspect --section=graph
   ```

   Choose a Story UUID from the inspection and set it locally:

   ```bash
   STORY='<canonical-story-uuid-from-inspection>'
   php artisan storylens read --story="$STORY" > /tmp/storylens-before.json
   ```

   Browser: open `/stories/<that-uuid>`. On a fresh dataset the response already
   contains the Story title and all attributed Articles; both sections say
   `Đang xử lý (pending)`. Existing fixture data makes ingest a no-op; use a fresh
   disposable dataset for this walkthrough rather than deleting development data.

3. **Show canonical evidence and a working timeline.**

   ```bash
   php artisan storylens consume --section=timeline --consumer=demo-timeline --limit=100
   ```

   Browser: retain the same Story page. Evidence remains present; timeline settles
   to `Sẵn sàng (ready)` and shows newest events first (PR-A order). Graph remains
   `pending`. Polling updates the sections without navigation/reload.

4. **Force graph failure through the bounded retry policy.** For the ten-item
   fixture, run five bounded passes; one-second gaps exceed the test/demo backoff
   base used only by these commands:

   ```bash
   for pass in 1 2 3 4 5; do
       NEO4J_BOLT_PORT=1 STORY_RETRY_MS=1 php artisan storylens consume --section=graph --consumer=demo-graph-fault --limit=10
       sleep 1
   done
   php artisan storylens inspect --section=graph --story="$STORY"
   ```

   Browser: graph says `Tạm thời chưa thể tải mục này… (failed)`; timeline remains
   ready and original Articles stay readable. No driver/host/query text appears.
   A handled projection failure may exit 0: inspect the recorded state and DLQ,
   not just the command exit. Page polling slows to about ten seconds on failure.

5. **Inspect dead projection work.** Browser: visit `/admin/processing` using the
   admin session (the admin nav includes `Xử lý Story`). Find graph DLQ entries
   with entry ID, Story link, event type, version and five recorded attempts.
   Last-error details are replaced with a generic notice. Pending rows show only
   recorded delivery ID, consumer, idle milliseconds and delivery count: their
   inspection API does not include event metadata/last error. Counts are totals;
   listings show up to the first 1,000 pending and 100 DLQ entries per section.
   Story summary rows show at most the 50 newest Stories. DLQ rows use titles from
   that snapshot; older or missing Stories have a neutral label next to the ID.
   Opening this page can initialize both Redis consumer groups (and an empty stream
   if absent): inspection calls the same `XGROUP CREATE ... 0 MKSTREAM` as consumers.
   It neither consumes nor ACKs events; starting at 0 preserves existing backlog.
   This metadata side effect is expected; opening the page is not strictly read-only.
   Stream length is shared history, not an outstanding-work count. Story states
   here are Mongo processing markers; the public page also detects read outages.

6. **Restore and retry.** The fault ends by omitting `NEO4J_BOLT_PORT=1` from the
   next consumer invocation; no container or `.env` change is needed. Browser:
   click `Thử lại` on each graph DLQ row for the chosen Story. Each CSRF-protected
   POST only requeues the envelope. Refresh the admin page to see DLQ counts
   decrease. A repeat submission gives a generic error and adds no extra event.
   Then run normal consumers:

   ```bash
   php artisan storylens consume --section=graph --consumer=demo-graph-restored --limit=100
   php artisan storylens consume --section=timeline --consumer=demo-timeline --limit=100
   ```

   Shared-stream replays are also seen by the timeline group; its version guard
   ignores duplicates. No HTTP request executes a projection. Replayed graph work
   may remain `failed` until the consumer processes it; the page keeps polling.

7. **Show recovery without re-ingest.** Browser: return to the still-open Story
   tab. Graph becomes `ready` with covering Sources and Article counts, timeline
   stays ready, and polling stops once both are ready. If the tab was hidden,
   polling resumes when visible. On a failed poll, the last good section render
   is retained. No JavaScript: reload manually to see new state.

   ```bash
   php artisan storylens read --story="$STORY" > /tmp/storylens-after.json
   php artisan storylens inspect --section=graph --story="$STORY"
   php -r '$a=json_decode(file_get_contents("/tmp/storylens-before.json"),true); $b=json_decode(file_get_contents("/tmp/storylens-after.json"),true); $ids=fn($x)=>array_column($x["evidence"],"id"); if ($a["story"]!==$b["story"] || $ids($a)!==$ids($b)) { exit(1); } echo "Canonical Story and Article identities unchanged\n";'
   ```

   No ingest runs after step 2. The permanent HTTP integration test additionally
   asserts exact global canonical counts/IDs and single projection facts after
   retry. No AI, clustering, credibility score or general performance claim is
   implied by the tracer display.
