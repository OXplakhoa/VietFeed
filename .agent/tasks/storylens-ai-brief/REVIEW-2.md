# Gate 9.6 — REVIEW-2 (fix round 1 verification)

I re-read the changed classes: `BriefValidator`, `BriefStore`, `Briefs`, `GenerateStoryBrief`, the Gemini, OpenAI and
HTTP model classes, `StoryBriefController`, `ContentCatalog`, the CLI command, `config/ai.php` and `.env.example`. I
also re-read the new tests.

- **Targeted tests:** I ran `php artisan test tests/Unit/BriefValidatorTest.php tests/Feature/BriefModelTest.php
  tests/Feature/StoryBriefTest.php tests/Feature/StoryPageTest.php tests/Feature/StoryCatalogTest.php
  tests/Feature/NoSqlRuntimeTest.php`. Result: 151 passed, 1,645 assertions, on isolated stores.
- **Validator probes:** I ran a scratch script with 34 Vietnamese and URL samples through `BriefValidator`. The
  results appear in REVIEW2-MAJOR-1 below.
- **Laravel source check:** I read `vendor/.../CallQueuedHandler::failed` to confirm that `failed()` receives the job
  instance. That means `attempts()` returns real values.

## REVIEW-1 findings: status

| REVIEW-1 | Status | Notes |
| --- | --- | --- |
| MAJOR-1 page resilience | **Fixed** | `Briefs::present` wraps state, the published lookup, citation, and the extract in try/catch, and logs only the exception class. Malformed `brief`, `published_id` or reviewer values are detected. The test runs three cases on both HTML and JSON: a store that throws with the key in the message, a malformed field, and a malformed output shape. It asserts 200, that evidence is still shown, `state=unavailable`, and that the key is absent. The test is meaningful. |
| MAJOR-2 abbreviations | **Fixed for false positives**, with new false negatives (see REVIEW2-MAJOR-1) | `TP.HCM`, `P.Bến Nghé`, `Q.1`, `TT.Huế`, `Tp.HCM`, `v.v.`, `U.23`, `GS.TS`, `GD&ĐT` and `3.5%` all pass. |
| MINOR-1 failed() hook | **Fixed** | Analysis below. The test covers a stale version, a replaced request, redelivery (attempts=2) during a live lease, the own lease, and `failed(null)`. |
| MINOR-2 staggering | **Partly fixed** | See REVIEW2-MINOR-1. |
| MINOR-3 thinking | **Fixed** | `generationConfig.thinkingConfig.thinkingLevel` is sent only when set to MINIMAL, LOW, MEDIUM or HIGH (uppercase REST enum), and omitted by default. `maxOutputTokens` is 16384. The docs say "where supported". The shape is plausible for Gemini 3 models. |
| MINOR-4 HTTPS | **Fixed** | Remote endpoints require `https`. `http` is allowed only for `localhost`, `127.0.0.1` and `[::1]` (PHP `parse_url` keeps the brackets). URLs with user, pass, query or fragment are still refused, so `http://127.0.0.1@evil` and `localhost.evil` are disabled. Tests exist. |
| MINOR-5 / NIT-1 control, bidi and markdown | **Fixed** | Validation now rejects `\p{Cc}`, LRM/RLM, U+202A–202E, U+2066–2069 (including the entity-encoded `&#x202E;`), `**`, `__` and a leading `#`, `- ` or `* `. The tests are table-driven. |
| NIT-2 settle on admin GET | **Fixed, safe** | See below. |
| NIT-3 batched index | **Fixed** | `publishedStories` makes one `$in` query and matches each Story ID to its `[output _id, story_version]` pointer, which rejects a pointer copied to another Story. When review is required it also checks that `reviewed_by` is a non-empty string. |
| NIT-4 eval bound | **Fixed** | Default 50, `--limit` from 1 to 100. |
| NIT-5 transport category | **Fixed** | Transport errors are now `timeout` (cURL errno 28 or a timeout message) or `network`. Only `invalid_output` counts toward the evaluation numerator, and a test checks a mixed history. |

### Analysis of the requested hot spots

- **`failed()` → `BriefStore::workerFailed`** (`app/StoryLens/Brief/BriefStore.php:65-87`):
  - **Version:** the write must match `version` and the brief `revision`, and the brief's `story_version` is checked
    as well, so it cannot overwrite a newer Story version.
  - **State:** the write only applies while the brief is `queued` or `generating`, so it cannot touch a `ready` brief
    or a brief that already `failed`.
  - **Request:** when the job carries a request ID, `brief.request_id` must match, so it cannot overwrite a newer
    request's state.
  - **Live lease:** a live lease is cleared only by its own first attempt (`lease_request_id == request &&
    attempts()==1`), which is the worker that actually died, for example in the timeout handler. A redelivered job
    (attempts ≥ 2, after `retry_after`) can only fail an expired lease. If the original worker is still running past
    its lease, `finish()` already refuses to commit.
  - **Legacy jobs:** a job without a request ID can only fail `queued` with no `request_id`, or `generating` with an
    expired lease. Both are harmless.
  - **Exceptions:** an exception after `finish()`'s CAS commits (for example during the history replay) leaves the
    state at `ready` or `failed`, so the hook is a no-op.
  - **Sync dispatch:** with `dispatchSync`, the SyncQueue calls `failed()` first, and then the follow-up
    `dispatchFailed` is a no-op because its guard requires `queued`.
  - Correct.
- **Expired lease display:** `Briefs::state` (`Briefs.php:426-433`) shows `generating` with an expired or missing
  lease as `failed`. The comparison uses the app clock, but this affects display only, and writes still use Mongo
  `$$NOW`. Retry on an expired lease passes the queue guard. Fine.
- **Settle on admin GET** (`StoryBriefController.php:520`): the replay only finishes a transition that a Story CAS
  already committed. It uses `$setOnInsert` upserts (a duplicate key on the extractive index is tolerated),
  status-conditioned `$set`s, and an `$unset` guarded by revision. It is idempotent, accepts no input from the
  request, and sits behind the `review-story-brief` ability. A browser prefetch or a CSRF-triggered GET could only
  finish an editor's own committed decision, which is harmless. A Mongo error during the replay makes the admin page
  return 500, which is acceptable for the admin page (the public path never replays).
- **Batched index:** correct (see the table above).

## Findings

### REVIEW2-MAJOR-1 — The URL check misses real URLs: domain plus path with a TLD outside the list, and any uppercase domain
`app/StoryLens/Brief/BriefValidator.php:13-14,47-48`. The TLD allowlist is case-sensitive (`~u`, no `i`) and short.
Results from my probe:

| Accepted (should be rejected under "no URLs") | Rejected (correct) |
| --- | --- |
| `bit.ly/abc` | `example.com/path` |
| `192.168.1.1/admin` | `vnexpress.net` |
| `Theo VnExpress.net, …` | `tuoitre.vn` |
| `EXAMPLE.COM` | `chinhphu.gov.vn` |
| `x.ai` | `dantri.com.vn` |
| `example.ai` | `t.co/abc` (only because `co` is a listed TLD) |
| `example.xyz` | `apps.apple.com` |
| `google.co.uk` | |
| `example.org.uk` | |
| `example.com.au` | |
| `example[.]com` | |
| `example。com` | |

The worst gap is a domain followed by a path (`bit.ly/abc`, an IP followed by a path). That is a URL by any reading,
and the contract (outcome 3) requires output with any URL to be rejected. "VnExpress.net"-style
mixed case is how Vietnamese outlets usually write their names, so the mixed-case gap is likely to come up in
practice.
- **Mitigating factors:** Blade does not auto-link, so nothing becomes clickable, and an editor reviews every brief by
  default. That is why this is MAJOR and not BLOCKER. It is still a contract gap the fix round claimed to close.
- **Fix:**
  - Add a host-plus-path rule independent of the TLD:
    `(?<![\pL\pN_.-])(?:[a-z0-9-]+\.)+[a-z]{2,63}/\S` and `\b\d{1,3}(?:\.\d{1,3}){3}\b`, both with the `i` flag.
  - Make the TLD allowlist case-insensitive (`TP.HCM`, `Q.1` and `GS.TS` still pass because `hcm`, `ts` and digits
    are not listed TLDs).
  - Add common TLDs: `ai|ly|xyz|tv|us|uk|au|biz|top|online|site|gov|edu`, plus a second-level `(?:co|com|org|gov|edu)\.[a-z]{2}`.
  - Add unit cases for `bit.ly/x`, `VnExpress.net`, `EXAMPLE.COM`, `x.ai`, `google.co.uk` and `192.168.1.1/admin`
    (reject), and re-run the abbreviation provider (accept).
  - Obfuscated forms (`[.]`, the full-width `。`) can stay out of scope. Note them as a known limitation.

### REVIEW2-MINOR-1 — Ingest spacing is per article, not per Story, so the only job that matters waits longest
`app/Content/ContentCatalog.php` (`queue(..., position: $count)`) together with `Briefs::queue`
(`Briefs.php:324`).
- **Failure scenario:** an ingest of 15 articles into one Story queues versions 1–15 with delays of 0, 6, … 84 s.
  Jobs 1–14 no-op at claim because the version no longer matches. The current version's job, the only useful one,
  is delayed 84 s. Spacing helps across Stories but adds latency within a Story. It also does not throttle globally:
  concurrent ingests, `--all` runs and several workers can still burst.
- **Fix:** Use the position of the distinct Story within the ingest call, or queue once per Story after the loop with
  its final version. Optionally add a provider-keyed `RateLimited`/`Redis::throttle` job middleware for real
  throttling. A test should assert the last version of a single Story is not delayed by its sibling articles.

### REVIEW2-MINOR-2 — The default Gemini model changed without an owner or control decision, and the decision log is stale
`config/ai.php:7` and `.env.example:117` now default to `gemini-flash-lite-latest`. Control's brief stated the
config default as `gemini-3.8-flash`. The owner's key working with flash-lite is set in `.env`, not in the defaults.
- **Why it matters:** `*-latest` is a moving alias, so behavior can change silently. `GATE-REPORT-9.6.md:12` still
  says "Gemini defaults to `gemini-3.8-flash`". If the flash-lite alias resolves to a model without `thinkingLevel`
  support, setting `GEMINI_THINKING_LEVEL` yields `http_4xx`. The docs' "where supported" wording only partly covers
  this.
- **Fix:** Either revert the default to the pinned Flash model and keep flash-lite as an `.env` choice, or have
  control or the owner confirm the change. Either way, make the decision log consistent.

### REVIEW2-NIT-1 — Repeated warning log on the public page path
`Briefs.php:398`: a persistently malformed brief logs `Story brief unavailable` on every page view and every
section-JSON poll. Consider rate-limiting the log (for example once per Story per minute through cache) or logging at
`debug` after the first occurrence.

### REVIEW2-NIT-2 — The index "AI" label can disagree with the public page
`Briefs::publishedStories` does not apply the `cite()` evidence-membership check that `present()` applies. In the
edge case where a cited article is no longer in the Story's `article_ids`, the admin index says "AI" while the public
page shows the extract. This is display-only.

### REVIEW2-NIT-3 — `__` and newlines are rejected outright
`BriefValidator.php:44,47`: a rare `__` in the text, or a model-inserted `\n` inside a point, rejects the whole output.
This is acceptable by design (fail closed). It is worth adding "one line, no newlines" to `BriefPrompt::INSTRUCTIONS`
if it is not there already, to keep the invalid-output rate down.

## Regressions
I found none. Publication invariants, stale-version fencing, secret handling, auth and CSRF behave as in REVIEW-1. The
new `request_id`/`lease_request_id` fields are dropped from non-lease writes as intended (`BriefStore.php:240-242`),
and `claim` now also binds to the request, which removes the duplicate-job ambiguity noted in REVIEW-1.

## Verdict

**APPROVE WITH FIXES.** Close REVIEW2-MAJOR-1 (domain plus path, case-insensitive TLDs, with tests). Resolve
REVIEW2-MINOR-2 with control or the owner, and correct the decision log. REVIEW2-MINOR-1 is recommended. The NITs are
optional.
