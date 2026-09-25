# STATUS — neo4j-foundation

- Branch: `neo4j/client-11` (from main fcbb98f, 2026-09-25)
- Base commit: fcbb98f97d50b64f574f99cdd61aed41414d4f1d (Merge PR #10, Gate 3 DONE)
- Classification: Small–Medium (new client + spike test; no prod model changes)
- Control session: pi-control
- Execution: AFK autonomous run (owner pre-approved retries, no merge)

## Scouts

- Repo scout (control): done — neo4j:5-community params, no client installed, PHP ^8.3
- Test scout (control): done — Integration conventions + Neo4jSpikeTest copy list

## Tickets

- T1 (full Gate 4): GitHub #11 — AFK run pending (no evidence yet). Ticket: T1-neo4j-client.md.

## Startup (2026-09-25 AFK)

- Ponytail full active (ladder enforced, smallest diff). Typesafe/warden enables: no slash
  tool in this harness — offline guards apply (no secrets, no destructive cmds, read-only reviewers).
  Warden live-flagged a hardcoded compose dev-default password in the spike draft → removed,
  env-only with fail-fast assert (see W1 below).
- `git checkout main && git pull` → fcbb98f; branch `neo4j/client-11` created.
- Preserved WIP stash `afk-preserve-2026-09-25` (CLAUDE.md skills section + SourceSeeder feed_url
  fixes from mongo/crud-7) — left stashed, never to be committed on this branch.
- `docker compose up -d` (daemon was down, containers absent); `./scripts/nosql-health.sh` → 4/4 READY.
- Research: laudis/neo4j-php-client 3.6.1 (primary: README + packagist + neo4j.com community drivers
  page + graphaware ARCHIVED notice). Decision: see Files changed.

## Files changed

- `composer.json` / `composer.lock` — added `laudis/neo4j-php-client: ^3.6` (locked 3.6.1).
  Why (3 lines): neo4j.com community-drivers page recommends it as the PHP client over Bolt/HTTP;
  v3.x supports Neo4j ^4.0/^5.0 + PHP ^8.1 (covers repo PHP ^8.3/8.4, ext-bcmath/sockets present);
  graphaware/neo4j-php-client is ARCHIVED (legacy fork only) — laudis is the maintained successor,
  testkit-validated with the official driver team.
- `tests/Integration/Neo4jSpikeTest.php` (new) — 4 tests, Integration-spike conventions
  (header: gate/issue ref, explicit run cmd, health prerequisite; outside phpunit suites by design).
- `.agent/tasks/neo4j-foundation/STATUS.md` — this file (checkpoint tracking).
- Out-of-scope touched: NONE. `composer require` needed `--ignore-platform-req=ext-mongodb`
  (pre-existing: lock wants ext-mongodb ^2.4, host has 2.3.3 — unrelated to this ticket, not fixed here).
  Repo-wide `pint --test` failures (15 files: seeders, lang, routes, etc.) are pre-existing on main,
  untouched per minimal-diff rule; spike file itself is Pint clean.

## Decisions

- Grill skipped (Gate 4 executable as-is).
- Single ticket (AFK-friendly: no mid-run handoff).
- Test style follows CassandraSpikeTest (tests/Integration/, outside suites).
- UUID discipline: business IDs are UUIDv7 strings in `uuid` property; never Neo4j internal IDs.

## Acceptance criteria status

- Gate 4 bullet 1 (client + connect): DONE (3.6.1 locked, Bolt 127.0.0.1:7687 via config('nosql.neo4j'), env-only)
- Gate 4 bullet 2 (idempotent UUID upsert): DONE (MERGE on uuid, double-run → 1/1/1 counts)
- Gate 4 bullet 3 (multi-hop query): DONE (Story<-PART_OF-Article-FROM->Source + MENTIONS/EMPHASIZES,
  uuids + 4 rel types asserted)
- Gate 4 bullet 4 (retry test): DONE (fresh client re-reads dropped client's write; typed-error test extra)

## Warden notes

- W1 (2026-09-25): hardcoded compose dev-default NEO4J password fallback in spike draft flagged
  ("Never commit secrets") → fixed same-turn: env-only + assertNotEmpty fail-fast, no fallback.

## Test results

- `vendor/bin/phpunit tests/Integration/Neo4jSpikeTest.php` — 4/4 pass, 19 assertions, x3 runs green
  (run1 765ms incl. connect, run2 210ms, run3 217ms; first green run 4344ms cold).
- `composer run test` — 51/51 pass, 226 assertions (spike outside suites by design, not counted).
- `vendor/bin/pint --test tests/Integration/Neo4jSpikeTest.php` — PASS. Repo-wide pint --test FAIL
  on 15 pre-existing files (main), none touched by this ticket.
- `./scripts/nosql-health.sh` — 4/4 READY at spike time.
- No secrets committed (warden-verified; .env untouched, `git diff` shows no credential values).

## Checkpoint

- Base: fcbb98f97d50b64f574f99cdd61aed41414d4f1d
- Review SHA: 01cfab0a30a71bb1c580f69d04cd3cc62833475f (initial; fix loop below)
- Fix SHA: (recorded on fix commit below)

## Review swarm (2026-09-25, fixed point 01cfab0)

- Standards: no P0/P1 (P2 cleanup dup, P3 x2). Spec: (2)(3)(4) PASS, P1 docs gap (F1).
- Runtime owner 6/6 PASS: spike 4/4 x3, suite 51/51, pint file, 4/4 healthy, 4/4 READY.
- Ponytail-review: notes only (P2 dup of standards P2, P3 single-query counts).
- Fix loop x1: F1 rationale into spike header; F2 reconnect → try/finally + helper.
  Re-verify final code: spike 3x (962/385/206ms), suite 51/51, pint file PASS.
- Verdict: PASS_WITH_NOTES — see REVIEW.md (untracked, per slice convention).
