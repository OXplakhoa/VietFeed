# Gate 6 PR-B

## Implementation

### Plan (before implementation)

- Routes: UUID-constrained GET /stories/{story}, GET /stories/{story}/sections; auth+admin GET /admin/processing and POST /admin/processing/retry.
- Controllers: thin Story controller calls StoryLens; admin controller calls StoryPipeline inspection/replay only, validates section/stream ID and returns generic errors.
- Domain additions: small read-only public response sanitizer in StoryLens and processing overview in StoryPipeline if needed; preserve all PR-A behavior.
- Views: existing AppLayout, Vietnamese evidence first, explicit server-rendered section states/data; existing admin nav, counts, real pending delivery metadata and sanitized DLQ details, CSRF retry forms.
- JS: guarded Story root in app.js; text-only updates, 2s pending / 10s failed or error polling, hidden-tab pause, stop at ready/ready, preserve render on failed requests.
- HTTP TDD: canonical pending evidence, UUID/unknown 404s, legacy Story, JSON contract/error sanitization; admin access, pending/DLQ listing, retry validation/requeue/CSRF; tiny real-store integration convergence, outage/read degradation and graph DLQ recovery without canonical changes.
- Isolation: existing TestCase and live Redis DB15 guard, SQLite :memory: migrations; integration asserts Cassandra vietfeed_test before writes, unique graph UUID cleanup, one fixture Article. Sequential mutating tests; no dev ingestion/server/consumer or container restart.
- Verification: each new test file three consecutive runs, required regressions/build/Pint/diff/health, before/after memory and restart/OOM counters. Build ignored, not tracked. No commits or delegation.

### Initial environment

Branch storylens/progressive-rendering; HEAD 659d5c0fc9108db237622ce945fcfe3698692c19.
Before: Cassandra 1.454 GiB / 1.5 GiB; Neo4j 647.9 MiB / 1 GiB; Mongo 168 MiB / 768 MiB; Redis 16.78 MiB / 256 MiB.
All restart=0, OOMKilled=false; starts 2026-11-14T16:46:53Z (full timestamps in tool evidence).

### Implementation result

STATUS: **READY_FOR_REVIEW** (implementation evidence; independent Review and
Control/owner acceptance remain separate). Branch and HEAD unchanged. No commit,
stash, push, PR creation, delegation, dev ingestion/consumer/server, container
stop/restart, `.env` edit or dependency change.

### Decisions and why

1. Four web routes reuse AppLayout, Bootstrap and existing CSS variables. UUID
   route constraints reject malformed IDs; StoryLens's Mongo lookup rejects unknown
   IDs. Canonical evidence is before both derived sections in the server document.
   SQL navigation remains unchanged; HTTP tests migrate SQLite `:memory:` using
   RefreshDatabase, with no production SQL access or weakened TestCase guard.
2. PR-A behavior is preserved. Only two small read-only domain methods were added:
   StoryLens::readForPage whitelists section state/projected version/data, dropping
   stored error internals; StoryPipeline::overview adapts existing inspection and
   reads Story markers (including legacy Stories). No existing consumer, apply,
   replay, read, ingest, state or client method changed. Existing inspect may lazily
   initialize its consumer group as before; overview runs no projection or ingest.
3. Server and polling renders show pending/ready/failed explicitly, and render
   derived rows only at ready. Timeline retains PR-A newest-first event order;
   rows expose occurrence time and canonical Article ID. Graph lists Source names
   and Article counts. Evidence includes original title/link, source snapshot name,
   ISO publication time and excerpt. Gate 3 Stories render empty evidence and pending
   sections with version zero. No SQL joins are introduced for evidence.
4. Polling stays in app.js, guarded by the Story root. One timer and one request:
   about 2s pending, 10s failed/error; 8s request timeout. Visibility changes clear
   the timer/abort active work; visibility restoration resumes until ready/ready.
   Prepare both updates before committing them; network/non-2xx/invalid-section
   responses preserve the last good render. All new server-derived insertion uses
   textContent/createElement/replaceChildren. Both-ready first paint starts no poll.
5. Admin uses existing auth+admin and normal web CSRF middleware. Requeue validates
   section and Redis stream ID, catches missing/already-requeued/operational errors
   with generic redirects, and calls the existing stream replay only. No projection
   runs in HTTP. Requeue does not pretend Mongo state has recovered before a consumer
   actually succeeds. Shared-stream replay is still version-idempotent for both groups.
6. Pending inspection really returns [delivery ID, consumer, idle ms, deliveries],
   not event envelopes or last error. The view explicitly says that. DLQ really
   records event envelope, attempts and last_error: show the event identity/version
   and actual attempts, replacing last_error with a generic notice. Never print raw
   errors. Listings retain existing bounds (1,000 pending / 100 DLQ per section),
   clearly labelled alongside total counts. Shared stream length is labelled as
   shared history rather than outstanding work. Story markers use Mongo processing
   state; public reads additionally detect derived-read outages.
7. Integration uses a one-Article subset of the frozen RSS capture, with unique
   Source/Category identities per run and UUID-scoped graph cleanup. Cassandra
   keyspace is asserted vietfeed_test before bootstrap/deletes and guarded inside
   the client on every request. TestCase rejects cached config/unsafe Mongo/SQL;
   Redis flushes use the live DB15 guard. All mutation was sequential. The legacy
   Cassandra spike was checked: this head already uses/asserts vietfeed_test.
8. Build output is ignored by `/public/build` and `git ls-files public/build`
   returns no files; no output is proposed for tracking. Vite's stale Browserslist
   advisory is recorded, without installing/updating unrelated dependencies.

### Routes added

| Method | Path | Name | Access |
|---|---|---|---|
| GET | /stories/{story} | stories.show | Public, UUID constrained |
| GET | /stories/{story}/sections | stories.sections | Public, UUID constrained, no-store JSON |
| GET | /admin/processing | admin.processing.index | auth + admin |
| POST | /admin/processing/retry | admin.processing.retry | auth + admin + web CSRF |

### Owned files created/changed

Created:

- app/Http/Controllers/StoryController.php
- app/Http/Controllers/Admin/ProcessingController.php
- resources/views/stories/show.blade.php
- resources/views/admin/processing.blade.php
- tests/Feature/StoryPageTest.php
- tests/Integration/StoryPageProjectionsTest.php
- docs/demo/storylens-progressive-rendering.md
- .agent/tasks/storylens-tracer/GATE-REPORT-6B.md

Changed:

- app/StoryLens/StoryLens.php (add readForPage only)
- app/StoryProcessing/StoryPipeline.php (add overview only)
- routes/web.php (four routes/imports; Pint also adds one existing closure's blank line)
- resources/views/admin/partials/nav.blade.php (processing link)
- resources/js/app.js (guarded polling initializer/function)
- docs/database/nosql-local.md (append PR-B link/test commands)

Owner/control WIP preserved: six initial tracked files, including TASK.md, checked
byte-identical against pre-implementation SHA-256 snapshots. No writes to unrelated
untracked packets, .pi/, docs/agents/ or pi-warden.md. No PR-A tests edited.

### Acceptance — nine required outcomes

| # | Outcome | Result | Evidence |
|---|---|---|---|
| 1 | Public Story evidence/404/legacy/outages | PASS | StoryPageTest first-response/404/legacy tests; StoryPageProjectionsTest real Cassandra, graph and combined read faults all return HTTP 200 with canonical evidence |
| 2 | Server-rendered pending/ready/failed and derived data | PASS | Feature pending/failed/error-redaction tests; integration ready timeline event/source counts and failed graph with ready timeline |
| 3 | Section JSON and guarded progressive polling | PASS | HTTP JSON tests plus Node VM behavioral harness: root guard, initial-ready stop, 2s/10s cadence, failed→ready, visibility pause/resume, error/invalid-response retention and literal HTML-looking text insertion; real browser not run |
| 4 | Admin view/nav/auth/metadata | PASS | Feature guest redirects, user 403, admin 200, actual pending and five-attempt DLQ metadata, Story links, no raw error text; overview includes both markers/version |
| 5 | Admin POST retry/CSRF/idempotent recovery | PASS | Real CSRF check enabled in HTTP test: missing token 419, valid token reaches handler; invalid/unknown/repeated entries redirect with error; successful retry adds one stream event without projection; integration consumer recovery retains exact Article/Story IDs and counts |
| 6 | Thin controllers/domain boundaries | PASS | Controllers call StoryLens/StoryPipeline/stream replay only, no store clients; views receive sanitized arrays |
| 7 | Feature + real-store integration HTTP tests | PASS | Feature 7 tests/134 assertions in composer suite; integration 2/98 including convergence, all read outage combinations and Scenario 1; each file passes three consecutive invocations |
| 8 | Build/no dependencies | PASS | npm run build exit 0; no composer/npm manifest/lock changes; public/build ignored and untracked |
| 9 | Routes/docs/seven-step Scenario 1/report | PASS | docs/demo/storylens-progressive-rendering.md, appended nosql-local link, this report; demo commands written only |

### TDD and diagnostic evidence

- Initial `vendor/bin/phpunit tests/Feature/StoryPageTest.php`: exit 1,
  7 tests / 59 assertions, six expected failures for absent Story/admin routes;
  404 behavior already passes. Tests preceded production implementation.
- Next feature run: exit 1, 7 / 99, four failures: three reveal an inline Blade
  `@endif` parsing issue, one reveals this Laravel web stack uses
  PreventRequestForgery rather than the deprecated ValidateCsrfToken binding.
  Corrected the Blade directive layout and bound a test subclass of the actual
  middleware overriding only its unit-test bypass. No production CSRF changes.
- Feature green before final formatting: exit 0, 7 / 134.
- Supplemental real-store integration was added after the initial HTTP implementation
  and passed first run (exit 0, 2 / 98); it validates existing PR-A degradation and
  the new HTTP/retry path, not a claimed separate red/green implementation slice.
- Temporary Node harness first failed exit 1 because its extraction left a comment
  fragment in evaluated source; corrected harness extraction, then exit 0. No
  product behavior was changed to accommodate the harness.
- Initial Pint check including routes: exit 1 for fully-qualified imports/order
  and an existing blank-line issue; Pint fixed only routes formatting/imports
  (exit 0). Other owned PHP formatting pass exited 0. Final exact Pint below passes.

### Final verification

Each row is a separate invocation with its true exit code. New files each ran three
consecutive times after final PHP formatting, followed by regression/build/style/
health checks. No pipelines/tail/tee masked statuses. Counts below are observed on
this PR-A head (and supersede the older PR-A report counts).

| Command | Exit | Counts/result |
|---|---:|---|
| `vendor/bin/phpunit tests/Feature/StoryPageTest.php` — run 1 | 0 | {"tool":"phpunit","result":"passed","tests":7,"passed":7,"assertions":134,"duration_ms":646} |
| `vendor/bin/phpunit tests/Feature/StoryPageTest.php` — run 2 | 0 | {"tool":"phpunit","result":"passed","tests":7,"passed":7,"assertions":134,"duration_ms":411} |
| `vendor/bin/phpunit tests/Feature/StoryPageTest.php` — run 3 | 0 | {"tool":"phpunit","result":"passed","tests":7,"passed":7,"assertions":134,"duration_ms":396} |
| `vendor/bin/phpunit tests/Integration/StoryPageProjectionsTest.php` — run 1 | 0 | {"tool":"phpunit","result":"passed","tests":2,"passed":2,"assertions":98,"duration_ms":526} |
| `vendor/bin/phpunit tests/Integration/StoryPageProjectionsTest.php` — run 2 | 0 | {"tool":"phpunit","result":"passed","tests":2,"passed":2,"assertions":98,"duration_ms":449} |
| `vendor/bin/phpunit tests/Integration/StoryPageProjectionsTest.php` — run 3 | 0 | {"tool":"phpunit","result":"passed","tests":2,"passed":2,"assertions":98,"duration_ms":456} |
| `vendor/bin/phpunit tests/Feature/StoryCatalogTest.php` | 0 | {"tool":"phpunit","result":"passed","tests":8,"passed":8,"assertions":193,"duration_ms":531} |
| `vendor/bin/phpunit tests/Integration/StoryProjectionsTest.php` | 0 | {"tool":"phpunit","result":"passed","tests":24,"passed":24,"assertions":445,"duration_ms":3346} |
| `vendor/bin/phpunit tests/Feature/RedisStreamsTest.php` | 0 | {"tool":"phpunit","result":"passed","tests":29,"passed":29,"assertions":495,"duration_ms":978} |
| `composer run test` | 0 | {"tool":"phpunit","result":"passed","tests":102,"passed":102,"assertions":1337,"duration_ms":4255} |
| `vendor/bin/phpunit tests/Integration/CassandraSpikeTest.php` | 0 | {"tool":"phpunit","result":"passed","tests":3,"passed":3,"assertions":36,"duration_ms":210} |
| `vendor/bin/phpunit tests/Integration/Neo4jSpikeTest.php` | 0 | {"tool":"phpunit","result":"passed","tests":4,"passed":4,"assertions":35,"duration_ms":198} |
| `npm run build` | 0 | Vite 8.0.13; 3 modules; ignored public/build emitted |
| `vendor/bin/pint --test app/Http/Controllers/StoryController.php app/Http/Controllers/Admin/ProcessingController.php app/StoryLens/StoryLens.php app/StoryProcessing/StoryPipeline.php routes/web.php tests/Feature/StoryPageTest.php tests/Integration/StoryPageProjectionsTest.php resources/views/stories/show.blade.php resources/views/admin/processing.blade.php resources/views/admin/partials/nav.blade.php` | 0 | PASS |
| `git diff --check` | 0 | PASS |
| `./scripts/nosql-health.sh` | 0 | 4/4 READY |

Additional checks (separate invocations unless described as one inspection batch):

- `node /tmp/storylens-polling-check.cjs`: exit 0; all listed polling behavior
  groups pass. Temporary diagnostic harness is not a committed test/dependency.
- `docker stats --no-stream`: before/after exit 0. Restart/OOM/start-time
  `docker inspect` query before/after: exit 0. Intermediate check also unchanged.
- Owner/control SHA-256 snapshot comparison + no cached config: exit 0.
- `git ls-files public/build`: exit 0, no tracked files;
  `git check-ignore public/build/manifest.json`: exit 0, ignored.
- Dependency/compose diff inspection: no changes to composer.json/composer.lock,
  package.json/package-lock.json or docker-compose.yml.
- Integration regression observed full-fixture convergence 0.224s locally; this
  is existing PR-A test output, not a performance promise for the HTTP page.

### Memory and container safety

| Container | Before | After | Limit |
|---|---:|---:|---:|
| Cassandra | 1.454 GiB | 1.447 GiB | 1.5 GiB |
| Neo4j | 647.9 MiB | 754 MiB | 1 GiB |
| Mongo | 168 MiB | 167.8 MiB | 768 MiB |
| Redis | 16.78 MiB | 17.48 MiB | 256 MiB |

All four containers: restart=0, OOMKilled=false before/intermediate/after; exact
start times unchanged:

- Cassandra: 2026-11-14T16:46:53.162357919Z
- Neo4j: 2026-11-14T16:46:53.161543335Z
- Mongo: 2026-11-14T16:46:53.160790377Z
- Redis: 2026-11-14T16:46:53.161208044Z

No stop condition occurred. Test fixtures stayed tiny; all new integration tests
used one Article per case, deleted only owned graph UUIDs/timeline partitions, and
used guarded test-store cleanup. Existing regression tests retained their fixtures.
No live RSS was fetched and no fixture/consumer/reemit was run against dev stores.

### NOT verified / limitations / deferred items

- No real browser/manual acceptance run: no visual/mobile/accessibility/dark-mode
  inspection, real browser timing, or actual tab lifecycle test. The Node VM harness
  verifies timer/DOM/fetch behavior in isolation, not browser integration; HTTP tests
  verify first-paint HTML and transitions through JSON. No MANUAL_PASS claimed.
- The seven-step CLI/browser demo is written, not executed on development stores.
  Automated HTTP recovery runs in isolated stores and uses the same real port-refusal
  failure technique as PR-A; no container was stopped or restarted.
- Admin listing is a snapshot refreshed manually; existing inspection caps remain
  visible. Story marker listing is currently unpaginated (tiny tracer scope).
  Pending event details/last error are unavailable from the existing pending API;
  no guessed values. DLQ error details are deliberately generic.
- Ready/ready stops polling as required; a later new ingest or outage after that
  stop is seen on a reload. No SSE/background worker/daemon or stream trimming added.
- JSON intentionally includes canonical evidence as well as section states/data,
  matching StoryLens's read contract; no separate payload optimization added.
- Public canonical availability still requires Mongo and the existing app/layout
  prerequisites; Cassandra/Neo4j outages are explicitly exercised. No broad SQL
  migration or redesign of the existing ticker/navigation.
- Vite reports its existing Browserslist dataset is six months old; build passes.
  No dependency refresh was attempted within this gate.
- PR-A carried follow-ups remain deferred: Cassandra-backed interaction replay for
  trending, Gate 5 idempotent move markers and distributed projection/fetch locks.
  PR-B non-goals remain deferred, including AI, clustering, search/home integration,
  Source/Category migration, supervisor setup and any fifth database.
- Independent Review, Control commit creation, stacked PR handling and owner/manual
  acceptance remain outstanding; this worker performed implementation only.

### PROPOSED ATOMIC COMMIT GROUPING

Ordered grouping; no commits created. Group 1 keeps routes/nav/controllers/domain/
views/JS and their HTTP tests together so the suite remains runnable. Group 2 adds
only documentation/report, keeping the suite green. Exclude all owner WIP, including
Control's TASK.md edits, and ignored public/build output from this worker grouping.

1. **feat(storylens): render progressive Story evidence and admin projection retry**
   -> exact paths:
   - app/Http/Controllers/StoryController.php
   - app/Http/Controllers/Admin/ProcessingController.php
   - app/StoryLens/StoryLens.php
   - app/StoryProcessing/StoryPipeline.php
   - routes/web.php
   - resources/views/stories/show.blade.php
   - resources/views/admin/processing.blade.php
   - resources/views/admin/partials/nav.blade.php
   - resources/js/app.js
   - tests/Feature/StoryPageTest.php
   - tests/Integration/StoryPageProjectionsTest.php
2. **docs(storylens): record progressive rendering and seven-step recovery demo**
   -> exact paths:
   - docs/demo/storylens-progressive-rendering.md
   - docs/database/nosql-local.md
   - .agent/tasks/storylens-tracer/GATE-REPORT-6B.md


## Review-fix round 1

STATUS: READY_FOR_REVIEW (implementation worker only; independent re-review pending).
Base remains `659d5c0fc9108db237622ce945fcfe3698692c19` on
`storylens/progressive-rendering`; all PR-B work remains uncommitted.
This section supersedes earlier report claims about unbounded Story listing,
raw timeline IDs/timestamps, temporary-only harness availability, and strictly
read-only admin inspection.

### Accepted findings and regression evidence

1. **P1 unsafe original links — fixed.** `StoryLens::readForPage()` allows
   only FILTER_VALIDATE_URL absolute HTTP/HTTPS values; unsafe legacy values become
   null in the HTTP/JSON link field. Blade renders their canonical title as plain
   text. `test_legacy_unsafe_article_urls_are_plain_text_in_html_and_null_in_json`
   covers javascript, data, scheme-relative, missing-host and whitespace-malformed
   URLs, plus valid HTTP and HTTPS links. Targeted command
   `vendor/bin/phpunit tests/Feature/StoryPageTest.php --filter legacy_unsafe`:
   red exit 1 (1 test / 10 assertions); green exit 0 (1 / 36).

2. **P2 malformed polling rows — fixed.** Validate every row, including rows
   accompanying pending/failed states, in both sections before replacing either
   section or setting stopped. Timeline requires string story/event/article IDs,
   event type, ISO occurrence field and display/title fields, and a nonnegative
   safe-integer aggregate version. Graph requires string uuid/name/url and a
   nonnegative safe-integer Article count. Nulls, arrays, missing fields, wrong
   types and invalid counts preserve both last-good states and lists, schedule the
   10-second error retry, and permit subsequent valid recovery.
   Extended the original Node VM harness, now retained for review at
   `tests/StoryLensPolling.cjs`. Initial
   `node /tmp/storylens-polling-check.cjs`: red exit 1 on a malformed timeline row
   accompanying pending state changing the graph to ready; green exit 0 with 90
   cases, then 102 after adding display-field validation. Final reproducible
   invocation `node tests/StoryLensPolling.cjs`: exit 0, 102 cases, plus root
   guard, ready stop, state cadence/recovery, text-only insertion, network/HTTP/
   section-shape retention and hidden-tab pause/resume. It also checks timeline
   display text and ISO time attribute. **This is a harness check, not a browser run.**

3. **P2 silent controller catch-all — fixed.** Missing/already-replayed entries
   explicitly catch the actual `StreamConsumer::replay()`
   `InvalidArgumentException`; store connection exceptions (including wrapped
   Redis failures) receive a generic response. Unexpected exceptions call
   Laravel `report()`, for both retry and overview, and keep the generic UI.
   No custom exception payload, credential or context logging was added.
   `test_processing_reports_unexpected_exceptions_but_handles_missing_and_unavailable_stores`
   uses the real pipeline and a failing Redis adapter, plus Exceptions::fake().
   `vendor/bin/phpunit tests/Feature/StoryPageTest.php --filter processing_reports`:
   initial red exit 1; an initial fake assertion incorrectly typed Throwable
   rather than Laravel's required exact LogicException class. After correcting
   that assertion, explicitly disabling report() reproduced red exit 1
   (1 / 32, expected LogicException not reported), restoring it gave green
   exit 0 (1 / 32). Existing real-DLQ repeat-submit and unknown-entry tests
   continue to verify clean redirects and no extra publication.

4. **P3 three unbounded Story scans — fixed.** Overview reads Redis metadata
   directly, then performs one Mongo query selecting only id/title/version/
   processing/created_at, newest first (id breaks ties), limited to 50. View
   states the cap. `inspect()` behavior/output are unchanged.
   `test_processing_overview_caps_stories_newest_first_with_only_summary_fields`
   creates 52 Stories, verifies the exact newest/oldest retained titles, one
   logged Mongo query with summary projection and no event history, and the
   displayed cap. Targeted
   `vendor/bin/phpunit tests/Feature/StoryPageTest.php --filter overview_caps`:
   red exit 1 (1 / 8, actual 52 instead of 50); green exit 0 (1 / 17).

5. **P3 reader/admin labels — fixed.** Timeline Article titles are mapped from
   canonical evidence by id with a neutral missing-evidence fallback. Publication
   and event display strings use d/m/Y H:i in Asia/Ho_Chi_Minh, shaped once in PHP
   and reused by Blade and JS; time datetime retains ISO. Admin summary links
   show title next to id, and DLQ links reuse titles from the bounded snapshot.
   `test_timeline_uses_canonical_titles_and_vietnamese_display_times_in_html_and_json`
   covers known UTC-to-Vietnam examples, ISO attributes, canonical title,
   missing-evidence fallback, no raw Article UUID in HTML, JSON display fields
   and admin title/id. Targeted
   `vendor/bin/phpunit tests/Feature/StoryPageTest.php --filter timeline_uses`:
   red exit 1 (1 / 9), green exit 0 (1 / 22); an intermediate expectation
   was corrected to account for the existing configured +07 publication cast.
   Updated earlier publication ISO assertion to retain ISO attribute coverage
   and assert localized visible text. Updated integration's old raw-UUID
   assertion to canonical title/no UUID, with JSON title/display structure
   coverage. Existing projection event identity and recovery coverage remains.

6. **Nav order — fixed.** Xử lý Story follows Health; Dashboard is first.
   `test_admin_inspection_initializes_groups_at_zero_without_consuming_existing_events`
   asserts Dashboard → Health → Xử lý Story in HTML.

7. **Inspection side effect — confirmed and documented; no redesign.**
   `StreamConsumer::lengths()` and `pending()` call the same private
   `group()` used by receive/consume: XGROUP CREATE at 0 with MKSTREAM.
   The test named above starts with existing events and no groups, opens admin,
   asserts two groups at last-delivered-id 0-0 with zero pending, then receives
   the original first event. Thus this metadata initialization is harmless to
   backlog and consumer progress: no event consumption, ACK, skip or projection.
   An absent stream can also be created. Walkthrough step 5 explains the
   operator-visible effect; overview's comment no longer claims read-only.
   First supplemental feature run exited 2 because the test treated a raw
   delivery envelope JSON string as an array; decoding it corrected the test.
   No product change was needed for this supplemental check.

8. **Walkthrough preconditions — fixed.** The first paragraph explicitly says
   web and CLI must point at the same stores, the dataset must be disposable/demo,
   and the worker has not executed the walkthrough end to end, including browser
   steps. The SQLite :memory: limitation remains explicit.

### Final verification — separate sequential invocations

Every row below was a separate command with its true exit code, without tail/tee
or pipeline masking. Store-mutating commands ran sequentially. The repeated rows
are runs 1, 2 and 3 in order, after PHP formatting.

| Command | Exit | Counts/result |
|---|---:|---|
| `vendor/bin/phpunit tests/Feature/StoryPageTest.php` | 0 | {"tool":"phpunit","result":"passed","tests":12,"passed":12,"assertions":259,"duration_ms":895} |
| `vendor/bin/phpunit tests/Feature/StoryPageTest.php` | 0 | {"tool":"phpunit","result":"passed","tests":12,"passed":12,"assertions":259,"duration_ms":637} |
| `vendor/bin/phpunit tests/Feature/StoryPageTest.php` | 0 | {"tool":"phpunit","result":"passed","tests":12,"passed":12,"assertions":259,"duration_ms":637} |
| `vendor/bin/phpunit tests/Integration/StoryPageProjectionsTest.php` | 0 | {"tool":"phpunit","result":"passed","tests":2,"passed":2,"assertions":108,"duration_ms":523} |
| `vendor/bin/phpunit tests/Integration/StoryPageProjectionsTest.php` | 0 | {"tool":"phpunit","result":"passed","tests":2,"passed":2,"assertions":108,"duration_ms":483} |
| `vendor/bin/phpunit tests/Integration/StoryPageProjectionsTest.php` | 0 | {"tool":"phpunit","result":"passed","tests":2,"passed":2,"assertions":108,"duration_ms":468} |
| `vendor/bin/phpunit tests/Feature/StoryCatalogTest.php` | 0 | {"tool":"phpunit","result":"passed","tests":8,"passed":8,"assertions":193,"duration_ms":380} |
| `vendor/bin/phpunit tests/Integration/StoryProjectionsTest.php` | 0 | {"tool":"phpunit","result":"passed","tests":24,"passed":24,"assertions":445,"duration_ms":3005} |
| `composer run test` | 0 | {"tool":"phpunit","result":"passed","tests":107,"passed":107,"assertions":1462,"duration_ms":4694} |
| `npm run build` | 0 | Vite 8.0.13, 3 modules, PASS |
| `vendor/bin/pint --test app/Http/Controllers/StoryController.php app/Http/Controllers/Admin/ProcessingController.php app/StoryLens/StoryLens.php app/StoryProcessing/StoryPipeline.php routes/web.php tests/Feature/StoryPageTest.php tests/Integration/StoryPageProjectionsTest.php resources/views/stories/show.blade.php resources/views/admin/processing.blade.php resources/views/admin/partials/nav.blade.php` | 0 | PASS |
| `node tests/StoryLensPolling.cjs` | 0 | 102 malformed-row cases + existing polling behavior groups PASS; Node VM harness only |
| `git diff --check` | 0 | PASS |
| `./scripts/nosql-health.sh` | 0 | 4/4 READY |

Formatting invocation before final verification (same ten PHP/Blade paths as the
Pint check above, without --test): exit 0. Supplemental whole-file checks before
formatting: feature 12 / 259 exit 0; integration 2 / 108 exit 0.
Final `git diff --check` after this report append: exit 0.

### Isolation, preservation and remaining limitations

- Mongo vietfeed_test, Redis live-guarded DB15, SQLite :memory:, Cassandra
  vietfeed_test, Neo4j owned UUID cleanup only. No fixture ingestion, consumers or
  reemit against dev stores. No delegation, commit, stash, push, dependencies or
  container stop/restart. No cached config remains.
- SHA-256 comparison of 46 owner/control files from the start of this round:
  unchanged, exit 0. Dependency/compose diff check: unchanged, exit 0.
  HEAD remains the requested base.
- Container stats and inspect before/after: exit 0 each. Cassandra
  1.447 → 1.448 GiB / 1.5 GiB; Neo4j 648.8 → 649.5 MiB / 1 GiB;
  Mongo 168.2 → 169.8 MiB / 768 MiB; Redis 17.48 → 17.27 MiB / 256 MiB.
  All restart counts remain 0, OOMKilled=false; start timestamps unchanged
  from the earlier report. No stop condition occurred.
- No browser/manual, visual, mobile or accessibility acceptance was performed.
  The JS check is a Node VM DOM/timer/fetch harness, not a browser.
  Automated isolated HTTP recovery passed; the seven-step CLI/browser demo has
  not been executed end to end by this worker. No MANUAL_PASS claimed.
- Admin Story snapshot is capped at 50 newest, not paginated; DLQ titles use
  that same snapshot to avoid additional Story reads. Older/missing Story
  references retain their id/link with a neutral out-of-snapshot label.
  Pending envelopes still do not supply Story metadata. Inspection may create
  consumer-group/stream metadata at id 0, as described above and in the walkthrough.
- No fault was injected into actual store availability for the controller test;
  its expected/unexpected exception paths use failing adapter mocks. Real
  projection-store refusal/recovery remains covered by the HTTP integration.
- Vite still notes its existing Browserslist data age; no dependency update.
  Ready/ready polling behavior and all previously listed PR-B non-goals and PR-A
  carried follow-ups remain unchanged. Independent review and owner acceptance
  remain outstanding.

### Updated proposed atomic commit grouping

No commits created. Keep the original two groups, adding the permanent Node
harness to group 1. Exclude owner WIP and ignored public/build output.

1. **feat(storylens): render safe progressive evidence and bounded admin recovery**
   - app/Http/Controllers/StoryController.php
   - app/Http/Controllers/Admin/ProcessingController.php
   - app/StoryLens/StoryLens.php
   - app/StoryProcessing/StoryPipeline.php
   - routes/web.php
   - resources/views/stories/show.blade.php
   - resources/views/admin/processing.blade.php
   - resources/views/admin/partials/nav.blade.php
   - resources/js/app.js
   - tests/Feature/StoryPageTest.php
   - tests/Integration/StoryPageProjectionsTest.php
   - tests/StoryLensPolling.cjs
2. **docs(storylens): document demo preconditions and review-fix evidence**
   - docs/demo/storylens-progressive-rendering.md
   - docs/database/nosql-local.md (existing PR-B route note only)
   - .agent/tasks/storylens-tracer/GATE-REPORT-6B.md

## Review-fix round 2

STATUS: **READY_FOR_REVIEW**. All five requested P3 notes are addressed. This is
implementation verification; independent delta Review and owner acceptance are
separate. Branch remains `storylens/progressive-rendering`, HEAD remains
`659d5c0fc9108db237622ce945fcfe3698692c19`; all PR-B work remains uncommitted.

### Changes and proving tests

A. **Outage observability.** `ProcessingController::reportUnexpected` now writes
one Laravel warning per failed operation for Redis/Mongo connection exceptions,
including wrapped failures. The fixed message names `overview` or `retry`; the
only context is `exception` containing the matching connection exception class.
No exception object/message, request payload, host, credentials or connection
configuration is logged. Generic UI responses remain unchanged; unexpected
exceptions still go to `report()`.
`test_processing_reports_unexpected_exceptions_but_handles_missing_and_unavailable_stores`
uses `Log::spy()` and exact message/context/count expectations for both operations
with Redis, wrapped Redis and Mongo connection exceptions carrying SECRET,
private-host and password text. Exact full arguments exclude that text. It also
asserts unexpected LogicException reporting and no warning for that exception.

B. **Timeline datetime.** Only `readForPage` normalizes timeline `occurred_at` to
ISO 8601 using Carbon's `toIso8601String`; localized `occurred_at_display` retains
the same Vietnam timezone/format. The real-store integration test
`test_ready_sections_render_real_data_and_each_read_outage_preserves_http_evidence`
first asserts the driver's actual `YYYY-MM-DD HH:mm:ss.SSS+0000` shape, then asserts
the corresponding `YYYY-MM-DDTHH:mm:ss+00:00` value in both HTML datetime and JSON.
The existing feature test still proves localized display output.

C. **Malformed derived rows.** Each ready section's presentation shaping is now
inside its own Throwable boundary. Data is published only after shaping completes;
a failure leaves that section failed with empty data and no error field, retaining
its projected version and canonical evidence. Non-scalar Article IDs and invalid
timestamp input are rejected before presentation.
`test_malformed_timeline_rows_fail_only_that_section_and_preserve_evidence` binds
a fake timeline containing one valid row followed by each malformed row: an
unparseable timestamp, array Article ID, object Article ID and array timestamp.
Every case proves HTML 200/evidence/generic failed state, JSON empty failed section,
retained evidence/version, unchanged other section, and no error/host/secret text.
The valid prefix is discarded rather than partially rendered.

D. **Polling validation.** Required strings now cover rendered fields plus row
identity only: timeline event_id/occurred_at/occurred_at_display/article_title;
graph uuid/name. Existing safe, nonnegative integer checks remain for timeline
aggregate_version and graph article_count. Unused story_id/type/article_id/url
cannot block updates. `tests/StoryLensPolling.cjs` proves 12 accepted unused-field
cases (null, absent and non-string values), including null Source URL, and 90
malformed-row cases across both sections and all three states. Missing name,
missing identity, invalid rendered strings and negative/fractional/unsafe integers
still preserve both prior renders, back off and recover on a valid retry.

E. **Harness wiring.** `package.json` adds `test:storylens` invoking
`node tests/StoryLensPolling.cjs`. The demo guide lists `npm run test:storylens`
next to the two PHPUnit invocations and states the Node VM boundary. No dependency
or lockfile change. The script invocation passes with all behavior groups above.

### Test-first evidence and diagnostics

All commands below were separate invocations, with their real exit status.

| Command | Exit | Observed result |
|---|---:|---|
| `vendor/bin/phpunit tests/Feature/StoryPageTest.php --filter test_processing_reports_unexpected` — red | 2 | 1 test / 19 assertions; expected missing warning error |
| Same command — first implementation check | 2 | 1 / 19; spy count was checked before argument filtering |
| Same command — diagnostic follow-up | 2 | 1 / 29; repeated Log::spy reused the same spy across loop cases |
| Same command — green | 0 | 1 / 49; filter arguments before count assertion and restore original logger between cases |
| `vendor/bin/phpunit tests/Integration/StoryPageProjectionsTest.php --filter test_ready_sections` — red | 1 | 1 / 12; real raw Cassandra timestamp failed ISO HTML expectation |
| Same command — green | 0 | 1 / 66 |
| `vendor/bin/phpunit tests/Feature/StoryPageTest.php --filter test_malformed_timeline` — red | 1 | 1 / 8; malformed timestamp returned HTTP 500 |
| Same command — green | 0 | 1 / 67; four malformed-row cases |
| `node tests/StoryLensPolling.cjs` — red | 1 | unused timeline story_id blocked both updates |
| Same command — green | 0 | 12 accepted unused-field / 90 malformed-row cases plus existing polling groups |
| `vendor/bin/pint --test` with the ten PHP/Blade paths listed below — preliminary | 1 | Feature test import/object-cast formatting only |
| `vendor/bin/pint tests/Feature/StoryPageTest.php` | 0 | Formatting fixed; no other file formatted |

No extra test seam approval was needed: the requested HTTP/log, real-store HTTP
and polling harness seams were already specified in the task. The npm entrypoint
is wiring verified by invoking it, without a synthetic failing test.

### Final verification

Every row was a separate invocation. Store-mutating tests ran sequentially on
isolated stores; no tail/tee or pipeline masked exit status. Runs 1–3 below were
after the final PHP formatting. No product or test code changed afterward.

| Command | Exit | Counts/result |
|---|---:|---|
| `vendor/bin/phpunit tests/Feature/StoryPageTest.php` — run 1 | 0 | 13 tests / 343 assertions |
| `vendor/bin/phpunit tests/Feature/StoryPageTest.php` — run 2 | 0 | 13 tests / 343 assertions |
| `vendor/bin/phpunit tests/Feature/StoryPageTest.php` — run 3 | 0 | 13 tests / 343 assertions |
| `vendor/bin/phpunit tests/Integration/StoryPageProjectionsTest.php` — run 1 | 0 | 2 tests / 111 assertions |
| `vendor/bin/phpunit tests/Integration/StoryPageProjectionsTest.php` — run 2 | 0 | 2 tests / 111 assertions |
| `vendor/bin/phpunit tests/Integration/StoryPageProjectionsTest.php` — run 3 | 0 | 2 tests / 111 assertions |
| `npm run test:storylens` | 0 | 12 accepted unused-field cases, 90 rejected malformed-row cases, existing polling behavior groups PASS |
| `composer run test` | 0 | 108 tests / 1546 assertions |
| `npm run build` | 0 | Vite 8.0.13, 3 modules; ignored public/build output |
| `vendor/bin/pint --test app/Http/Controllers/StoryController.php app/Http/Controllers/Admin/ProcessingController.php app/StoryLens/StoryLens.php app/StoryProcessing/StoryPipeline.php routes/web.php tests/Feature/StoryPageTest.php tests/Integration/StoryPageProjectionsTest.php resources/views/stories/show.blade.php resources/views/admin/processing.blade.php resources/views/admin/partials/nav.blade.php` | 0 | All ten PHP/Blade files created/changed in PR-B PASS |
| `git diff --check` | 0 | PASS; repeated after report append |
| `./scripts/nosql-health.sh` | 0 | Mongo, Cassandra, Neo4j, Redis: 4/4 READY |

### Preservation and runtime safety

- Mongo/Cassandra `vietfeed_test`, Redis DB15 guarded against the live connection,
  SQLite `:memory:`, and UUID-scoped graph cleanup. No dev-store mutation, live RSS,
  dev consumer/server, dependency install, container stop/restart, commit, stash,
  push or delegation. No PR-A method/test edits.
- SHA-256 check exited 0: eight files snapshotted at round start remain identical:
  `.agent/tasks/infra-cassandra-spike/STATUS.md`,
  `.agent/tasks/mongo-foundation/STATUS.md`,
  `.agent/tasks/neo4j-foundation/STATUS.md`,
  `.agent/tasks/storylens-tracer/TASK.md`, `CLAUDE.md`,
  `database/seeders/SourceSeeder.php`, `app/StoryProcessing/StoryPipeline.php`,
  `package-lock.json`. Other unrelated WIP was not edited.
- `StoryLens::read` is byte-identical to HEAD; StoryPipeline (including inspect)
  is byte-identical to round start. No cached config remains.
- Container inspection before final repeated checks and after verification exited
  0: all restart counts 0, OOMKilled=false, original start timestamps unchanged.
  These snapshots bracket final verification, not the earlier targeted TDD runs.
  Cassandra 1.448 → 1.447 GiB / 1.5 GiB; Neo4j 649.1 → 649.1 MiB / 1 GiB;
  Mongo 170.1 → 170.4 MiB / 768 MiB; Redis 17.48 → 17.73 MiB / 256 MiB.

### Final proposed atomic commit grouping (exact paths)

No commits created. Exclude owner/control WIP, package-lock.json and ignored build
output. The shared files below contain only the existing PR-B hunks plus this
round's requested changes.

1. **feat(storylens): render safe progressive evidence and bounded admin recovery**
   - `app/Http/Controllers/StoryController.php`
   - `app/Http/Controllers/Admin/ProcessingController.php`
   - `app/StoryLens/StoryLens.php`
   - `app/StoryProcessing/StoryPipeline.php`
   - `routes/web.php`
   - `resources/views/stories/show.blade.php`
   - `resources/views/admin/processing.blade.php`
   - `resources/views/admin/partials/nav.blade.php`
   - `resources/js/app.js`
   - `tests/Feature/StoryPageTest.php`
   - `tests/Integration/StoryPageProjectionsTest.php`
   - `tests/StoryLensPolling.cjs`
   - `package.json`
2. **docs(storylens): document demo preconditions and review-fix evidence**
   - `docs/demo/storylens-progressive-rendering.md`
   - `docs/database/nosql-local.md` (existing PR-B addition only)
   - `.agent/tasks/storylens-tracer/GATE-REPORT-6B.md`

### Remaining limitations

- No real browser/manual/visual/mobile/accessibility acceptance; Node VM harness
  and isolated HTTP integration are automated evidence only. The seven-step
  CLI/browser walkthrough was not executed end to end. No MANUAL_PASS claimed.
- Controller outage classification/logging uses injected adapter exceptions,
  including the Mongo exception class; actual Redis/Mongo outages were not induced.
  Real Cassandra/Neo4j read refusal and recovery are covered by integration.
- ISO datetime follows existing Carbon seconds precision; localized display is
  unchanged. PR-A raw read output remains unchanged, including its driver timestamp.
- Existing bounded admin listings/metadata initialization, ready/ready polling stop
  and earlier PR-B non-goals remain unchanged. No separate PR-A integration files
  were rerun this round beyond coverage included in the requested Composer suite.
- Build retains its existing six-month-old Browserslist-data advisory; no packages
  were updated. Independent delta Review and owner acceptance remain outstanding.

## Independent review (gpt-6.1-terra, read-only, sha256-pinned file sets)

| Pass | Verdict | Findings |
|---|---|---|
| 1 | FAIL | P1 article `href` accepted any scheme from legacy Article documents; P2 polling accepted malformed rows and could stop for good; P2 admin controller swallowed every `Throwable` unlogged; P3 three unbounded Story scans in `overview()`; P3 raw UUID / ISO labels |
| 2 | PASS_WITH_NOTES | Findings 1, 2, 4, 5 resolved, 3 partial (store outages unlogged). New P3 notes: non-ISO `datetime` attribute, strict graph-row validation, malformed derived rows could 500, harness not wired |
| 3 | PASS_WITH_NOTES | A, B, D, E resolved; C partial (see ruling) |

The reviewer never ran PHPUnit, the build or a browser; its verdicts rest on source inspection plus `git diff --check`,
`php -l`, `node --check` and the Node polling harness.

### Control ruling on the remaining P3 (pass 3)

Finding: a derived row that has a valid timestamp and article id but lacks `event_id` / `aggregate_version` (timeline) or
`name` / `article_count` (graph) is still published as `ready`; the client would reject it, and Blade could fail on a
missing graph key.

Ruling: accepted as a documented limitation, not fixed in this PR. Both row shapes come from fixed projections owned by
this codebase: the Cassandra `SELECT` names its six columns and the Cypher `RETURN` aliases its four, so a row cannot
arrive without those keys; a null `name` needs a Source node written without a name, which `ContentCatalog` validation
rejects at ingest. Reaching the state requires someone to alter projection data by hand. If the projections gain other
writers, validate the full row contract at the `readForPage` boundary (the reviewer's suggested fix).

## Control final gate (Fable 5.2, run on the final tree, 2026-11-15)

Control read the full diff after each worker round and probed the real JSON section payload from the live test stores
against the client validator (accepted). Then, each as its own invocation with its true exit code:

| Command | Exit | Result |
|---|---|---|
| `vendor/bin/phpunit tests/Feature/StoryPageTest.php` x3 | 0 | 13 tests / 343 assertions each |
| `vendor/bin/phpunit tests/Integration/StoryPageProjectionsTest.php` x3 | 0 | 2 / 111 each |
| `vendor/bin/phpunit tests/Feature/StoryCatalogTest.php` | 0 | 8 / 193 |
| `vendor/bin/phpunit tests/Integration/StoryProjectionsTest.php` | 0 | 24 / 445 |
| `vendor/bin/phpunit tests/Feature/RedisStreamsTest.php` | 0 | 29 / 495 |
| `vendor/bin/phpunit tests/Feature/RedisQueueTest.php` | 0 | 3 / 42 |
| `vendor/bin/phpunit tests/Feature/RedisSessionTest.php` | 0 | 4 / 47 |
| `composer run test` | 0 | 108 / 1546 |
| Mongo integration (connection + auth double) | 0 | 3 / 19 |
| `vendor/bin/phpunit tests/Integration/Neo4jSpikeTest.php` | 0 | 4 / 35 |
| `vendor/bin/phpunit tests/Integration/CassandraSpikeTest.php` | 0 | 3 / 36 |
| `npm run test:storylens` | 0 | harness passed |
| `npm run build` | 0 | built (output ignored by git) |
| `vendor/bin/pint --test` on the PR-B PHP files | 0 | clean |
| `git diff --check` | 0 | clean |
| `composer validate --no-check-publish` | 0 | valid |
| `./scripts/nosql-health.sh` | 0 | 4/4 ready |

Containers: no restarts, no OOM kills; Cassandra 1.448 GiB of 1.5 GiB.

Not verified by anyone: a real browser run of the Story page, the polling script in a browser, the admin page's visual
layout, and the seven-step walkthrough end to end. Only the final tree was gated, not each commit.

Verdict: PASS_WITH_NOTES.

## Fix round 3 (browser finding)

STATUS: **READY_FOR_REVIEW** (implementation verification only). Base branch
`storylens/progressive-rendering`, HEAD `534ad09`; changes remain uncommitted for
Control. No independent Review or manual/browser acceptance claimed by this worker.

### Root cause and decision

ContentCatalog creates Mongo-native Articles with embedded `source_snapshot` and
`category_snapshot`, without SQL foreign keys. Admin queries include these
Articles, but templates assumed both SQL relations existed. The new fixture-slice
HTTP regression first reproduced the exact dashboard `name on null` 500. Once
that was fixed, it exposed the admin index's unsupported cross-store `withCount`
(SQL Query Expression passed into Mongo projection). Admin show also used the
same SQL aggregate mechanism and was corrected with the index.

**Public listing decision: preserve the existing exclusion.** No public listing
query was broadened; catalog-only Articles remain absent from home, public article
index, category pages, search, load-more and live-search. Their public slug detail
continues to return 404. StoryLens evidence remains the tracer's public reading
path. Bookmarks are an explicit user selection, not a general listing: their
existing query has no active-relation exclusion, and they can display catalog
Articles with safe snapshot labels.

The model now exposes `source_name` / `category_name`: SQL relation first,
snapshot second, neutral `—` last. `category_url` exists only for a real active
SQL Category with a slug; a snapshot slug alone never fabricates a category link.
Admin/dashboard and shared card/hero use these accessors. No ContentCatalog writes
changed, and no SQL Source/Category rows were created for the RSS fixture.

### Audit table

The queries below describe current source, including unchanged paths. “Catalog”
means an Article ingested without SQL Source/Category ids; adopting an existing
RSS Article with real SQL relations remains governed by those relations.

| Page / controller path | Receives catalog Articles? Query / reason | Action |
|---|---|---|
| Admin dashboard | Yes: `Article::with(...)->latest()->take(8)`; ranked lists also select Article ids without relation filters | Snapshot names in recent row, including category beside source; eager-load both relations. Counts already use per-store queries; no change to dashboard count logic. |
| Admin articles index | Yes: unfiltered `Article::with(['source', 'category'])` | Snapshot names, conditional real-category link; replace unsupported SQL `withCount` with native relation-count accessors. |
| Admin article filters | Yes for text/date/default; category `whereHas('category', ...slug...)` and source `where('source_id', ...)` remain SQL-identity filters | Preserve semantics; HTTP coverage for all six filters/sorts. Count sorts run in PHP before pagination, retaining published-time tie order. |
| Admin article show | Yes: Article id route binding | Snapshot labels and native counts instead of cross-store `loadCount`. Comments do not dereference Source/Category. |
| Admin article edit/update | Yes: Article id route binding | Empty-id option shows snapshot label, preventing silent first-SQL-option assignment. Nullable SQL ids accepted only when that id is absent and the corresponding snapshot exists; relational Articles still require valid SQL ids. Title/description editing preserves catalog exclusion. |
| Admin destroy / bulk-destroy | Yes: id binding / requested ids | Single delete already safe, covered unchanged. Bulk accepts UUIDs as well as prior numeric ids; rejects malformed nested/id entries. Test proves deletion, not just redirect. |
| `article-card` | Yes via bookmarks; reusable component can receive any Article | Name accessors, category badge becomes plain span without a real active category link. Preserve styling. |
| `article-hero` | Home caller excludes catalog; component itself accepts any Article | Defensive name accessors; direct Blade rendering tested with catalog and missing metadata. |
| `articles/show` (including related/sidebar Articles) | No: main and both related queries require `whereHas('category', active)` and `whereHas('source', active)` | Unchanged; fixture slug returns 404 before template. Source/category names, slugs and source URL dereferences are behind these filters. |
| `home/index` (featured, hero, feed, trending, community and breaking/news ticker) | No: every Article query in `HomeController::index` requires `whereIn('source_id', $activeSourceIds)` AND `whereIn('category_id', $activeCategoryIds)` | Unchanged. Null ids do not match active SQL ids. HTTP excludes fixture title. |
| Public `/articles` | No: `ArticleController::index` requires active category/source via `whereHas` | Unchanged; HTTP 200 without fixture title. |
| Search page | No: `SearchController::index` requires active category/source via `whereHas` | Unchanged; matching fixture keyword tested, 200 without title. |
| Bookmarks | Yes: user's bookmarks eager-load `article.source` / `article.category`, with no active-relation filters | Shared card fallback fixes display. Pin Bookmark's Article foreign key to actual `article_id`; otherwise Laravel infers `article__id` from Article's `_id`, silently dropping selected Articles. HTTP proves actual snapshot card and no category link. |
| Category page | No: `where('category_id', $category->id)` plus active-source `whereHas`; category itself resolved with `Category::active()` | Unchanged. Real unrelated active SQL category page tested as 200 without fixture title. |
| `/api/articles` JSON | No: `HomeController::loadMore` requires active category and source via `whereHas` | Unchanged; 200 with empty HTML for fixture-only content. Shared card is defensive as above. |
| `/api/live-search` JSON | No: `SearchController::liveSearch` requires active category/source via `whereHas` before selecting fields; category name is already nullsafe | Unchanged; matching fixture keyword yields 200 / empty array. |
| Layout USD/SJC ticker and `/api/ticker` | Never receives Articles: `TickerService::snapshot/fetch` uses metric cache/upstream data only | Unchanged; no Article Source/Category dereference. No upstream ticker fetch invoked by this worker. |
| Story page / sections JSON | Yes as shaped evidence arrays, not SQL-backed view relations | Existing StoryLens snapshot evidence shaping already safe; unchanged. StoryPage and StoryCatalog tests pass. |
| Other admin Article references (comments/reports) | May reference any Article, but render only nullsafe title/slug, never Article Source/Category | Unchanged; no metadata null dereference to fix. Source/category admin screens operate on SQL Source/Category rows; Article counts are aggregated, not dereferenced for metadata. |

### Files changed in this round

- `app/Models/Article.php`: snapshot labels, valid category URL and per-store count accessors (respect already supplied count attributes).
- `app/Models/Bookmark.php`: explicit Article foreign key.
- `app/Http/Controllers/Admin/ArticleController.php`: count queries/sorting, snapshot-only update validation, UUID bulk selection.
- `app/Http/Controllers/Admin/DashboardController.php`: eager-load recent Article categories.
- `resources/views/admin/dashboard.blade.php`.
- `resources/views/admin/articles/index.blade.php`.
- `resources/views/admin/articles/show.blade.php`.
- `resources/views/admin/articles/edit.blade.php`.
- `resources/views/components/article-card.blade.php`.
- `resources/views/components/article-hero.blade.php`.
- `tests/Feature/CatalogArticlePagesTest.php` (new).
- This report (append only).

### Test-first evidence

HTTP seams were explicitly requested; no additional approval was necessary.
All tests ingest the first frozen RSS fixture item through ContentCatalog, then
act as a real factory admin. SQL rows used in compatibility tests are unrelated
synthetic metadata, never fixture Source/Category rows.

| Separate invocation / stage | Exit | Counts and observation |
|---|---:|---|
| `vendor/bin/phpunit tests/Feature/CatalogArticlePagesTest.php` — initial red | 1 | 1 test / 8 assertions; dashboard `Attempt to read property "name" on null`. |
| Same command — partial implementation | 1 | 1 / 10; dashboard passed, admin index failed on cross-store Query Expression. |
| Same command — first green | 0 | 1 / 15; dashboard/index/show HTTP 200. |
| `vendor/bin/phpunit tests/Feature/CatalogArticlePagesTest.php --filter test_admin_can_edit` — red | 1 | 1 / 9; edit omitted snapshot names. |
| Same filtered command — green | 0 | 1 / 18; snapshot edit/update passes, public edited slug stays 404. |
| `vendor/bin/phpunit tests/Feature/CatalogArticlePagesTest.php` — expanded red | 1 | 7 / 95; UUID bulk selection ignored; bookmark Article relation did not resolve. |
| Same command — expanded green | 0 | 7 / 104. |
| Same command — compatibility and missing-metadata coverage | 0 | 9 / 138. |
| `vendor/bin/pint app/Http/Controllers/Admin/ArticleController.php app/Http/Controllers/Admin/DashboardController.php app/Models/Article.php app/Models/Bookmark.php tests/Feature/CatalogArticlePagesTest.php` | 0 | Formatting only; two files fixed. |

### Final verification

Every row below was a separate invocation with its true exit status; no tail,
tee, pipelines, concurrent mutating tests or delegated work. Product/test code
was unchanged after these runs.

| Command | Exit | Counts / result |
|---|---:|---|
| `vendor/bin/phpunit tests/Feature/CatalogArticlePagesTest.php` — run 1 | 0 | 9 tests / 138 assertions. |
| `vendor/bin/phpunit tests/Feature/CatalogArticlePagesTest.php` — run 2 | 0 | 9 / 138. |
| `vendor/bin/phpunit tests/Feature/CatalogArticlePagesTest.php` — run 3 | 0 | 9 / 138. |
| `vendor/bin/phpunit tests/Feature/StoryPageTest.php` | 0 | 13 / 343. |
| `vendor/bin/phpunit tests/Feature/StoryCatalogTest.php` | 0 | 8 / 193. |
| `vendor/bin/phpunit tests/Feature/ArticleMongoTest.php` | 0 | 6 / 44. |
| `composer run test` | 0 | 117 / 1684. |
| `vendor/bin/pint --test app/Http/Controllers/Admin/ArticleController.php app/Http/Controllers/Admin/DashboardController.php app/Models/Article.php app/Models/Bookmark.php tests/Feature/CatalogArticlePagesTest.php resources/views/admin/dashboard.blade.php resources/views/admin/articles/index.blade.php resources/views/admin/articles/show.blade.php resources/views/admin/articles/edit.blade.php resources/views/components/article-card.blade.php resources/views/components/article-hero.blade.php` | 0 | All 11 PHP/Blade paths pass. |
| `git diff --check` (after report append) | 0 | Clean. |
| `npm run build` | Not run | No JS or CSS changes; not required for this round. |

### Isolation, preservation and limits

- Tests used guarded Mongo `vietfeed_test`, Redis DB15 and SQLite `:memory:`.
  StoryPage config pins Cassandra reads to `vietfeed_test`; this round introduced
  no Cassandra or Neo4j writes and ran no projection consumers.
- No access to `127.0.0.1:8123`, `vietfeed_e2e_6b` or Redis DB14. No server or
  container start/stop/restart, commit, stash, push, delegation or dependency change.
- The three older STATUS files, CLAUDE.md, SourceSeeder.php, untracked packets,
  `.pi/`, `docs/agents/` and `pi-warden.md` were not edited. HEAD remains `534ad09`.
- HTTP/Blade tests are automated application-kernel tests, not a real browser run.
  No browser/manual, visual, mobile or accessibility acceptance; no MANUAL_PASS.
- Public catalog slug pages intentionally remain 404, including existing Article
  title links from admin/bookmark cards. Category links are omitted when no real
  active SQL Category exists. Admin SQL-identity source/category filters do not
  search snapshot metadata. Explicitly assigning valid SQL ids through admin can
  make an Article eligible for existing public queries; no automatic assignment
  occurs, and ordinary catalog edits preserve null ids.
- Native count accessors issue SQL count queries when no precomputed attribute is
  supplied. Admin count sorting scans the filtered Article candidates in PHP
  before pagination (like the existing cross-store ranking approach); it is not
  a scalable pre-aggregated ranking solution. Default admin ordering stays Mongo
  paginated. No counter storage/schema or public query behavior was redesigned.
- Deleting an Article retains the pre-existing deletion semantics; Story aggregate
  reconciliation / event retraction is outside this round.
- Independent delta Review and Control acceptance remain outstanding.

## Fix round 4

STATUS: **READY_FOR_REVIEW** (single-worker implementation verification).
Branch `storylens/progressive-rendering`, HEAD `534ad09`, rounds 3–4 uncommitted.
This section supersedes round 3's lazy-count implementation, count-sort audit
entries and scaling limitation. Accepted snapshot accessors, view fallbacks,
edit/update handling, UUID deletion and Bookmark foreign-key fix are retained.
No independent Review or manual acceptance is claimed.

### Explicit counts and bounded page hydration

- Removed all three lazy `*_count` accessors from Article. Reading an unloaded
  count now performs no query; the regression checks all three attributes and
  an empty SQL query log.
- Default ordering still uses Mongo `paginate(20)->withQueryString()`. A private
  controller helper attaches bookmarks/boosts counts with exactly one grouped
  SQL query per type, restricted to the displayed ids (at most 20). Empty pages
  skip these queries. Admin show uses the same helper for its one Article and
  all three count types, including unlocks; no cross-store `loadCount` remains.
- Count sorting projects the filtered Mongo ids in `published_at DESC` order
  using **`toBase()->pluck('id')`**. Eloquent's own `pluck('id')` constructs models
  for the Mongo id accessor; the hydration regression caught that during this
  round, so it is deliberately bypassed. The candidate query never loads full
  documents or Article models.
- SQL ranking groups the selected interaction table by `article_id`, orders by
  aggregate descending and restricts each IN list to at most 500 candidate ids.
  PHP merges these disjoint chunk results, orders ids by count descending then
  their original Mongo published-order position, and treats absent counts as
  zero. Only the requested 20 ids are hydrated with eager-loaded metadata.
  Pagination totals, page size, request path and query-string retention match
  the default paginator, including filtered totals and zero-count candidates.
- Both sorting paths share all filters. The new combined-filter test exposed
  existing BSON input mismatches: request source ids were strings while stored
  SQL relation ids are integers, and date bounds were strings while
  `published_at` is BSON datetime. The shared query now uses an integer source
  id and Carbon start/end-of-day bounds. SQL category identity filtering and
  text matching in title OR description are preserved.

### Query and correctness evidence

`CatalogArticlePagesTest` retains all nine round-3 tests and adds four tests:

1. Real SQL Source/Category metadata and interactions on 5 versus 25 Articles;
   query logs cover the complete admin index request including rendered Blade.
   Auth subscription metadata is warmed equally before both measurements to
   avoid a first-request relation-cache difference. Assertions require identical
   SQL/Mongo counts at both sizes for default, boosts and bookmarks sorts, and
   exactly `min(N, 20)` Article retrieval events (also detects id-only hydration).
2. Both count sorts across two pages with 25 matching Articles: a two-count
   older Article first, 18 tied one-count Articles in published order, then six
   zero-count Articles in published order. A higher-ranked nonmatching Article
   is excluded. Exact ids, totals and query-string links are asserted.
3. Combined text/category/source/from/to filters for both count sorts: one
   title match and one description match remain; wrong text, category, source,
   earlier date and later date candidates are excluded.
4. Plain count reads issue no SQL; admin show explicitly supplies the correct
   bookmarks, boosts and unlocks totals.

Measured whole-request counts, identical at N=5 and N=25:

| Sort | SQL statements | Mongo commands | Article models hydrated |
|---|---:|---:|---:|
| published_at | 7 | 2 | min(N, 20) |
| boosts | 8 | 2 | min(N, 20) |
| bookmarks | 8 | 2 | min(N, 20) |

The SQL totals include sanction middleware, two eager relation queries, two
filter-option queries and two explicit page-count queries; count sorts add one
ranking query for N <= 500. Additional active category filtering has its fixed
SQL lookup cost. These are fixture-specific whole-request totals, not a promise
that middleware, absent relations or empty pages always execute the same count.

### Defect attribution: main versus catalog compatibility

Local `main` is `93daf78`; its Article is already Mongo-backed with Laravel
UUIDv7 ids. Its admin Article controller and Bookmark relationship contain the
same offending code as pre-change HEAD `534ad09`.

A separate regression file, `tests/Feature/AdminArticleDefectsTest.php`, creates
only an ordinary RSS-style Article with valid SQL Source/Category ids and a
factory admin. **It never invokes ContentCatalog or writes snapshot metadata.**
For pre-change evidence, main's controller and Bookmark source were extracted
read-only into `/private/tmp/vietfeed-round4-baseline/`, given legacy class names
(and Bookmark's explicit original table name), and bound only into that isolated
test application's container. Production working-tree files and running servers
were never swapped. The otherwise identical regression tests ran against these
legacy definitions, then against the final definitions:

| Defect | Pre-change failing behavior | Why no catalog Article is needed | Fix classification |
|---|---|---|---|
| Admin index cross-store `withCount` | HTTP 500: `Object of class Illuminate\Database\Query\Expression could not be converted to string`, Mongo Query Builder projection, reached through legacy controller `paginate(20)` | Ordinary Article has both valid SQL relations; error is the SQL aggregate expression being sent into Mongo | **Exists on main**, explicit per-store counts and ranking replace it. Admin show's `loadCount` has the same unsupported cross-store mechanism. |
| Numeric-only bulk-destroy | UUID selection redirects without success message `Đã xóa 1 bài viết.`; numeric-only filtering discards the id | Every ordinary Article already receives a UUIDv7 from Article's creating hook, before StoryLens ingestion | **Exists on main**, accept valid UUIDs while retaining numeric compatibility. |
| Bookmark Article foreign key | Persisted ordinary bookmark resolves `article` to null instead of the selected UUID | `belongsTo(Article::class)` infers `article__id` from the related `_id` primary key, but the existing SQL column is `article_id` | **Exists on main**, explicitly pin `article_id`. |
| Null Source/Category display and edit assumptions | Round-3 initial HTTP dashboard reproduction: `Attempt to read property "name" on null`; edit form lacked snapshot metadata | Catalog ingestion intentionally omits SQL Source/Category ids and stores embedded snapshots; ordinary correctly related RSS Articles satisfy the old assumptions | **Catalog-Article compatibility**, snapshot name/category URL accessors, view fallbacks, eager category loading and snapshot-only nullable edit validation. |

The shared source/date normalization also corrects pre-existing Mongo filter
input typing in main's controller, rather than a catalog metadata defect. Before
normalization, isolated probes with ordinary typed relation ids/BSON dates returned
zero matches for `source=1`, `date_from=2026-11-01` and `date_to=2026-11-30` despite
qualifying Articles; text and category probes returned six matches. The final
combined-filter test returns exactly its two expected Articles. This issue was
previously hidden on the index by the earlier cross-store `withCount` 500.

The lazy accessor N+1 and hydrate-all sorting were **round-3 implementation
regressions**, not main's behavior and not inherent to catalog Articles. Round 4
removes both; they should not be attributed to StoryLens ingestion or claimed as
separate main defects.

### Verification (separate invocations, actual exits)

Final product/test code was unchanged throughout the final required runs below.
No pipelines or concurrent mutating suites were used.

| Command | Exit | Result |
|---|---:|---|
| `vendor/bin/phpunit /private/tmp/vietfeed-round4-baseline/PreChangeAdminArticleDefectsTest.php` | 1 | Expected red evidence: 3 tests / 16 assertions; all three main defects reproduced. |
| `vendor/bin/phpunit tests/Feature/AdminArticleDefectsTest.php` | 0 | 3 tests / 20 assertions; same ordinary-Article behaviors fixed. Also included in final full suite. |
| `vendor/bin/phpunit tests/Feature/CatalogArticlePagesTest.php` — final run 1 | 0 | 13 tests / 230 assertions. |
| `vendor/bin/phpunit tests/Feature/CatalogArticlePagesTest.php` — final run 2 | 0 | 13 / 230. |
| `vendor/bin/phpunit tests/Feature/CatalogArticlePagesTest.php` — final run 3 | 0 | 13 / 230. |
| `vendor/bin/phpunit tests/Feature/StoryPageTest.php` | 0 | 13 / 343. |
| `vendor/bin/phpunit tests/Feature/ArticleMongoTest.php` | 0 | 6 / 44. |
| `composer run test` | 0 | 124 / 1796. |
| `vendor/bin/pint --test app/Http/Controllers/Admin/ArticleController.php app/Http/Controllers/Admin/DashboardController.php app/Models/Article.php app/Models/Bookmark.php tests/Feature/CatalogArticlePagesTest.php tests/Feature/AdminArticleDefectsTest.php resources/views/admin/dashboard.blade.php resources/views/admin/articles/index.blade.php resources/views/admin/articles/show.blade.php resources/views/admin/articles/edit.blade.php resources/views/components/article-card.blade.php resources/views/components/article-hero.blade.php` | 0 | All 12 PHP/Blade paths pass. |
| `git diff --check` (after this append) | 0 | Clean. |

Development runs also returned exit 1 while building the regressions: the first
hydration listener was registered repeatedly, then the assertion exposed actual
Eloquent id-projection hydration; another comparison exposed first-request
subscription caching; and the combined-filter test exposed BSON typing. The
listener/cache setup and product projection/filter behavior were corrected before
the three final green runs. No failed development invocation is represented as a
passing verification.

### Final proposed commit grouping for rounds 3–4 (no commits created)

**(a) `fix: render catalog Articles with snapshot metadata`**

Exact paths:

- `app/Models/Article.php` — snapshot names/category URL only; no count accessors.
- `app/Http/Controllers/Admin/DashboardController.php` — eager category metadata.
- `resources/views/admin/dashboard.blade.php`
- `resources/views/admin/articles/index.blade.php`
- `resources/views/admin/articles/show.blade.php`
- `resources/views/admin/articles/edit.blade.php`
- `resources/views/components/article-card.blade.php`
- `resources/views/components/article-hero.blade.php`

**(b) `fix: count and manage Mongo Articles across SQL interactions`**

Exact paths:

- `app/Http/Controllers/Admin/ArticleController.php`
- `app/Models/Bookmark.php`
- `tests/Feature/AdminArticleDefectsTest.php`
- `tests/Feature/CatalogArticlePagesTest.php`
- `.agent/tasks/storylens-tracer/GATE-REPORT-6B.md`

Mixed-file placement is deliberate: the ArticleController contains catalog-only
update-validation hunks as well as pre-existing count/filter/bulk defects; the
CatalogArticlePagesTest file covers both catalog compatibility and interaction
regressions; this report documents both. Put these complete files in **(b)** so
the new integrated regressions arrive together with all required fixes and stay
green. Commit (a) installs metadata rendering/accessors; commit (b) completes
catalog editing and all admin interaction compatibility. Do not split the test
file into an intermediate failing test suite. The final combined tree was gated;
individual proposed commit snapshots have not been executed or committed.
Owner WIP paths outside this list do not belong in either commit.

### Complexity, isolation and limitations

- Default index has a constant number of queries per nonempty page, and at most
  20 hydrated Articles. Counts are never lazily fetched from the Article model.
- For N filtered candidate ids, count sorting stores O(N) ids/counts in PHP and
  sorts them in O(N log N). It issues ceil(N/500) ranking SQL queries, plus the
  constant page-count/eager-load/filter-option/middleware work. SQL aggregate
  cost depends on matching interaction rows; IN lists have at most 500 bindings.
  Mongo performs an id-projection find and a page find; large projected cursors
  can require additional `getMore` wire commands. Only page Articles are
  hydrated. This is the explicitly accepted ids-only design, not an O(1)
  global-ranking solution. No persistent counters or new dependencies added.
- Concurrent Article/interaction changes between ranking and page hydration
  are not a cross-store snapshot transaction; no redesign of that consistency
  boundary was attempted. Equal publication timestamps retain the underlying
  Mongo candidate order; no new tertiary order is imposed.
- Guarded SQLite `:memory:`, Mongo `vietfeed_test`, Redis DB15 only. StoryPage's
  existing test configuration pins Cassandra reads to `vietfeed_test`. No E2E
  URL/store access, no `127.0.0.1:8123`, no Redis DB14, no service/container or
  projection consumer operations. Main source inspection used only `git show`.
- Owner WIP and earlier round-3 work outside the requested count rework were
  preserved. No commit, stash, push, delegation or dependency changes. Requested
  worker/control labels were honored as role context; no backend model switch
  was performed or claimed by this worker.
- Kernel HTTP/Blade verification only; no browser/manual or independent Review
  acceptance. Public catalog listing exclusion and slug 404 behavior remain as
  recorded in round 3; deletion reconciliation remains outside scope.


## Fix round 5

STATUS: **READY_FOR_REVIEW** (implementation verification only).
Branch `storylens/progressive-rendering`, HEAD `534ad09`; rounds 3–5 remain
uncommitted. Control context: Fable 5.2 Medium. Single implementation worker;
requested model label is role context, not evidence of a backend model switch.
This appendix supersedes round 4's equal-timestamp ordering limitation.

### 1. Persist SQL relation identities as BSON integers

- Inspected `app/Services/Rss/FeedIngestionService.php`: `buildMetrics()` writes
  `$source->id` and `$source->category_id` directly. SQL identities are integers;
  Article has only a datetime cast, so Mongo preserves their numeric type.
  Admin source filtering explicitly compares an integer; category `whereHas`
  and public active Source/Category `whereHas` queries use SQL integer ids.
- Added `test_rss_ingestion_stores_integer_sql_relation_ids` in
  `tests/Feature/AdminArticleDefectsTest.php`. A mocked HTTP RSS response is
  imported by the actual FeedIngestionService; a raw Mongo collection read
  asserts both exact SQL ids and native integer types. No production documents
  were inspected or changed to establish this behavior.
- Admin Article update now casts each validated, non-null `source_id` and
  `category_id` to integer before saving. The controller is the narrow write
  boundary: no model cast, migration, backfill or existing-document read change.
  Snapshot null ids stay BSON null (`isset` skips them).
- Proving tests: ordinary Article
  `test_edit_form_persists_integer_relation_ids_and_preserves_listing_eligibility`
  and catalog Article
  `test_catalog_edit_form_assigns_integer_relations_and_becomes_publicly_eligible`.
  Both submit form-style string ids, inspect raw BSON values with strict integer
  equality, assert exact Article ids from admin source and category filters,
  and require the title in `/api/articles?page=1` HTML. That public listing
  requires both active SQL relations. The ordinary test assigns different valid
  SQL ids so Eloquent dirty checking cannot skip the persistence being tested.
  The existing snapshot-only edit test additionally asserts both raw ids null.
- Stored relation ids are BSON integers, matching RSS ingestion and query
  operands. Article `_id` remains its UUID string; no Article identity change.

### 2. Reject malformed filters before Carbon parsing

- `index()` validates `date_from` / `date_to` as `nullable|date` and `source`
  as `nullable|integer` before building or using filter values.
- Chosen behavior: normal Laravel web validation redirects back with session
  errors, consistent with `$request->validate()` in this controller and other
  admin updates (for example Admin UserController). JSON requests retain normal
  framework validation behavior. Invalid input is not silently ignored.
- `test_invalid_filters_redirect_back_with_validation_errors` covers both date
  parameters with garbage, impossible `2026-02-31`, and an array (six cases),
  plus garbage, fractional and array source values (three cases).
  `test_empty_nullable_filters_are_accepted` verifies blank filter inputs still
  return HTTP 200 with the ordinary Article.

### 3. Deterministic tie ordering across page boundaries

- Shared Mongo ordering is now `published_at DESC, _id ASC`. Count ranking uses
  that projected id order after `count DESC`; default Mongo pagination uses
  the same date/id ordering. The final id key is unique.
- `test_identical_counts_and_dates_have_stable_id_order_across_pages` creates
  21 explicit UUID Articles in reverse id order with identical publication
  timestamps and identical bookmark/boost counts. It asserts exact id order
  on pages 1 and 2 for default, boosts and bookmarks sorts. Reverse insertion
  prevents an accidental natural-order pass.

### 4. Exercise chunk merging and pages beyond the end

- Replaced the literal ranking chunk size with private `COUNT_CHUNK_SIZE = 500`;
  a protected typed `$countChunkSize` defaults to that constant. An anonymous
  test controller overrides only the size to 2; the real HTTP route, SQL
  aggregates, Mongo queries, merge, ranking and rendering still execute.
- `test_count_ranking_merges_three_small_chunks` creates only six Articles:
  published-order counts `[1, 0, 2, 1, 3, 0]` form three chunks. For both sorts,
  it asserts global Article order `[4, 2, 0, 3, 1, 5]` and displayed counts
  `[3, 2, 1, 1, 0, 0]`, proving the final chunk's winner outranks earlier chunks
  while equal and missing counts merge correctly. SQL logs require exactly
  three ranking aggregate queries, each with two id bindings; this prevents a
  false pass if the size seam is unused.
- The 21-Article tie regression also requests page 3 for all three sorts and
  asserts HTTP 200, an empty paginator collection, retained total 21 and no
  fixture title in the rendered table. No out-of-range-page exception occurs.

### Test-first evidence (separate invocations)

| Command / stage | Exit | Counts / observation |
|---|---:|---|
| `vendor/bin/phpunit tests/Feature/AdminArticleDefectsTest.php --filter test_edit_form` — initial unchanged-id probe | 0 | 1 test / 16 assertions; insufficient reproduction because unchanged ids were not dirty. Replaced with real reassignment before the fix. |
| `vendor/bin/phpunit tests/Feature/CatalogArticlePagesTest.php --filter test_catalog_edit_form` — red | 1 | 1 / 11; raw stored source id was string `"1"`, expected integer 1. |
| `vendor/bin/phpunit tests/Feature/AdminArticleDefectsTest.php --filter test_edit_form` — reassignment red | 1 | 1 / 8; raw stored source id was string `"2"`, expected integer 2. |
| `vendor/bin/phpunit tests/Feature/AdminArticleDefectsTest.php --filter test_edit_form` — green | 0 | 1 / 16. |
| `vendor/bin/phpunit tests/Feature/CatalogArticlePagesTest.php --filter 'test_catalog_edit_form|test_admin_can_edit'` — green | 0 | 2 / 38, including null preservation. |
| `vendor/bin/phpunit tests/Feature/AdminArticleDefectsTest.php --filter test_invalid_filters` — red | 1 | 9 / 45; malformed strings/arrays threw 500, other invalid values returned 200 instead of validation redirects. |
| `vendor/bin/phpunit tests/Feature/AdminArticleDefectsTest.php --filter 'test_invalid_filters|test_empty_nullable'` — green | 0 | 10 / 78. |
| `vendor/bin/phpunit tests/Feature/CatalogArticlePagesTest.php --filter test_identical_counts` — red | 1 | 1 / 10; equal-date default ordering did not match deterministic ids. |
| Same command — green | 0 | 1 / 37, all sorts and out-of-range pages. |
| `vendor/bin/phpunit tests/Feature/CatalogArticlePagesTest.php --filter test_count_ranking_merges` — red | 1 | 1 / 11; only one ranking query instead of three before the size seam was wired. |
| Same command — green | 0 | 1 / 21, both sorts and three real SQL chunks. |
| `vendor/bin/phpunit tests/Feature/AdminArticleDefectsTest.php --filter test_rss_ingestion` | 0 | 1 / 8, raw persisted integer type baseline. |
| `vendor/bin/pint app/Http/Controllers/Admin/ArticleController.php tests/Feature/AdminArticleDefectsTest.php tests/Feature/CatalogArticlePagesTest.php` — before final verification | 0 | Formatting passes. |

### Required final verification (separate invocations, true exits)

Final product/test code was unchanged throughout these runs. Tests ran serially;
no pipelines, output-masking commands or concurrent store-mutating suites.

| Command | Exit | Counts / result |
|---|---:|---|
| `vendor/bin/phpunit tests/Feature/CatalogArticlePagesTest.php` — run 1 | 0 | 16 tests / 308 assertions. |
| `vendor/bin/phpunit tests/Feature/CatalogArticlePagesTest.php` — run 2 | 0 | 16 / 308. |
| `vendor/bin/phpunit tests/Feature/CatalogArticlePagesTest.php` — run 3 | 0 | 16 / 308. |
| `vendor/bin/phpunit tests/Feature/AdminArticleDefectsTest.php` — run 1 | 0 | 15 / 122. |
| `vendor/bin/phpunit tests/Feature/AdminArticleDefectsTest.php` — run 2 | 0 | 15 / 122. |
| `vendor/bin/phpunit tests/Feature/AdminArticleDefectsTest.php` — run 3 | 0 | 15 / 122. |
| `vendor/bin/phpunit tests/Feature/ArticleMongoTest.php` | 0 | 6 / 44. |
| `vendor/bin/phpunit tests/Feature/StoryPageTest.php` | 0 | 13 / 343. |
| `composer run test` | 0 | 139 / 1976; includes config-clear step. |
| `vendor/bin/pint --test app/Http/Controllers/Admin/ArticleController.php app/Http/Controllers/Admin/DashboardController.php app/Models/Article.php app/Models/Bookmark.php tests/Feature/CatalogArticlePagesTest.php tests/Feature/AdminArticleDefectsTest.php resources/views/admin/dashboard.blade.php resources/views/admin/articles/index.blade.php resources/views/admin/articles/show.blade.php resources/views/admin/articles/edit.blade.php resources/views/components/article-card.blade.php resources/views/components/article-hero.blade.php` | 0 | All 12 PHP/Blade paths changed in rounds 3–5 pass. |
| `git diff --check` — after report append | 0 | Clean; rerun after recording this result also passed. |

### Final two-commit grouping for rounds 3–5 (no commits created)

**(a) `fix: render catalog Articles with snapshot metadata`**

Exact paths:

- `app/Models/Article.php`
- `app/Http/Controllers/Admin/DashboardController.php`
- `resources/views/admin/dashboard.blade.php`
- `resources/views/admin/articles/index.blade.php`
- `resources/views/admin/articles/show.blade.php`
- `resources/views/admin/articles/edit.blade.php`
- `resources/views/components/article-card.blade.php`
- `resources/views/components/article-hero.blade.php`

**(b) `fix: count and manage Mongo Articles across SQL interactions`**

Exact paths:

- `app/Http/Controllers/Admin/ArticleController.php`
- `app/Models/Bookmark.php`
- `tests/Feature/AdminArticleDefectsTest.php`
- `tests/Feature/CatalogArticlePagesTest.php`
- `.agent/tasks/storylens-tracer/GATE-REPORT-6B.md`

Keep complete mixed controller/test/report files in (b), as in round 4, so the
integrated tests arrive with all count/filter/update fixes. Commit (a) supplies
metadata rendering; (b) completes admin management and regression coverage.
Only the final combined tree was tested; individual proposed commits were not
created or independently executed. Owner WIP outside these exact paths is not
part of either proposed commit.

### Isolation, preservation and limitations

- Guarded stores: SQLite `:memory:`, Mongo `vietfeed_test`, Redis DB15. StoryPage
  tests pin Cassandra reads to `vietfeed_test`. The RSS type test fakes HTTP and
  prohibits stray requests. No E2E endpoint/store access, including
  `127.0.0.1:8123`, `vietfeed_e2e_6b` and Redis DB14. No server/container or
  projection-consumer operations.
- Round 5 edited only the admin Article controller, the two feature test files
  and this append-only report. All existing rounds 3–4 work and unrelated owner
  WIP were retained. No commit, stash, push, delegation or new dependency.
- Controller normalization applies on submitted updates; it does not repair
  documents already containing string foreign ids. No data migration requested.
- Existing count-ranking O(N) candidate-id/count memory and O(N log N) PHP sort
  remain; production uses ceil(N/500) ranking queries. Only page Articles hydrate.
  No new cross-store snapshot/transaction guarantee under concurrent writes.
- Automated kernel HTTP/Blade and isolated store verification only; no browser,
  manual acceptance, independent Review or Control acceptance is claimed.

## Fix round 6

Reviewer pass 5 P2: invalid GET filters must redirect to the clean admin Article
index regardless of the previous session URL or Referer.

- Replaced only `index()`'s automatic request validation with manual `Validator`
  using the same date/source rules. Failure explicitly returns
  `redirect()->route('admin.articles.index')->withErrors($validator)` with no
  query parameters. Valid-filter query construction is unchanged. This
  supersedes round 5's redirect-back behavior, including its JSON exception
  behavior: this index validation failure now always uses the explicit redirect.
- Existing `resources/views/layouts/app.blade.php` already renders
  `$errors->first()` in a visible `role="alert"`; no Blade change was needed.
- Added three HTTP regression cases: no previous URL; session previous URL equal
  to the invalid URL; and a Referer on another site. Each asserts exact Location
  equality to `route('admin.articles.index')`, all three filter validation errors,
  and a single follow-up GET returning 200 with the actual error text and Article
  title visible. The follow-up carries the session cookie as a browser does,
  preserving errors with the application's JSON session serialization.
- Renamed the existing nine-case invalid-filter test to describe the clean-index
  behavior. Existing valid/nullable filter and catalog coverage is preserved.

### Reproduction and verification

`vendor/bin/phpunit tests/Feature/AdminArticleDefectsTest.php --filter test_invalid_filters_always`
failed before the controller fix (exit 1, 3 tests / 16 assertions): actual targets
were the site root, the same invalid query URL, and the external Referer URL.
An earlier assertion attempt hit Laravel's redirect assertion error-context
formatter (exit 2); direct Location equality produced the precise red evidence.
Two intermediate full Admin runs failed only the new follow-up error-display
assertions while the test omitted or incorrectly encoded the session cookie.
Correcting the test's cookie handling made the unchanged layout display errors.

All final commands below ran as separate, serial invocations with true process
exit codes, after the final controller/test edits:

| Command | Exit | Result |
|---|---:|---|
| `vendor/bin/phpunit tests/Feature/AdminArticleDefectsTest.php` — run 1 | 0 | 18 tests / 168 assertions. |
| `vendor/bin/phpunit tests/Feature/AdminArticleDefectsTest.php` — run 2 | 0 | 18 / 168. |
| `vendor/bin/phpunit tests/Feature/AdminArticleDefectsTest.php` — run 3 | 0 | 18 / 168. |
| `vendor/bin/phpunit tests/Feature/CatalogArticlePagesTest.php` | 0 | 16 / 308. |
| `composer run test` | 0 | 142 / 2022; config-clear step also passed. |
| `vendor/bin/pint --test app/Http/Controllers/Admin/ArticleController.php tests/Feature/AdminArticleDefectsTest.php tests/Feature/CatalogArticlePagesTest.php` | 0 | Passed. |
| `git diff --check` — after this appendix | 0 | Clean. |

Round 6 changes only the admin Article controller, AdminArticleDefectsTest and
this report appendix. Branch `storylens/progressive-rendering`, HEAD `534ad09`;
rounds 3–5 and unrelated owner WIP retained. Guarded isolated test stores only:
SQLite `:memory:`, Mongo `vietfeed_test`, Redis DB15; StoryPage pins Cassandra
to `vietfeed_test`. No access to the E2E environment at `127.0.0.1:8123`, no
server/container changes, and no commit, stash, push or delegation. Automated
implementation verification only; independent Review and Control acceptance
remain separate. The requested worker/model label is role context and does not
prove a backend model switch.

## Browser acceptance and follow-up fixes (control summary, 2026-11-15)

Requested by the owner after PR #21 was opened. Run on a disposable environment (Mongo `vietfeed_e2e_6b`, Cassandra keyspace
`vietfeed_e2e_6b`, Redis DB14, scratch SQLite, production asset build, PHP built-in server on 127.0.0.1:8123).

- gpt-6.1-terra drove Scenario 1 through Playwright with the Story tab left open and never reloaded: timeline `pending` to
  `ready`, graph `failed` after five failing passes with timeline still `ready`, polling about 2 s while pending and about 10 s
  while failed with no overlap, admin retry of five dead letters (DLQ 9 to 4) leaving the Story `failed` until a consumer ran,
  graph `ready` after the restored consumer, polling stopped (no request for 76 s), same Story / version / Article ids before
  and after, 404 pages. No error internals in page or JSON. It saved no screenshots.
- Control viewed the Story page (desktop and 375 px), the admin processing page and the admin Article pages in a browser.
- Not checked: external article links actually opening, hidden/visible tab behaviour, light theme at both widths.

Found by this run:

1. After `storylens ingest`, `/admin/dashboard` returned 500 (catalog Articles have snapshots but no SQL Source/Category).
   Fixed in rounds 3-6 with snapshot fallbacks.
2. Three defects that exist on main independent of StoryLens, fixed in the same rounds and kept in a separate commit:
   cross-store `withCount` 500 on the admin Article index/show, numeric-only bulk-destroy ids, `Bookmark::article()` foreign key.
3. Reader-facing notes left open (P3): state labels still carry the English word in brackets; timeline rows show when an
   Article was attached to the Story, which a reader can mistake for event time.

Review of rounds 3-6 (gpt-6.1-terra, read-only): pass 4 FAIL (ids persisted as strings; invalid date filter 500), pass 5 FAIL
(validation redirect could loop), pass 6 PASS_WITH_NOTES.

Control gate on the round-5 tree: 21/21 commands exit 0 (`composer run test` 139 / 1976; CatalogArticlePagesTest 16 / 308 x3;
AdminArticleDefectsTest 15 / 122 x3; StoryPageTest 13 / 343; StoryPageProjectionsTest 2 / 111; StoryProjectionsTest 24 / 445;
RedisStreamsTest 29 / 495; Mongo / Neo4j / Cassandra integration; harness; build; Pint; diff check; health). After round 6:
AdminArticleDefectsTest 18 / 168, CatalogArticlePagesTest 16 / 308, `composer run test` 142 / 2022, Pint and diff check, all
exit 0; the integration files were not rerun after round 6 (the change is confined to the admin Article index filter).
Browser re-check on the E2E data: admin dashboard and Article index (default, both count sorts, date filter, page beyond the
last) 200; an invalid date filter lands on the clean index with a validation message.

Known limits of these fixes: count sorting reads the id of every Article matching the filter (O(N) memory, ceil(N/500)
ranking queries); Article documents already holding string ids are not backfilled; catalog Articles stay off public listing
pages and `/articles/{slug}` is 404 for them.
