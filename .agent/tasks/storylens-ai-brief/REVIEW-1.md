# Gate 9.6 — REVIEW-1 (independent reviewer)

Scope: `git diff main` (owner WIP excluded) plus the untracked Gate 9.6 files listed in the brief. I read TASK.md and
GATE-REPORT-9.6.md, every new class, controller, view, migration and test, and the modified ingest/read/route/provider
files. Reviewer verification: `php artisan test --filter='Brief|StoryPage'` → passed (71 tests, 943 assertions,
isolated stores). I also ran a scratch script against `BriefValidator` to check for false positives (results below).
I made no edits apart from this report.

## Summary

The core invariants hold. Publication requires `status=accepted`, `kind=ai`, `error_category=null`, `story_version ==
current Story.version`, `published_id` on the Story and, when review is required, `reviewed_by != null`
(`Briefs::published`). Every evidence id has to resolve against current evidence, or the page falls back to the
extract (`Briefs::cite`). All brief state transitions go through one conditional `findOneAndUpdate` per Story. The
filter covers `_id`, `version`, `brief.revision` and the absence of pending `history_updates`, plus a per-operation
guard. A pipeline `$replaceWith/$mergeObjects($$ROOT)` makes the update atomic against concurrent `$push/$inc` from
ingest and `processing.*` writes from consumers. The lease uses server time on both sides (`$dateAdd $$NOW` on claim,
`$$NOW` compare on finish; Mongo 8.0, so `$dateAdd` is available).

I traced these stale paths and found them safe:
- Generation for v(n) finishing after ingest moved the Story to v(n+1): the version and revision mismatch, so the
  write is discarded.
- Accepting a candidate after the Story grew: the version check fails at read time and again in the CAS filter.
- The Story growing between the accept check and the write: the CAS filter on `version` refuses the write.
- Replay of `history_updates`: idempotent (`$setOnInsert`, status updates conditioned on the old status). If replay
  is interrupted, the public read fails closed to the extract.

Secrets are handled well: header auth for both drivers, `#[\SensitiveParameter]`, every transport `Throwable` reduced
to a category, a job payload of only `{storyId, storyVersion}`, `withoutRedirecting()`, and a check that output does
not echo the key. Tests are meaningful, not tautological. They cover the CAS/lease races with in-flight callbacks,
real Redis DB15 pop/fire, CSRF with the bypass disabled, and log capture asserting the exact log context.

There are no blockers. Two MAJOR findings need fixing before merge: page resilience, and a validator false positive
that will reject a large share of real Vietnamese outputs.

## Findings

### MAJOR-1 — A brief read failure 500s the Story page and section JSON
`app/StoryLens/StoryLens.php:88` calls `$this->briefs->present($story, $evidence)` with no guard. `present()` runs a
`story_briefs` `findOne` (`BriefStore::output`, `app/StoryLens/Brief/BriefStore.php:119-129`) and the extract
builder. The timeline and graph sections already degrade to `failed` inside `readForPage`, but the brief block does
not. The contract says a failed brief storage read must not 500 the page and canonical evidence must still render.
- **Failure scenarios:**
  - A transient `story_briefs` error (index build, a step-down on a future replica set, a timeout) throws from
    `findOne`.
  - A hand-edited or legacy `brief` field that is not an array: `$story->brief['published_id']` on a string throws a
    `TypeError`.
  - Either way, `/stories/{id}` and `/stories/{id}/sections` return 500, even though the Story and its Articles were
    read successfully.
- **Fix:** In `read()` (or in `Briefs::present`), wrap the published lookup in `try/catch (\Throwable)`. On failure,
  fall back to the extract (pure, no I/O), or to `{kind:'none', state:'failed'}` if the extract itself throws. Log
  only the exception class. Add a test that binds a `BriefStore` mock whose `output()` throws, then assert both
  endpoints return 200 with evidence and `brief.kind=extractive`.

### MAJOR-2 — The URL/domain heuristic rejects common Vietnamese abbreviations, and with them the whole output
`app/StoryLens/Brief/BriefValidator.php:41` uses the bare-domain branch
`(?:[\pL\pN-]+\.)+(?:[\pL]{2,63})(?=[/\s:?#),.;!”]|$)`. That branch matches abbreviations of the form `X.YY`. I ran
these through the validator:
- `"Mưa lớn tại TP.HCM khiến …"` → **rejected** (`text_markup_or_url`)
- `"… xảy ra lúc 9h ở P.Bến Nghé."` → **rejected**
- `v.v.`, `Q.1`, `U.S.` and `2.5` pass.

"TP.HCM" appears in a large share of Vietnamese news. A model that copies it from the source text (as it should) gets
the entire brief rejected. The result is a high `invalid_output` rate and editors seeing nothing to review. This
fails closed, so it is not a safety issue, but it undermines outcome 3 and the evaluation metric in outcome 9.
- **Fix:** Restrict the bare-domain branch to lowercase ASCII TLD-like labels after a dot with no uppercase in the
  label, or require a known-TLD allowlist (`com|vn|net|org|info|io|gov|edu|…`). Alternatively, rely on the explicit
  `scheme:`, `//` and `www.` checks plus a `\.(?:com|vn|net|org)\b` check. Add unit cases for `TP.HCM`, `P.Bến Nghé`,
  `Q.1` and `v.v.` (accept), and for `vnexpress.net` and `example.com/x` (reject).

### MINOR-1 — No `failed()` hook, so state can stick at `queued`/`generating` after a worker crash or unexpected exception
`app/Jobs/GenerateStoryBrief.php:15-68` has `tries=1`, which is good: no retry storm. The trigger is any exception that
is not a `BriefFailure`: a Mongo error in `finish`, a worker timeout or kill, or a `JsonException` from
`BriefPrompt::input`. The job then fails with the Story left at `generating` (lease set) or `queued`. The public page
is unaffected. The admin list, however, keeps showing `generating` indefinitely and the CLI or eval give no category.
- **Fix:** Add `public function failed(?\Throwable $e)` that writes `state=failed,
  error_category='worker_failed'`. Guard it by `brief.story_version == storyVersion` and
  `state in [queued, generating]` with an expired-or-own lease, and log only the exception class.

### MINOR-2 — Bulk generation has no rate limiting (free-tier 429 cascade)
`app/Console/Commands/StoryLens.php` (`brief --all`) and per-article ingest dispatch can push up to 50 jobs, which
workers then send back-to-back to the provider. Free Gemini, OpenRouter and Groq tiers allow roughly 5–30 RPM, so most
attempts end in `http_429`. Each one is recorded as an AI attempt and inflates the invalid/failed numbers. This is not
a retry storm, but it is a burst storm.
- **Fix:** Add `Illuminate\Queue\Middleware\RateLimited` (or `Redis::throttle`) keyed on the provider in
  `GenerateStoryBrief::middleware()`, released without consuming the single try. Or space out the dispatches with
  `->delay()` in `--all`. At minimum, document the expected 429 behavior.

### MINOR-3 — Gemini thinking budget versus the 10 s timeout and the 4096-token output cap
`app/StoryLens/Brief/Models/GeminiBriefModel.php:23`. Current Flash models think by default. Thinking adds latency,
which risks frequent `timeout` failures at the default `AI_TIMEOUT_SECONDS=10`. Thinking tokens may also count toward
`maxOutputTokens`, which risks `finishReason=MAX_TOKENS` → `provider_incomplete`. This is plausible with the default
`gemini-3.8-flash`. It is less likely with `gemini-flash-lite-latest` (the owner's .env).
- **Fix:** Send a minimal thinking configuration in `generationConfig` (the model-appropriate
  `thinkingConfig` field, e.g. a low level or zero budget). Or document that thinking models need
  `AI_TIMEOUT_SECONDS≈30`. Cover it with a request-shape assertion.

### MINOR-4 — The OpenAI-compatible driver accepts `http://` base URLs, so the bearer key can travel in cleartext
`app/StoryLens/Brief/Models/OpenAiBriefModel.php:28` allows the `http` scheme for any host.
- **Failure scenario:** A typo or a copied `http://` URL sends `Authorization: Bearer <key>` unencrypted.
- **Fix:** Allow `http` only for `localhost`, `127.0.0.1` or `::1` (local Ollama/LM Studio). Otherwise categorize as
  `disabled`.

### MINOR-5 — The validator accepts control and bidi-override characters
`BriefValidator.php:35-41`. I confirmed that `\u0000` and `‮` (RIGHT-TO-LEFT OVERRIDE) pass. Blade escaping
prevents XSS, but a bidi override can visually reorder or spoof the meaning of a published AI sentence in the admin
review and on the public page.
- **Fix:** Reject `\p{Cc}` (except whitespace already normalized) and `\x{202A}-\x{202E}\x{2066}-\x{2069}`, with
  reason `text_control_chars`. Add two unit cases.

### NIT-1 — Markdown emphasis and headings pass validation
`**Quan trọng**:` and leading `# ` are accepted. Only links, URLs and HTML are rejected, which matches the letter of the
spec. The prompt forbids only links, and these characters render literally. Consider rejecting `^\s*#`, `\*\*` and
backticks, or stripping them.

### NIT-2 — The admin show page does not settle pending history
`app/Http/Controllers/Admin/StoryBriefController.php:31-42` uses `Story::findOrFail` instead of a settle-then-read.
If the last write was interrupted before replay, the page shows `ready` with the message "Đã qua kiểm tra cấu trúc"
but no candidate and no accept form, until some other write replays the history.
- **Fix:** Call a public `BriefStore::settle($id)` first, or change the message when `$points` is null.

### NIT-3 — The admin index makes 50 `findOne` calls
The index (`StoryBriefController.php:25`) calls `published()` once per row. This is acceptable at 50 rows. A single
`$in` query on `published_id` would remove the N+1.

### NIT-4 — `brief-eval` without `--story` is unbounded
`brief-eval` iterates every Story and its full history. This is intentional per the report. Note it for later scale,
or add `--limit`.

### NIT-5 — Transport errors that are not connection errors are categorized as `invalid_output/transport`
`HttpBriefModel.php:36-38` puts these under `invalid_output/transport`, and DNS failures map to `timeout`. Both are
acceptable under the fixed category list. A short comment, or reason `transport`, under a non-`invalid_output`
category would keep the eval's invalid-output rate honest. Currently a transport throwable counts as invalid output in
`brief-eval`.

## Checked and fine
- **Spec invariants:** a partial output is never stored as a candidate (points are only written when the validator
  passes, and failures store `points: []`). Auto-publish only happens for validated output, carries a distinct label,
  and is hidden again once `require_review` returns to true (`reviewed_by` null). A rejection keeps the existing
  accepted output, and an acceptance supersedes the previous one.
- **Validator:** the evidence-id subset uses a strict `in_array` against the ids actually sent (`forPrompt` slice).
  Also checked: an exact `{points}` key set, list type, 2–5 points, per-point key count, 20–400 characters after a
  Unicode trim, a 1600 total, a 32 KB size cap, depth 16, and normalized duplicate detection. Any violation rejects
  the whole output with only a reason code.
- **Page and JSON:** no model or HTTP call (`Http::preventStrayRequests`, a throwing fake, and the
  `shouldNotReceive('generate')` mock). There is no error category in public JSON.
- **Secrets:** Gemini uses the `x-goog-api-key` header and the test asserts the key is absent from the URL and body.
  The OpenAI driver uses Bearer. Exceptions are reduced before they can carry request or response data. The dispatch
  log records only the exception class (tested with the key in the exception message). The job payload holds only
  the ids. The forced env entries in `phpunit.xml` keep the dev Gemini key out of tests.
- **Ingest:** dispatch failure is swallowed, then recorded as `queue_unavailable` (tested). Each advanced version
  queues once, and older jobs no-op at claim. The job hard-codes the `redis` connection, so `QUEUE_CONNECTION=sync`
  in tests does not hide queue behavior, and a real DB15 pop/fire test exists. TestCase flushes DB15.
- **Admin:** auth goes through the single `review-story-brief` Gate ability (RBAC-ready). Guest → login, user → 403,
  admin OK, and all POSTs return 419 without a token. There is no IDOR: the candidate must equal
  `brief.candidate_id` and match `story_id` and `story_version`. All AI and evidence text uses escaped `{{ }}`.
- **Provider shapes:** Gemini `generateContent` with `systemInstruction`, `responseMimeType` and
  `responseJsonSchema`. It checks `finishReason == STOP`, `promptFeedback.blockReason`, skips thought parts, and
  concatenates text parts. OpenAI `chat/completions` with `response_format: json_object` (the prompt contains
  "JSON"), checking `finish_reason == stop`, `refusal` and string `content`.
- **Other:** no new dependencies. Owner WIP files are untouched by gate code. `.env.example` lists all AI variables
  with empty keys and `AI_PROVIDER=none`. The nosql-local.md docs are accurate, including the provider data caveats
  and the `retry_after` note. The edits to existing tests are minimal and justified.

## Verdict

**APPROVE WITH FIXES.** Fix MAJOR-1 and MAJOR-2 with tests before merge. MINOR-1 to MINOR-5 are recommended in this
gate (all small). The NITs are optional.
