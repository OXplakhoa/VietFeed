# Gate 9.6 — REVIEW-3 (fix round 2 verification)

This was a read-only round, as control asked. I ran no tests because control is using the shared stores. I re-read
the following against "Fix round 2" in GATE-REPORT-9.6.md:
- `BriefValidator`
- the `ContentCatalog::ingest/attach` refactor
- `Briefs::presentStories/presentWithLookup/published`
- `BriefStore::acceptedOutputs`
- `BriefEvidence::readForStories`
- `StoryBriefController::index`
- `BriefPrompt`
- the docs and config for the model default

I ran pure-PHP scratch scripts that only instantiate `BriefValidator`: the 34 samples from REVIEW-2, 24 new
abbreviation, number and URL cases, and 10 slash cases.

## REVIEW-2 findings: status

| REVIEW-2 | Status | Evidence |
| --- | --- | --- |
| MAJOR-1 URL detection | **Fixed** (one residual false-positive pattern, see R3-MINOR-1) | See the probe results below this table. |
| MINOR-1 dispatch spacing | **Fixed** | `ingest` records `$versions[storyId] = final version` in first-attachment order and queues once per Story in a `finally`, with `position` counting Stories. A Story version committed before a projection publish failure or a later attach exception still gets a brief. `Briefs::queue` swallows its own errors, so the `finally` cannot hide the original exception. The `attach()` extraction is a verbatim move of the old loop body (CAS, counts and events are unchanged). The reported tests cover 15 articles in one Story (one job, delay 0), three interleaved Stories (0/6/12 s), and re-ingest (nothing queued). |
| MINOR-2 model default | **Fixed** (control confirmed) | `config/ai.php:7`, `.env.example:117`, `nosql-local.md:1581` and the decision log at GATE-REPORT:12 and 114 all say `gemini-flash-lite-latest`, describe it as a moving alias and mention the `GEMINI_MODEL` override. I found no stale `gemini-3.8-flash` reference. |
| NIT-1 log noise | **Fixed** | `Log::debug` with only the exception class, wrapped so that a logging failure cannot break the fallback (`Briefs.php:~125`). |
| NIT-2 index vs public | **Fixed** | Both paths now go through `presentWithLookup`. The index batches accepted outputs (projection includes `points` and `reviewed_by`) and evidence (`readForStories`, one query). `published()` re-checks `story_id`, `story_version` and `_id` for every looked-up output and applies the reviewer rule, so a pointer copied to another Story or a stale version is ignored. A failed batch lookup becomes a per-Story `unavailable` result through the throwing closure. |
| NIT-3 line breaks | **Fixed** | The prompt says "Mỗi trường text chỉ có một dòng, không xuống dòng". The validator adds `\p{Zl}\p{Zp}` to the control-character class. |

Probe results for MAJOR-1:
- **Now rejected (14 previous false negatives):** `bit.ly/abc`, `VnExpress.net`, `EXAMPLE.COM`, `Example.Com/tin-tuc`,
  `x.ai`, `example.ai`, `example.xyz`, `google.co.uk`, `example.org.uk`, `example.com.au`, `192.168.1.1/admin`,
  `10.0.0.1:8080`, `sub.domain.store`, `bank-vn.top`.
- **Still accepted (Vietnamese abbreviations, names and numbers):** TP.HCM, Tp.HCM, TP.HN, P.Bến Nghé, Q.1,
  Q.Phú Nhuận, Tp.Thủ Đức, TT.Huế, T.Ư, GS.TS, PGS.TS, ThS.BS, U.23, U.23/U.20, U.S. Army, v.v., Co.op Mart,
  St.Petersburg, VNM.HM, Pro.Max, the missing-space typo "Hà Nội.Theo", 3.5%, 0.5%, 1.250.5, 1.200.000.000 đồng,
  15.5 triệu đồng/tấn, 120.000 đồng/kg, 2.5m/s, 6.5%/năm and Q.1/TP.HCM.
- **Still accepted, a documented limitation:** obfuscated forms (`example[.]com`, the full-width `example。com`).

## Findings

### R3-MINOR-1 — The domain-plus-path rule rejects "TP.HCM/<word>" and similar slash constructions
`app/StoryLens/Brief/BriefValidator.php` (`DOMAIN`, the `[a-z]{2,63}(?=(?::[0-9]{1,5})?/\S)` branch). Any
`label.letters/` sequence counts as a URL whatever the suffix. My probe results:
- `"Giá nhà tại TP.HCM/Hà Nội …"` → rejected
- `"… tại TP.HCM/ngày …"` → rejected
- `"Ngân hàng ACB.HN/HCM …"` → rejected
- `"Q.1/TP.HCM"` and `"Hà Nội/TP.HCM"` pass, because the abbreviation comes last.

"TP.HCM/Hà Nội" (and "TP.HCM/tỉnh X") is an ordinary construction in Vietnamese news and market reports. It fails
closed, so there is no safety issue, but the whole AI output is discarded. The case is much rarer than bare "TP.HCM",
which was REVIEW-1 MAJOR-2, so this is MINOR.
- **Fix (any one of these):**
  - (a) Exempt known Vietnamese administrative prefixes from the start of the host. For example, add
    `(?!(?:tp|tx|tt|q|p|h)\.)` after `HOST_START`, case-insensitive. Real hosts such as `tp.example.com` are
    negligible.
  - (b) Require the path branch's final label to contain at least one lowercase letter, or to be a known suffix. This
    keeps `Example.Com/x` and `bit.ly/x` rejected and lets the all-caps `HCM/` and `HN/` through. A path to an
    all-caps unlisted TLD (`EXAMPLE.XYZ/x`) would still be caught by the suffix branch, since `xyz` is listed.
  - Add `TP.HCM/Hà Nội` and `ACB.HN/HCM` (accept) and `bit.ly/x` (reject) to the providers.

### R3-NIT-1 — The IPv4 rule matches 10-digit VND amounts whose groups are all 100–255
`"Giao dịch trị giá 2.150.250.100 đồng"` is rejected as an IPv4 address. `1.200.000.000 đồng` passes, because the
octet pattern does not allow leading zeros. Only about 0.4% of 10-digit amounts have every group in 100–255, and
"1.2.3.4" version strings are also rejected, which is acceptable. Optional fix: skip IPv4 matches followed by
`\s*(?:đồng|đ|vnđ|usd|tỷ|triệu)`.

### R3-NIT-2 — The admin index computes an extract and loads full evidence for 50 Stories
`StoryBriefController::index` → `presentStories` reads every Article of the 50 Stories (with descriptions) in one
query and runs `BriefEvidence::extract` per Story, only to print the label "Trích từ nguồn". This is fine at demo
scale. For large Stories, consider projecting `_id`/`title` only and skipping `extract`, since `kind` only needs to
know whether any excerpt text exists. A malformed `article_ids` value would also 500 the index (`array_fill_keys`),
which is acceptable for admin.

## The `CommentsMongoTest` first-run failure

`test_string_comment_id_flows_from_report_to_case_events_and_the_cassandra_comment_ref_column` asserts the Cassandra
row order `['REPORTED','SANCTION_ISSUED','COMMENT_HIDDEN']`. Rows are clustered by `(occurred_at ASC, event_id ASC)`
(`app/Moderation/ModerationAudit.php:47`). The REPORTED event's `occurred_at` comes from `$report->created_at`
(`ModerationCases.php:99,146`), while the sanction and hide events use `ModerationEvents::now()` and UUIDv7 event ids.
The order therefore depends on timestamps taken at different moments, in different code paths, at millisecond
precision. When those timestamps tie or come close (fast machine, or rounding of `created_at` to the ms that Mongo
stores), the tie-break falls to UUIDv7 bytes, whose bits after the millisecond are random. That fits an
order-dependent flake that passes on rerun.

This gate's diff touches nothing on that path. Git status and the list of untracked files show no changes under
`app/Moderation`, comments, sanctions, reports or `ModerationEvents`. The gate's only shared-harness edit is adding
`story_briefs` to the Mongo wipe list in `tests/TestCase.php`, which cannot affect moderation ordering. Gate 9.6 code
writes nothing to Cassandra.

**Judgement:** plausibly a pre-existing timing flake, unrelated to Gate 9.6. It should not block the gate. I recommend
a follow-up outside this gate: make the test or the projection deterministic. For example, give REPORTED an
`occurred_at` strictly before later case events, or compare on `case_version` and `aggregate_version` instead of
wall-clock order. Record the flake in the gate report's follow-ups. If control's current full run fails the same way
again, file it as a known flake with the seed and timings.

## Regressions

I found none. The publication rule (accepted, reviewed when required, current version, citations resolved against
current evidence) is the same code path for the public page and the admin index. Stale-version fencing and lease and
request guards are unchanged since REVIEW-2. Ingest's brief dispatch still cannot fail ingest.

## Verdict

**APPROVE.** The blocking and major findings from REVIEW-1 and REVIEW-2 are resolved, with tests. R3-MINOR-1 (the
"TP.HCM/…" false positive) is a small fail-closed quality issue. I recommend fixing it in this gate if a round is
cheap, otherwise as an immediate follow-up. The NITs are optional. The CommentsMongoTest failure is plausibly
pre-existing and unrelated.
