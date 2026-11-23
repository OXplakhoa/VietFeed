# Gate 9.7a — RBAC implementation report

Branch `rbac/permissions` · Control review and owner approval before merge.

## Decisions

- Used the six fixed permission names in the task. Laravel Gates evaluate the
  authenticated MongoDB user. `admin` middleware admits staff and the mapped
  Gate controls each route. `review-story-brief` remains a compatibility Gate
  for `stories.review`.
- `users.manage` includes deletion of ordinary user accounts. It allows a
  named Admin to edit ordinary users' names and emails. The account Gate guards
  staff targets, and a Super Admin Gate guards requests that supply role or
  permission fields. Both fields are excluded from User mass assignment.
- Self-demotion and self-deletion are allowed for a Super Admin when the guard
  can reserve the removal. Both the admin and profile deletion paths use the
  same guard. Normal profile self-service persists for other accounts.
- Permission loss and demotion to `user` increment the API token epoch in the
  same MongoDB user write, then trigger the existing Redis family cleanup.
  An access or refresh token with a stale epoch fails even if cleanup fails.
- The existing public comment delete action checks `comments.manage` for its
  staff override. Staff visibility of hidden comments also uses that
  permission. Authors retain their own comment access.
- The registered category detail URL forwards to the existing edit page.
- Cassandra gets one event per committed authorization change and per refused
  invariant attempt. The durable MongoDB outbox covers failures across both
  Cassandra inserts. A successful change can commit while Cassandra is down.
  Replays use the same event ID and timestamp. The global table uses daily UTC
  buckets with 30-day retention anchored to the original bucket. User partitions
  retain history. Audit reads return an unavailable state on Cassandra error.
- The first bootstrap grants every legacy Admin the full fixed catalog and
  records the initial account. Later reruns preserve edits and revocations.
  Run first bootstrap with writers paused. The fresh demo seeder bootstraps
  `admin@vietfeed.com`. The separate moderation demo seeder gives its demo
  Admin the four permissions used in the walkthrough and preserves later
  grants and revocations.

### Final Super Admin concurrency

The MongoDB singleton `authorization_control/super-admins` holds a conservative
set of registered IDs. Every registered ID denotes a committed Super Admin
account. Promotion commits to the user first, then adds the ID. Removal
atomically pulls an ID only if the same document contains that ID and still has
another member. The caller performs the user write only after winning that
reservation. That means two removals may read two Super Admin user documents
and still only one can reserve a removal. Removal of the same account also
requires winning the reservation. An authorization version predicate fences
stale user writes and deletes across role changes.

If a second write fails or its result is uncertain, the implementation leaves
the guard conservative. It never adds back an ID based only on an exception.
A surviving role with no ID cannot be removed until membership is repaired.
Control pauses writers, confirms there are no in-flight requests, verifies the
committed and sign-in-capable Super Admins, and replaces only the guard ID
list. The local NoSQL runbook details this repair.

A permanent ban can block access to the staff UI. The moderation write
therefore refuses a permanent ban of a Super Admin. For other users the
permanent-ban write claims a MongoDB marker and changes the authorization
version before writing the sanction. Promotion checks for the marker and a
committed permanent ban. The compare-and-set version makes a promotion racing
with a new ban fail closed. Interrupted ban markers require verifying the
referenced sanction before clearing the marker.

## Permission to route map

All route names below are relative to the `admin.` prefix. A listed GET
route also accepts HEAD. Resource updates accept PUT and PATCH.

| Ability | Route names and methods |
|---|---|
| `admin-dashboard` | GET `dashboard` |
| `content.manage` | GET `articles.index`, `articles.show`, `articles.edit`, `categories.index`, `categories.create`, `categories.show`, `categories.edit`, `sources.index`, `sources.create`, `sources.show`, `sources.edit`. POST `articles.bulk-destroy`, `categories.store`, `sources.store`. PUT/PATCH `articles.update`, `categories.update`, `sources.update`. PATCH `categories.toggle-active`, `sources.toggle-active`. DELETE `articles.destroy`, `categories.destroy`, `sources.destroy`. |
| `comments.manage` | GET `comments.index`. DELETE `comments.destroy`. |
| `moderation.manage` | GET `reports.index`, `reports.show`, `sanctions.index`, `sanctions.show`. POST `sanctions.store`, `sanctions.revoke`. PUT/PATCH `reports.update`, `sanctions.update`. |
| `operations.manage` | GET `processing.index`, `sources.health`. POST `processing.retry`, `sources.test`, `sources.retry`. |
| `stories.review` | GET `stories.index`, `stories.show`. POST `stories.accept`, `stories.reject`, `stories.retry`. |
| `users.manage` | GET `users.index`, `users.edit`. PUT/PATCH `users.update`. DELETE `users.destroy`. Target policy also applies to edit, update, and delete. |
| `super-admin` | GET `authorization.audit`. |

## File inventory

- New authorization module in `app/Authorization` contains `Permissions`,
  `AdminRoutes`, `UserAuthorization`, `SuperAdminGuard`, and `AuthorizationAudit`.
- New commands are `app/Console/Commands/RbacBootstrap.php` and
  `app/Console/Commands/RbacAudit.php`. The new audit controller and view are
  `app/Http/Controllers/Admin/AuthorizationAuditController.php` and
  `resources/views/admin/authorization/audit.blade.php`.
- Updated routing and registration in `routes/web.php`, `routes/console.php`,
  and `app/Providers/AppServiceProvider.php`. Updated account and enforcement
  paths in `app/Models/User.php`, `app/Models/Comment.php`,
  `app/ApiTokens/ApiTokenService.php`, and `app/Moderation/ModerationService.php`.
- Updated `app/Http/Controllers/ProfileController.php`, the public
  `CommentController.php`, and the admin `ArticleController.php`,
  `CategoryController.php`, `DashboardController.php`, and `UserController.php`.
- Updated Blade navigation and managed actions in
  `resources/views/admin/{articles/show,dashboard,partials/nav,sources/health,sources/index,sources/show,users/edit,users/index}.blade.php`
  and `resources/views/components/comment.blade.php`.
- Updated `database/seeders/UserSeeder.php`, `database/seeders/ModerationDemoSeeder.php`, and `docs/database/nosql-local.md`.
  Added `tests/Feature/AuthorizationTest.php` and adjusted the fixtures below.
  Updated `phpunit.xml` to force the Cassandra test keyspace.

## Existing test changes

| Files | Reason |
|---|---|
| `AdminArticleDefectsTest.php`, `CatalogArticlePagesTest.php` | Grant `content.manage` to article-management fixtures. |
| `ConfigMongoTest.php` | Grant content and, where needed, operations access to category and source fixtures. |
| `SourceFetchHistoryTest.php`, `SourceHealthMonitorTest.php` | Grant `operations.manage` to source-health and retry fixtures. The fetch-history fixtures also open source details and receive `content.manage`. |
| `StoryBriefTest.php` | Grant `stories.review` to the editorial fixtures. |
| `StoryPageTest.php`, `StoryPageProjectionsTest.php` | Grant `operations.manage` to processing dashboard and replay fixtures. |
| `ProSubscriptionTest.php` | Grant `users.manage` to user admin list and detail fixtures. |
| `ApiTokensTest.php` | Grant `users.manage` for the ordinary account deletion case and `moderation.manage` for sanctions and appeals. |
| `tests/Concerns/ModerationFixtures.php` | Supply the permissions used by shared moderation, comments, user-list, article, and processing scenarios. |
| `tests/TestCase.php` | Clear the authorization control and pending audit collections with the existing isolated MongoDB cleanup. |

The new `AuthorizationTest.php` walks the live admin router for every role
and single-permission Admin, checks writes and privilege escalation separately,
simulates a real MongoDB guard interleaving and failed user write, exercises
session and token revocation, and covers bootstrap, the fresh demo seeder, and
Cassandra failure and replay.

## Verification

Commands ran sequentially from the real repository. The runner supplied an
isolated environment for MongoDB `vietfeed_test`, Redis DB15, Cassandra
`vietfeed_test`, and `AI_PROVIDER=none`. It used temporary empty Laravel
environment overrides and removed them at exit.

| Command | Result | Exit |
|---|---|---:|
| `xargs -a /tmp/vietfeed-rbac-gate/pint-files-final vendor/bin/pint --test` | Passed on 46 changed or new PHP and Blade paths | 0 |
| `php artisan test <paths below>` | 212 passed, 1 failed, 6,385 assertions | 1 |
| `composer run test` | 569 passed, 11,452 assertions | 0 |
| `env -u CASSANDRA_KEYSPACE php artisan test tests/Feature/AuthorizationTest.php tests/Feature/ModerationEnforcementTest.php` | 23 passed, 1,538 assertions. PHPUnit selected `vietfeed_test` without the shell override. | 0 |

The targeted command selected these paths. The shared fixture and base test
class are loaded through the suites that use them.

```bash
php artisan test \
  tests/Feature/AdminArticleDefectsTest.php \
  tests/Feature/ApiTokensTest.php \
  tests/Feature/AuthorizationTest.php \
  tests/Feature/CatalogArticlePagesTest.php \
  tests/Feature/ConfigMongoTest.php \
  tests/Feature/ProSubscriptionTest.php \
  tests/Feature/SourceFetchHistoryTest.php \
  tests/Feature/SourceHealthMonitorTest.php \
  tests/Feature/StoryBriefTest.php \
  tests/Feature/StoryPageTest.php \
  tests/Integration/StoryPageProjectionsTest.php \
  tests/Feature/ModerationEnforcementTest.php
```

Logs and exit-code files are in `/tmp/vietfeed-rbac-gate`. The targeted
run's sole failure is the unchanged `assertDontSee($article->id)` in
`tests/Integration/StoryPageProjectionsTest.php`. The StoryLens template at
the branch base already renders that UUID in `id="article-…"`. This case is
outside the default Composer suites and outside the RBAC change scope. This
was the stop condition for repairing that assertion in this gate. The
RBAC-related projection retry case passed.

## Dev bootstrap for Control

1. After merge and deployment, pause write traffic and workers. Back up the
   MongoDB user and authorization control documents.
2. Against the intended dev stack, run `php artisan rbac:audit schema` and
   `php artisan rbac:bootstrap --super-admin=admin@vietfeed.com` or select
   another existing active account by email. Review the printed counts.
3. Sign in as the initial Super Admin. Verify the user edit controls and
   `/admin/authorization/audit`. Check the selected account's history with
   `php artisan rbac:audit --user=<mongo-user-id>`.
4. Resume writers and the scheduler. Verify the scheduler runs
   `rbac:audit retry`, or run it manually after Cassandra recovers.

## Follow-ups

- Gate 9.7b owns the account deletion and recovery lifecycle. Integrate its
  staff-removal entry points with the same membership protection and outbox.
- API endpoint scopes are outside this gate. API token families already
  honor the authorization epoch.
- The opt-in StoryLens projections test has a pre-existing assertion that
  rejects an article UUID anywhere in the page. The existing StoryLens
  evidence anchor intentionally contains that UUID. Review the assertion's
  scope in the StoryLens gate.
- Existing StoryLens and moderation feature boundaries still apply. This
  gate protects the existing routes. Track further editorial actions with
  the relevant future feature work.

## Fix round 1

- Ban issuance checks the current actor and target before a report claim and
  again before writing the sanction. Temporary and permanent bans of Admins
  require a Super Admin actor. Super Admin targets must be demoted first.
  Denying an appeal on an active staff ban follows the same rule. Approval
  and revocation can lift the existing ban. Refused bans and privilege
  escalation attempts enter the authorization audit. Audit failures preserve
  the escalation response's 403.
- First bootstrap reserves the initial account ID on the singleton guard and
  conditionally commits the completion marker against live, nonempty
  membership. The completion write updates the marker alone. A regression
  test simulates a demotion immediately before it commits.
- `rbac:repair` previews by default. `--user=<id> --force` clears markers
  older than 60 minutes using a versioned user update and a durable audit
  event. Clearing a deletion marker preserves the account in its current
  role. Clearing an unresolved ban requires maintenance mode and drained
  writers. `--guard --force` fences pending registrations with a new guard
  generation, blocks removal reservations, and version-fences any Super
  Admin it restores. The conditional guard update requires an active member
  and records reconciliation in its outbox.
- Audit retry uses a five-minute overlap lock and a new MongoDB index
  migration. It tracks each failed delivery, continues through the batch,
  and dead-letters an event after five failures. `rbac:audit dead` lists
  those events and `rbac:audit replay --event=<id>` requeues one.
  Audit assertions compare event presence or sets because the clustering
  timestamp comes from the application clock.
- Any role or grant reduction revokes API token families, including a
  Super Admin demoted with every Admin grant. Both Admin and profile email
  changes clear email verification and increment the API-token epoch in
  the same MongoDB write. The profile already cleared verification and
  needed the token revocation. A name-only edit preserves both.
- Web form conflicts flash a Vietnamese error. Self-demotion redirects to
  the admin dashboard for Admins and home for ordinary users. The user
  form restores submitted checkboxes, including an empty selection.
  `UserSeeder` invokes bootstrap with or without an attached seed command.

### Verification

The Docker readiness check polled every 30 seconds from 13:43:57 to
14:23:57 local time and reached the requested 40-minute cutoff.
`docker ps` still reported `docker: command not found` in WSL. The two Control
integration-test files matched their recorded checksums. Verification logs
are under `/tmp/vietfeed-rbac-fix1`.

| Check | Result | Exit |
|---|---|---:|
| Docker readiness poll | Timed out waiting for four healthy stores | 1 |
| Final `docker ps` | Docker CLI unavailable in WSL | 127 |
| `php -l` on the 16 PHP source and test files changed this round | Passed | 0 |
| `git diff --check` | Passed | 0 |
| `vendor/bin/pint --test` on changed files | Skipped at the Docker cutoff | — |
| `php artisan test` on targeted files | Skipped at the Docker cutoff | — |
| `composer run test` | Skipped at the Docker cutoff | — |
| `php artisan test tests/Integration` | Skipped at the Docker cutoff | — |
| `AuthorizationTest` repeat loop | 0 of 10 runs started | — |

## Fix round 2

- Audit retry counts malformed events at validation or encoding and stops
  immediately on a Cassandra projection error, including connection
  failures and unavailable tables. It preserves pending attempts during
  outages. Retry uses a 35-second work budget and a 45-second CLI alarm
  with PHP PCNTL, below the five-minute schedule lock.
- `rbac:audit dead --limit --page` lists dead letters in stable order.
  `rbac:audit replay --all --limit` clears dead-letter state for a bounded
  batch and only updates entries still matching the selected dead state.
  Single-event replay remains available.
- Guard repair audits target `super-admins`. The runbook explains how a
  stale `repair_token` blocks promotions and removals and how to recover it
  after the 60-minute threshold in maintenance mode.
- After an abandoned first bootstrap has stopped, `--clear-candidate=<id>`
  conditionally clears only the exact candidate marker of an incomplete
  bootstrap. The operator can then retry with another email. Committed
  roles and guard membership are preserved.
- NIT-3 goes to 9.7b. An Admin email change can still enable account
  takeover through password reset to the new address. Google OAuth can
  also mark the changed address verified by recognizing the linked Google
  ID. Clearing verification and revoking tokens on email change do not
  resolve those flows.
- NIT-5 is accepted at current scale. Each refused staff-account edit
  request, including GET, retains its own audit event.

### Verification

| Command | Result | Exit |
|---|---|---:|
| `php -l` on all six changed PHP files | Passed | 0 |
| `vendor/bin/pint` and `vendor/bin/pint --test` on those six files | Passed | 0 |
| `git diff --check` | Passed | 0 |

The regression coverage distinguishes connection loss and missing Cassandra
schema from poison events, verifies bounded replay and pagination, and
checks candidate replacement and the guard audit target. Control will
run the store-backed suites and the ten-run authorization loop when
Docker is available. Static verification logs are in `/tmp/vietfeed-rbac-fix2`.

## Control final gate (2026-11-23)

Docker restarted by owner; the four containers were brought up with `docker compose up -d` (volumes kept) and reported healthy.

| Command | Result |
| --- | --- |
| `composer run test` (first run) | exit 1: `NoSqlRuntimeTest::test_migrations_are_recorded_in_the_mongodb_migrations_collection` expected 10 migrations, found 11 (this gate's audit-index migration) |
| Control edit: `tests/Feature/NoSqlRuntimeTest.php` count 10 → 11 + `assertContains` for `2026_11_23_000001_create_mongodb_authorization_audit_indexes` | — |
| `composer run test` | exit 0, 581/581, 11 790 assertions |
| `php artisan test tests/Integration` | exit 0, 36/36 (includes control fixes for two Gate 9.6 regressions, committed separately) |
| `php artisan test --filter=AuthorizationTest` ×10 | 10/10 exit 0 (22 tests each) |
| `vendor/bin/pint --test` on changed/new PHP files | exit 0 (repo-wide `pint --test` still flags 8 pre-existing untouched files on `main`) |
| `git diff --check` | exit 0 |
| REVIEW-3 (Opus) | APPROVE, two NITs recorded below |

### Browser acceptance (Playwright, 390 px phone width, throwaway stores)

Server `php artisan serve --port=8091` with `MONGO_DB=vietfeed_live_check`, `CASSANDRA_KEYSPACE=vietfeed_live_check`, Redis DB14.
Fixture ingest → `storylens brief --all --sync` with live Gemini (`gemini-flash-lite-latest`): 2/2 Stories valid, 3 points, 3/5
evidence cited, 0 % invalid. Accounts: Super Admin (bootstrap), admin, user.

- Guest Story page: extractive brief "Tóm tắt trích từ nguồn" with citation links; timeline ready.
- Super Admin: `/admin/stories`, candidate review, **Duyệt** → public page shows "Tóm tắt AI, đã được biên tập viên duyệt".
- Super Admin edits admin: role select + 6 permission checkboxes; narrowed to `content.manage` + `stories.review` → saved.
- Demote the only Super Admin → refused flash "Phải giữ ít nhất một Super Admin"; Mongo still `super_admin`.
- `/admin/authorization/audit`: before provisioning the throwaway keyspace → "Nhật ký phân quyền hiện không khả dụng" (no 500);
  after `rbac:audit schema` + `rbac:audit retry` (Delivered: 4) → 4 rows (2 bootstrap, 1 permission change, 1 refused demotion).
- Limited admin nav: Dashboard, Nguồn tin, Duyệt tóm tắt Story, Chủ đề, Bài viết only; `/admin/users` → 403, `/admin/stories` → 200.
- Plain user `/admin/dashboard` → 403.

Cleanup: server stopped by exact PID; Mongo `vietfeed_live_check` dropped (name guard), Redis DB14 flushed (`getDbNum()===14`
guard), Cassandra keyspace `vietfeed_live_check` dropped. Dev intact: 3295 articles, 4 users, 13 comments.

### Follow-ups added by control

- REVIEW-3 NIT-1: classify only connection/timeout errors as audit outage, or validate `target`/`at` in `encode`.
- REVIEW-3 NIT-2: `rbac:bootstrap --clear-candidate` liveness fence and guard audit event.
- Audit page shows raw actor/target ids and raw JSON before/after; show names and a readable diff.
- `User::$casts['permissions' => 'array']` triggers the laravel-mongodb deprecation ("stores values as a JSON-encoded string");
  stored values observed native, but drop the cast or switch to a native-array cast to silence it.
- Refusal copy "Vui lòng thử lại nếu tài khoản vừa thay đổi" is generic for the last-Super-Admin case.
