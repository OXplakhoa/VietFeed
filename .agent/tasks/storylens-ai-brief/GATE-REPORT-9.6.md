# Gate 9.6 — StoryLens AI brief with human review

Branch `storylens/ai-brief`, base `de53c30`. Control owns the commit and merge decision.

## Decision log

| Area | Decision |
| --- | --- |
| Storage | MongoDB stores the Story's current brief state plus versioned outputs in `story_briefs`. The [feature map](../../../docs/architecture/database-feature-map.md#46-storylens-một-vertical-slice-dùng-cả-bốn-db-hợp-lý) assigns canonical Stories and current validated summaries to MongoDB. Review needs mutable decisions and version-scoped lookup. Cassandra continues to serve timeline history. Redis carries job payloads. |
| Concurrency | A conditional single-Story write checks the canonical version and brief revision. The revision taken on claim identifies the lease owner, and MongoDB `$$NOW` determines lease expiry. Brief transitions carry pending history updates until the other MongoDB documents catch up. Replaying those updates is idempotent. A stale generation is discarded at commit. |
| Review and publication | The Story version and accepted output must match on each public read. With review required, only a recorded editor decision can publish an AI brief. An explicitly enabled auto-publication uses its own label and still applies the full validator. Rejection preserves any existing accepted output for that version. Acceptance supersedes the previous accepted brief. |
| Provider boundary | Control confirmed `gemini-flash-lite-latest` as the default. It is a moving alias, configurable through `GEMINI_MODEL`. Gemini and OpenAI-compatible requests use JSON mode, header authentication and one provider request per job. Round 3 adds a single retry job for 5xx, timeout and network errors. The disabled driver records a disabled generation and the fake driver supports an offline demo. HTTP diagnostics use categories and validation reason codes. [Gemini models](https://ai.google.dev/gemini-api/docs/models), [Gemini structured outputs](https://ai.google.dev/gemini-api/docs/structured-output), [Chat Completions](https://developers.openai.com/api/reference/resources/chat). |
| Fallback | The public response builds an extract from current evidence. Generation persists one extractive snapshot per Story version when text is available. The extract uses the first sentences of recent articles, de-duplicates text and uses additional sentences or a headline when fewer than two points are available. A single distinct snippet yields one cited point. |
| Evaluation | Evaluation covers all Stories by default. The latest current-version AI attempt provides points and distinct cited/sent IDs. All recorded AI attempts supply the invalid-output rate. Disabled runs and stale results are excluded. Accept/reject totals count editors' decisions, including decisions on versions since superseded. |
| Admin | The existing admin group and `review-story-brief` ability protect the list and actions. The list and the CLI's bulk selection each cap at the 50 most recent Stories. |
| Planning | Control already added Gate 9.6 to `docs/planning/technical-gates.md`. |

## Files changed

**Added**

- `app/StoryLens/Brief/{BriefModel,BriefFailure,BriefPrompt,BriefEvidence,BriefValidator,BriefStore,Briefs}.php`
- `app/StoryLens/Brief/Models/{DisabledBriefModel,FakeBriefModel,GeminiBriefModel,HttpBriefModel,OpenAiBriefModel}.php`
- `app/Jobs/GenerateStoryBrief.php`, `config/ai.php`
- `database/migrations/2026_11_22_000001_create_mongodb_story_brief_indexes.php`
- `app/Http/Controllers/Admin/StoryBriefController.php`, `resources/views/admin/stories/{index,show}.blade.php`
- `tests/Unit/BriefValidatorTest.php`, `tests/Feature/{BriefModelTest,StoryBriefTest}.php`
- `.agent/tasks/storylens-ai-brief/GATE-REPORT-9.6.md`

**Modified**

- `app/{Console/Commands/StoryLens,Content/ContentCatalog,Providers/AppServiceProvider,StoryLens/StoryLens}.php`
- `routes/web.php`, `resources/views/{admin/partials/nav,stories/show}.blade.php`
- `.env.example`, `docs/database/nosql-local.md`
- `tests/TestCase.php`, `tests/Feature/{NoSqlRuntimeTest,StoryPageTest}.php`

### Existing test edits

- `tests/TestCase.php` adds `story_briefs` to the guarded MongoDB test collection cleanup.
- `tests/Feature/NoSqlRuntimeTest.php` accounts for the new index migration and asserts that it is present in the migration inventory.
- `tests/Feature/StoryPageTest.php` scopes the UUID exclusion assertion to timeline text, since evidence citation anchors legitimately include the canonical Article UUID.

## Verification

Pint ran in the workspace with only the PHP and Blade files changed by this gate.
Both test runs used a temporary copy of the branch files, the committed
`SourceSeeder.php`, and process-local test settings. The active `.env` was
excluded. Test settings used MongoDB `vietfeed_test` on port 27018, Redis DB15
and Cassandra `vietfeed_test`. Existing graph tests used test Story UUIDs.
Provider tests used `Http::fake()` and `Http::preventStrayRequests()`.

```bash
mapfile -d '' gate_php_files < <(git ls-files -z --modified --others --exclude-standard -- \
  'app/*.php' 'config/ai.php' 'database/migrations/*story_brief_indexes.php' \
  'routes/web.php' 'tests/*.php' 'resources/views/*.blade.php')
vendor/bin/pint "${gate_php_files[@]}" > "$gate_dir/pint-fix.log" 2>&1
vendor/bin/pint --test "${gate_php_files[@]}" > "$gate_dir/pint-test.log" 2>&1
php artisan test tests/Unit/BriefValidatorTest.php tests/Feature/BriefModelTest.php \
  tests/Feature/StoryBriefTest.php tests/Feature/StoryPageTest.php \
  tests/Feature/StoryCatalogTest.php tests/Feature/NoSqlRuntimeTest.php \
  > "$gate_dir/targeted.log" 2>&1
composer run test > "$gate_dir/full.log" 2>&1
```

PHPUnit executes `Tests\TestCase` through its subclasses. Each command's exit
status was captured before displaying its log tail.

| Command | Exit | Tests | Assertions | Failures | Errors | Skipped |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Pint formatter | 0 | — | — | — | — | — |
| Pint check | 0 | — | — | — | — | — |
| Targeted Artisan tests | 0 | 86 | 1,346 | 0 | 0 | 0 |
| Full Composer suite | 0 | 444 | 9,474 | 0 | 0 | 0 |

## Offline demo

Run the demo on a prepared demo instance with the brief index migration applied
and an admin account available. Set the generation process to `AI_PROVIDER=fake`
and keep `AI_BRIEF_REQUIRE_REVIEW=true`.

1. Run `php artisan storylens ingest` to load the frozen RSS fixture.
2. Run `AI_PROVIDER=fake php artisan storylens brief --all --sync`.
3. Open `/admin/stories`, choose a Story, inspect the candidate and its cited
   articles, then select **Duyệt**. **Từ chối** and **Tạo lại** exercise the other
   paths. With a Redis worker, **Tạo lại** sends the work to the queue.
4. Open the public Story and confirm the approved label and source links above
   the article list. Before approval, the page shows the extract from its sources.
5. Run `php artisan storylens brief-eval --story=<uuid> --json` to see evidence
   coverage and review counts.

## Follow-ups

- Cluster merge/split remains outside this gate.
- Gate 9.7 can point the review ability at a fixed RBAC permission.
- Gate 10 owns human annotation, factual quality scoring and demo runbooks.

## Stop conditions

None. The active `.env`, dev stores and owner work stayed untouched throughout
implementation and verification.

## Fix round 1

[Independent review](REVIEW-1.md) requested changes to the public fallback,
validator, worker recovery, and provider configuration. The table records each
finding and the regression coverage added in this round.

| Finding | Change | Regression test |
| --- | --- | --- |
| MAJOR-1 | The shared public brief presenter catches lookup and shape failures. HTML and polling JSON keep canonical evidence and show the extract with state `unavailable`. | Both routes return 200 for a throwing store, a malformed Story brief field, and malformed stored points. |
| MAJOR-2 | Explicit schemes and `www.` identify links. Bare domains require lowercase ASCII labels and a known suffix. | Data providers accept all seven specified Vietnamese abbreviations and reject known bare domains and explicit URLs. |
| MINOR-1 | The worker failure hook records `worker_failed` with version, request and lease guards. Review screens show an expired lease as failed and retryable. MongoDB server time still controls writes. | Current queued and generating requests can fail. Stale versions, replaced requests and redelivery during a live lease leave the active state intact. An admin can retry an expired lease. |
| MINOR-2 | Each asynchronous ingest and `--all` batch uses configurable Redis queue offsets. The default is six seconds, and generation still caps at 50 Stories. Synchronous demo commands run immediately. | Ingest jobs use a configured seven-second interval. Bulk jobs have offsets from 0 to 294 seconds, and a 51st Story is left out. |
| MINOR-3 | Gemini defaults to the moving alias `gemini-flash-lite-latest`, configurable through `GEMINI_MODEL`. The optional thinking level is sent as a REST enum, and the output budget is 16,384 tokens. | Faked requests cover the omitted field and all four values. [Gemini thinking documentation](https://ai.google.dev/gemini-api/docs/thinking) |
| MINOR-4 | OpenAI-compatible HTTP endpoints are limited to loopback hosts. Remote endpoints require HTTPS. | Mock requests cover HTTPS, all three permitted loopback hosts, and disabled remote or deceptive HTTP URLs. |
| MINOR-5 and NIT-1 | Validation rejects control and bidi characters after trimming the edges, Markdown emphasis and leading heading/list markers. The prompt asks for plain text. | Table-driven cases cover interior controls, all listed bidi characters, and the Markdown markers. |
| NIT-2 | Opening the admin detail replays pending history writes before querying the candidate and version history. | A simulated interrupted approval becomes visible on the detail page and the public page after the admin opens it. |
| NIT-3 | The 50-Story admin index fetches accepted publication pointers in one batch and checks their Story IDs and versions. | The index uses one batch lookup and ignores a pointer copied to a different Story. |
| NIT-4 | Evaluation selects the newest 50 Stories by default, with `--limit=1..100` and the existing `--story` selector. | A 51-Story fixture exercises the default and an explicit limit. |
| NIT-5 | Transport exceptions use `timeout` or `network`. Only `invalid_output` contributes to the evaluation numerator. | Fakes exercise DNS, timeout and general transport exceptions. A mixed three-attempt history produces an invalid rate of one third. |

### Fix round 1 verification

The commands below ran sequentially from `/home/dolakhoa/code/VietFeed`. The
test environment used MongoDB `vietfeed_test` on port 27018, Redis DB15 and
Cassandra `vietfeed_test`. Temporary `.env.testing` and `.env.local` files
contained only a comment and kept Laravel and the existing local-environment
guard tests off the active `.env`. The verification wrapper removed both files.
Control's forced PHPUnit AI settings remained in place. All provider fixtures
used Laravel HTTP fakes.

```bash
vendor/bin/pint --test "${gate_php_files[@]}" > "$gate_dir/pint-test.log" 2>&1
php artisan test tests/Unit/BriefValidatorTest.php tests/Feature/BriefModelTest.php \
  tests/Feature/StoryBriefTest.php tests/Feature/StoryPageTest.php \
  tests/Feature/StoryCatalogTest.php tests/Feature/NoSqlRuntimeTest.php \
  > "$gate_dir/targeted.log" 2>&1
composer run test > "$gate_dir/full.log" 2>&1
```

Pint's file array contained the 18 PHP and Blade files changed by this review.
The wrapper captured each exit status before printing its log tail.

| Command | Exit | Tests | Assertions | Failures | Errors | Skipped |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Pint check | 0 | — | — | — | — | — |
| Targeted Artisan tests | 0 | 151 | 1,645 | 0 | 0 | 0 |
| Full Composer suite | 0 | 509 | 9,773 | 0 | 0 | 0 |

## Fix round 2

[Independent review](REVIEW-2.md) identified URL detection gaps, per-article
dispatch delays, and a publication label that could disagree with the public
brief.

| Finding | Change | Regression test |
| --- | --- | --- |
| MAJOR-1 | Detect domain paths independently of the suffix list, match common generic and country suffixes case-insensitively, and recognize dotted IPv4 addresses with or without a port or path. | Table-driven cases cover all requested URL and IPv4 forms and preserve Vietnamese abbreviations, percentages, decimals, and version-like text. |
| MINOR-1 | Collect the final committed version of each Story in an ingest call. Queue it once, with spacing between Stories in their first attachment order. Projection events still publish per article. Committed Story versions still get a brief dispatch if projection publishing fails partway through the call. | Fifteen articles into one Story queue version 15 with zero delay. Seven interleaved articles across three Stories queue versions 3, 2, and 2 at 0, 6, and 12 seconds. Re-ingesting the same articles queues nothing. |
| MINOR-2 | Record Control's confirmed default `gemini-flash-lite-latest` in the decision log and local docs. The alias can move and `GEMINI_MODEL` can select a specific model. | Cross-checked the decision log, local docs, `.env.example`, and `config/ai.php`. Provider request shape is covered with HTTP fakes. |
| NIT-1 | Log brief lookup and shape errors at debug with the exception type. Public responses use the fallback even if debug logging fails. | Repeated HTML and JSON requests use the fallback and emit debug diagnostics without warnings. |
| NIT-2 | Apply the same output, review and citation checks to the public page and admin index. The index still batches accepted outputs and now batches canonical evidence. | Index and public JSON agree for a valid AI brief, after a cited Article leaves the Story, and after the remaining Article is missing. The existing batch test also verifies that another Story's pointer is ignored. |
| NIT-3 | Ask for one line per point in the plain-text prompt. The validator also rejects Unicode line and paragraph separators within point text. | The faked Gemini request asserts the single-line instruction. Validator cases cover Markdown markers and line breaks. |

The URL validator recognizes literal hosts and IPs. Obfuscated separators such as
`[.]` and `。` remain outside this check.

### Fix round 2 verification

The commands ran sequentially in the real repo, `/home/dolakhoa/code/VietFeed`,
using MongoDB `vietfeed_test`, Redis DB15, and Cassandra `vietfeed_test`.
Process-local settings and temporary, comment-only `.env.testing` and
`.env.local` files kept Laravel off the active `.env`. The wrapper removed the
temporary files. The forced PHPUnit AI settings stayed in place.

```bash
vendor/bin/pint --test "${gate_php_files[@]}" > "$gate_dir/pint-test.log" 2>&1
php artisan test tests/Unit/BriefValidatorTest.php tests/Feature/BriefModelTest.php \
  tests/Feature/StoryBriefTest.php tests/Feature/StoryPageTest.php \
  tests/Feature/StoryCatalogTest.php tests/Feature/NoSqlRuntimeTest.php \
  > "$gate_dir/targeted.log" 2>&1
composer run test > "$gate_dir/full.log" 2>&1
```

Pint received the 10 PHP files changed in this round. The wrapper captured the
exit status of each command before showing its log tail.

| Command | Exit | Tests | Assertions | Failures | Errors | Skipped |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Pint check | 0 | — | — | — | — | — |
| Targeted Artisan tests | 0 | 178 | 1,747 | 0 | 0 | 0 |
| Full Composer suite (rerun) | 0 | 536 | 9,875 | 0 | 0 | 0 |

The first full run exited 1 with 536 tests, 9,865 assertions, one failure,
zero errors and zero skips. The existing
`CommentsMongoTest::test_string_comment_id_flows_from_report_to_case_events_and_the_cassandra_comment_ref_column`
returned all three expected Cassandra events with `REPORTED` last. The
verification sequence passed on rerun with the same source files and isolated
settings.

## Fix round 3

[Independent review](REVIEW-3.md) identified two remaining validator false
positives. Control also requested one automatic retry for transient provider
failures after a live Gemini 503 recovered on manual retry.

| Finding | Change | Regression test |
| --- | --- | --- |
| R3-MINOR-1 | The slash rule for unlisted suffixes requires a lowercase letter in the final label. Known domain suffixes still match case-insensitively. | The four requested Vietnamese slash constructions pass. All prior URL cases, including paths with an unlisted lowercase suffix, still fail validation. |
| R3-NIT-1 | A dotted IPv4-like number followed by a currency word is treated as numeric prose. Explicit schemes, paths and ports still identify URLs. | Both `đồng` and `VNĐ` amounts pass. Bare addresses and addresses with a path or port still fail. |
| Transient provider retry | HTTP 5xx, timeout and network errors get one retry after 30 seconds by default, or after 3 seconds in-process with `--sync`. The first attempt is committed to history before the second is scheduled. Each provider attempt acquires its own lease, and Story version, request ID and attempt number guard the transition. | HTTP fakes cover 503 then success, 503 twice, timeout and network recovery, and no retry on 429, 402, other 4xx, or invalid output. Tests also cover version and request replacement, duplicate or incomplete deliveries, retry dispatch failure, real Redis delay and synchronous sleep. |

### Follow-ups

- Make the Cassandra event-order assertion in `CommentsMongoTest` deterministic
  when the report and sanction timestamps tie.
- Profile admin index evidence loads for large Stories. Keep the public citation
  checks while projecting only the data needed for the index.
- Add cluster merge and split in the separate clustering workflow.

### Fix round 3 verification

Verification ran sequentially in the real repo using MongoDB `vietfeed_test`,
Redis DB15 and Cassandra `vietfeed_test`. The wrapper supplied process-local
test settings and removed the temporary, comment-only `.env.testing` and
`.env.local` files. The active `.env` and PHPUnit's forced AI settings were
untouched. Provider requests used Laravel HTTP fakes.

```bash
vendor/bin/pint --test "${gate_php_files[@]}" > "$gate_dir/pint-test.log" 2>&1
php artisan test tests/Unit/BriefValidatorTest.php tests/Feature/BriefModelTest.php \
  tests/Feature/StoryBriefTest.php tests/Feature/StoryPageTest.php \
  tests/Feature/StoryCatalogTest.php tests/Feature/NoSqlRuntimeTest.php \
  > "$gate_dir/targeted.log" 2>&1
composer run test > "$gate_dir/full.log" 2>&1
```

Pint received the seven PHP files changed in this round. The wrapper captured
exit statuses before printing the log tails.

| Command | Exit | Tests | Assertions | Failures | Errors | Skipped |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Pint check | 0 | — | — | — | — | — |
| Targeted Artisan tests | 0 | 201 | 2,001 | 0 | 0 | 0 |
| Full Composer suite | 0 | 559 | 10,129 | 0 | 0 | 0 |

## Control fix after REVIEW-4 (APPROVE)

- R4-MINOR-1: `GenerateStoryBrief::retry()` now wraps `dispatchFailed` in its own try/catch, so a simultaneous Redis dispatch failure
  and Mongo write failure is logged (exception type only) instead of escaping; the admin "Tạo lại" action still recovers the Story.
- Known limitations (R4-NIT-1, accepted because AI briefs need human acceptance before publication by default): an all-caps unlisted
  domain with a path such as `EXAMPLE.FOO/x`, and an IPv4 address followed by a currency word or "đồng thời", pass the URL check.
- Demo note (R4-NIT-2): `storylens brief --all --sync` retries in-process after `AI_SYNC_RETRY_DELAY_SECONDS`; during a full provider
  outage it can block the CLI for minutes. For the demo prefer `--story=<uuid> --sync`, or queued generation with a worker.
