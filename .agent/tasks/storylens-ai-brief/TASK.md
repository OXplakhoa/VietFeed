# Gate 9.6 — StoryLens AI brief with human review

Canonical sources: `docs/specs/vietfeed-nosql-storylens.md` §12.1 item 9 ("Generate AI output separately with timeout, structured
validation, evidence IDs, and extractive fallback"), §12.2 items 3–5 ("Allow human merge/split/review", "Store evidence and output
versions", "Never publish unsupported claims or partial AI output as complete"), §7 stories 23, 29, 30;
`docs/architecture/database-feature-map.md` §4.6 (MongoDB holds "canonical Article + current Story + validated current summary";
"First render đọc MongoDB và không chờ AI ... AI brief chạy riêng, có validator, timeout và extractive fallback").

One gate, one branch, one PR: branch `storylens/ai-brief`, base `main` `de53c30`. **Control stops before merging (owner approval).**

## Recon facts (control, 2026-11-22)

- `App\StoryLens\StoryLens::read()/readForPage()` builds the Story page contract from MongoDB (`stories` documents: `title`,
  `story_key`, `version`, `article_ids`, `events`, `processing.{timeline,graph}`) plus evidence Articles (`id`, `title`,
  `original_url`, `published_at`, `description` → `excerpt`, `source_snapshot`, `category_snapshot`). `StoryController::show` renders
  `resources/views/stories/show.blade.php`; `sections` returns the same JSON for polling (`no-store`).
- Stories exist only when articles carry a `story_key` (`App\Content\ContentCatalog::ingest`, `php artisan storylens ingest` with
  `tests/Fixtures/storylens-rss-v1.json`). Dev has 0 Stories today; the demo creates them from the fixture.
- `App\StoryProcessing\StoryPipeline` shows the house patterns: conditional single-document Mongo updates with version guards
  (`$expr`, no transactions: Mongo is standalone), Redis Streams via `StreamConsumer`, `assertSafeTestStores()`.
- Admin area: `Route::middleware(['auth','admin'])->prefix('admin')`, `AdminMiddleware` checks `User::isAdmin()`. RBAC with fixed
  permissions is a LATER gate (owner 2026-11-22); this gate uses the existing admin middleware and keeps the authorization check in
  one place (a Gate/policy ability such as `review-story-brief`) so RBAC can re-point it.
- No AI client exists yet. No `GEMINI_API_KEY` in dev `.env` today (owner will add one later); the AI Studio key the owner tested
  returns HTTP 402 "prepayment credits are depleted". Laravel's `Http` client (Guzzle) is already installed.

## Owner decisions (2026-11-22)

- Primary provider Gemini (spec). The owner wants free-tier options, so the client is a small provider interface with three
  drivers: `gemini` (Generative Language REST `generateContent`), `openai` (any OpenAI-compatible Chat Completions endpoint: base URL
  + key + model, e.g. OpenRouter free models, Groq, OpenCode Zen), and `fake` (tests/demo). `AI_PROVIDER=none` (default when no key)
  disables generation. No new Composer dependencies (use `Http`).
- Publication rule: a public Story page shows an AI brief ONLY if a human reviewer accepted it AND it was generated for the
  Story's current `version`; otherwise it shows the deterministic extractive brief. Config `AI_BRIEF_REQUIRE_REVIEW=true` (default;
  `false` only allowed to auto-publish outputs that passed validation, still never partial ones).

## Required outcomes

1. **Provider seam** `App\StoryLens\Brief\BriefModel` (interface) + drivers above, chosen by `config/ai.php` (`AI_PROVIDER`,
   `GEMINI_API_KEY`, `GEMINI_MODEL` default a current Flash model, `OPENAI_COMPAT_BASE_URL`, `OPENAI_COMPAT_API_KEY`,
   `OPENAI_COMPAT_MODEL`, `AI_TIMEOUT_SECONDS` default 10, connect timeout, `AI_MAX_EVIDENCE` default 8). Keys are never logged,
   never shown in UI/exceptions/CLI output; HTTP errors are reduced to a category (`timeout`, `http_4xx`, `http_429`, `http_402`,
   `http_5xx`, `invalid_output`, `disabled`). One attempt per job run; no retry storm (429/402 → failed with category).
2. **Prompt + structured output**: input = Story title + up to N evidence items `{id, source, published_at, title, excerpt}`
   (strip HTML, bound lengths). Ask for JSON only, Vietnamese, 2–5 points, each `{text, evidence_ids[]}`; use the provider's JSON
   mode when available (Gemini `responseMimeType: application/json` (+ schema if simple), OpenAI `response_format`).
3. **Validator** (pure class, heavily unit-tested): valid JSON object; 2–5 points; each text 20–400 chars after trim, no URLs/HTML/
   markdown links; each point has ≥1 evidence id and EVERY id belongs to the evidence actually sent; no duplicate points; total length
   bound. Any violation rejects the WHOLE output (`invalid_output`, with a reason code, not the raw text in logs). Never store or show
   a partial output as complete.
4. **Extractive fallback** (deterministic, no network): 2–4 points built from evidence excerpts (first sentence(s), de-duplicated,
   newest/most-sourced first), each citing its article id; labelled in the UI as trích từ nguồn. Always available, also when the
   Story has 1 article; 0 articles → no brief section text beyond an empty state.
5. **Storage (MongoDB only)**: current state embedded on the Story document `brief` = `{state: none|queued|generating|ready|failed,
   story_version, candidate_id, published_id, error_category, updated_at}` updated with conditional single-document writes
   (version/lease guarded; a stale generation for an older Story version must not overwrite a newer one). Output versions in a new
   collection `story_briefs` (UUIDv7 `_id`, `story_id`, `story_version`, `kind: ai|extractive`, `provider`, `model`, `points`,
   `evidence_ids`, `prompt_version`, `status: candidate|accepted|rejected|superseded`, `reviewed_by`, `reviewed_at`,
   `review_note`, timestamps) with an index migration (`*_create_mongodb_story_brief_indexes`). Justify Mongo vs Cassandra in the
   gate report (feature map puts the validated current summary in MongoDB). No fifth database; Redis only as queue.
6. **Async generation**: queued job `GenerateStoryBrief(storyId, storyVersion)` on the existing Redis queue; a lease on the Story
   document (`$$NOW`-based, like existing locks) prevents duplicate concurrent generation for the same version. Triggers: (a) after
   `ContentCatalog::ingest` advances a Story version (dispatch, never inline; ingest must not fail if dispatch fails), (b)
   `php artisan storylens brief --story=<uuid>` (and `--all` bounded, `--sync` for demo), (c) admin "Tạo lại" (retry) button.
   With `AI_PROVIDER=none` the job records state without calling anything.
7. **Page never blocked**: `StoryLens::read/readForPage` add a `brief` block read from Mongo only: `{kind: ai|extractive|none,
   points[{text, evidence:[{id,title}]}], state, label}`; no model call, no HTTP, on page/JSON requests (test with
   `Http::preventStrayRequests()` and a fake that would throw). Show it above the evidence list in `stories/show.blade.php`, with
   each point linking to the cited evidence anchor (`#article-<id>`); AI briefs labelled "Tóm tắt AI, đã được biên tập viên duyệt";
   polling JSON includes it.
8. **Human review (story 23 subset)**: `/admin/stories` (recent 50 Stories: title, version, article count, brief state, published
   kind) and `/admin/stories/{story}` (evidence list, current candidate points with cited evidence titles, validation result, error
   category, history of versions) with POST actions accept / reject (optional note) / retry, CSRF, admin-only via the single ability,
   conditional writes so accepting a candidate whose `story_version` is no longer current is refused with a clear message.
   Accepting marks the previous accepted brief `superseded`. Link from the existing admin navigation. Merge/split clusters are NOT in
   scope (record as follow-up).
9. **Evaluation hook (story 29, minimal)**: `php artisan storylens brief-eval [--story=]` prints, per Story, points count, evidence
   coverage (cited ids / evidence sent), invalid-output rate from `story_briefs`, accepted/rejected counts. JSON output option. Human
   annotation itself is Gate 10.
10. **Tests** on isolated stores only (Mongo `vietfeed_test`, Redis DB15 guard, Cassandra `vietfeed_test`), no real network
    (`Http::fake` + `preventStrayRequests`): each driver request shape (key in header, never in URL for Gemini → use
    `x-goog-api-key`), timeout/429/402/5xx categories, invalid JSON / unknown evidence id / too many points / URL in text → whole
    output rejected, extractive determinism, stale-version guard (Story grows after generation → public page falls back to
    extractive; accept refused), lease prevents double generation, page renders with provider down/disabled and makes zero HTTP calls,
    admin auth (guest → login, user → 403, admin OK), accept/reject/retry flows and supersede, `brief-eval` output, ingest dispatches
    and survives dispatch failure, keys never appear in logs/exception messages (assert on a captured log). Full `composer run test`
    green; list any existing test edits with reasons.
11. **Docs**: `docs/database/nosql-local.md` (brief storage, states, commands, provider env vars incl. free options and their data
    caveats), `.env.example` (all AI vars, empty keys, `AI_PROVIDER=none`), demo note in the gate report (fixture ingest → `storylens
    brief --all --sync` with `fake` or a real provider → review → page), `GATE-REPORT-9.6.md` in this folder with the decision log
    and the exact verification commands + exit codes. Update `docs/planning/technical-gates.md` only if control has not already.

## Hard rules / isolation

Never touch dev data (Mongo `vietfeed`, Redis DB0/1/2, Cassandra `vietfeed`); no FLUSHALL, no migrate/seed/new commands against dev,
no `migrate:fresh`/`db:wipe`, no container starts/restarts, no real AI or RSS network calls in tests or during implementation (do not
call Gemini/OpenRouter even if a key appears). Never read, print or edit the active `.env`. Sequential suites. Never edit existing
migrations. No new Composer/npm dependencies. Owner WIP untouched: `CLAUDE.md`, `database/seeders/SourceSeeder.php`,
`.agent/tasks/{infra-cassandra-spike,mongo-foundation,neo4j-foundation}/*`, `.agent/handoff/`, `.agent/migration-pc/`,
`.agent/control/`, `.agent/tasks/claude-code-handoff/`, `.pi/`, `docs/agents/`, `pi-warden.md`. Do not commit; control commits.

## Non-goals

Merge/split of clusters, RBAC permissions (next gate), SSE/WebSocket, clustering changes, Gate 10 annotation/runbooks, UI redesign
beyond the brief block and the two admin pages.

## Stop conditions

Product change beyond the decisions above; owner WIP edit; need to change the active `.env` (report the exact lines instead);
failure not fixable in scope; container restart or OOM; any need for a new dependency.
