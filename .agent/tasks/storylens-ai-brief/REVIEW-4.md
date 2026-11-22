# Gate 9.6 — REVIEW-4 (fix round 3 verification)

This was a read-only round. I ran no tests and touched no stores. I re-read `BriefValidator`, `BriefFailure`,
`GenerateStoryBrief`, `BriefStore` (queue, claim, finish, workerFailed, dispatchFailed and attemptFilter),
`config/ai.php`, `.env.example` and the docs rows. I also listed the new retry tests. I re-ran my 68 validator
samples, the 10 slash cases and 14 new cases in pure-PHP scratch scripts that only instantiate `BriefValidator`.

## Validator (R3-MINOR-1, R3-NIT-1)

**Fixed.**
- **68 earlier samples:** 34 accepted and 24 rejected, exactly as before, except that `2.150.250.100 đồng` now passes
  as intended.
  - Every earlier URL case is still rejected: `bit.ly/abc`, `VnExpress.net`, `EXAMPLE.COM`, `Example.Com/tin-tuc`,
    `x.ai`, `.xyz`, `.co.uk`, `.org.uk`, `.com.au`, `192.168.1.1/admin`, `10.0.0.1:8080`, `sub.domain.store`,
    `bank-vn.top`, `apps.apple.com`, `t.co/abc`.
  - None of the accepted samples contains a URL.
- **Slash constructions:** all 10 cases pass, including `TP.HCM/Hà Nội`, `TP.HCM/ngày`, `ACB.HN/HCM` and
  `Q.1/TP.HCM`. The mixed- or upper-case paths `BIT.LY/ABC`, `Bit.Ly/abc` and `Example.Foo/x` are rejected, the first
  two through the known-suffix branch.
- **IPv4:** `2.150.250.100 đồng` and `… VNĐ` pass. Bare IPs (`8.8.8.8`) and an IP followed by a path are rejected.

### R4-NIT-1 — Two small new false negatives
- `EXAMPLE.FOO/x` (an all-caps unlisted suffix followed by a path) now passes. This is the stated trade-off for
  `TP.HCM/…`.
- The currency exemption also matches "đồng thời" ("simultaneously"), so `"Máy chủ 192.168.1.1 đồng thời bị tấn
  công"` passes. `10.0.0.1 USD` passes too.

Neither is clickable (Blade escapes the text and nothing auto-links), and review is required by default. Optional
fixes: require a digit-free word boundary and exclude `đồng thời`, for example by changing `đồng` to
`đồng(?!\h+thời)`. Accept both cases as known limitations next to the `[.]`/`。` note.

## Transient retry

**Correct.** I traced each property the coordinator asked about:
- **Retryable categories:** `BriefFailure::isRetryable()` covers only `http_5xx`, `timeout` and `network`. A 429,
  402, other 4xx, `invalid_output`, `disabled` or `sensitive_output` failure never retries. Tests cover the permanent
  statuses. Invalid output is not retryable by construction.
- **No retry storm:** `$retry = providerAttempt === 1 && retryable`. Attempt 2 can never schedule another attempt, so
  there are at most 2 provider calls per request. The retry is a new job with `tries=1`, not a queue release, so queue
  tries and backoff do not interact. During a provider outage, `--all` doubles its calls at most, and the retries keep
  the original 6 s spacing because each comes 30 s after its own first attempt.
- **Guards:**
  - `finish(..., retry: true)` commits `state=queued, provider_attempt=2` only if the Story version, the brief revision
    (owner), `state=generating` and the unexpired lease all still match. If the Story grew during attempt 1, the
    finish fails, `handle()` returns early, and no retry is dispatched.
  - The delayed attempt-2 job claims only when `version`, `brief.story_version`, `request_id` (required whenever
    `providerAttempt > 1`), `provider_attempt == 2`, a `queued`/`generating` state and an expired lease all match.
  - **The Story grows during the 30 s delay:** ingest's `queue()` writes a new request with `provider_attempt=1`, so
    the old attempt-2 job does nothing.
  - **An admin clicks "Tạo lại" during the delay:** `finish` dropped the lease, so the queue guard passes and a new
    request replaces the old one, which then does nothing. Correct.
  - Each attempt takes its own `$$NOW` lease when it claims.
- **`failed()` hook:** `workerFailed` now also filters on `provider_attempt` (1 matches 1 or a missing field, for
  documents written before the retry) and requires the request ID when `providerAttempt > 1`. A failure of attempt 1
  after the retry was committed (provider_attempt is now 2) is a no-op, so it cannot overwrite the pending retry.
  Attempt 2's own crash marks `worker_failed` under the same rules as before.
- **Sync path:** `--sync` sleeps 3 s, then runs attempt 2 inline with `dispatchSync`. If attempt 2 throws, the outer
  job's `failed()` and `Briefs::queue`'s `dispatchFailed` are both no-ops because the attempt numbers no longer match.
- **Retry dispatch failure:** `dispatchFailed(..., providerAttempt: 2)` marks `queue_unavailable`, guarded by state,
  version, request and attempt, and the first attempt stays in history (tested).
- **Evaluation:** each provider call records one AI output with `provider_attempt` and `request_id`. A 503 followed by
  a success counts as two attempts with an invalid rate of 0, because only `invalid_output` is in the numerator. The
  latest current-version attempt (the successful one) supplies points and coverage. This is sensible. Transient
  failures inflate `attempts`, but that is honest, since each was a real call.
- **Configuration:** `AI_RETRY_DELAY_SECONDS=30` and `AI_SYNC_RETRY_DELAY_SECONDS=3` are in `config/ai.php` (clamped
  at ≥ 0), `.env.example` and `nosql-local.md`.

### R4-MINOR-1 — An unguarded `dispatchFailed` in `retry()` can leave the state at `queued` with no job
`app/Jobs/GenerateStoryBrief.php:117-124`. The trigger is a Redis dispatch failure followed by a `dispatchFailed`
Mongo write that also throws. The exception escapes `handle()` and the job fails. `failed()` then calls
`workerFailed(providerAttempt: 1)`, which is a no-op because the brief is now at attempt 2. The Story stays
`queued`/attempt 2 with no lease and no job. The public page is unaffected: it shows the extract, and the state is
`queued`. The admin page shows `queued` indefinitely, although "Tạo lại" still works because the lease has expired.
This needs two stores to fail at once, so it is MINOR.
- **Fix:** wrap `dispatchFailed` in `try/catch (Throwable)` and log only the exception class. Optionally, have
  `Briefs::state` show `queued` with `provider_attempt=2` and a stale `updated_at` (older than the retry delay plus a
  margin) as `failed`.

### R4-NIT-2 — Sync retry blocks the CLI
With `--all --sync`, a full outage costs up to 50 × (2 × timeout + 3 s), about 19 minutes at the defaults. This is a
demo-only mode and documented, so it is acceptable. Mention it in the demo note, or skip the sync retry when `--all`
is used.

## Verdict

**APPROVE.** The validator changes are correct, with no regression on the earlier URL corpus and with the Vietnamese
slash and currency cases now accepted. The transient retry is bounded to one attempt and covers only transient
categories. It is fenced by version, request, attempt and lease, and it cannot overwrite newer state through the
`failed()` hook. R4-MINOR-1 is a one-line hardening worth doing if another round happens. The NITs are optional.
