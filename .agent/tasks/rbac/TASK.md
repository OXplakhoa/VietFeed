# Gate 9.7a — RBAC: Super Admin, fixed admin permissions, audit

Canonical sources: `docs/specs/vietfeed-nosql-storylens.md` §7 stories 19, 20, 21, 22, 23, 24, 25; Authorization Module ("fixed roles,
permission catalog, Laravel Gates/Policies, Super Admin invariants, and authorization audit emission"); §13 item 3 ("Seed ...
permissions, and initial Super Admin"); acceptance "RBAC and Super Admin invariants are enforced server-side".
`docs/architecture/database-feature-map.md`: "RBAC Super Admin/Admin/User | MongoDB | Cassandra audit | Permission hiện hành cần đọc
chính xác mỗi request; thay đổi quyền cần lịch sử append-only"; "Không lưu permission chỉ trong session/cache để việc revoke có hiệu lực
ngay"; "Permission check không nên phụ thuộc Redis cache".

One gate, one branch, one PR: branch `rbac/permissions` (9.7b account lifecycle follows in its own branch/PR), base
`main` `bcdcbba`. **Control stops before merging (owner approval).**

## Recon facts (control, 2026-11-23)

- `User.role` is `user|admin`; `User::isAdmin()`; `AdminMiddleware` (alias `admin`) guards the whole `/admin` group in `routes/web.php`.
  Only one Gate exists: `review-story-brief` (`AppServiceProvider`, `isAdmin()`), used as `can:review-story-brief` on `/admin/stories`.
- **Current privilege hole:** `Admin\UserController::update` lets ANY admin set any user's `role` (incl. promoting to admin, demoting
  other admins); `destroy` lets any admin hard-delete any other user, including admins. Only self-delete is refused.
- Admin areas: dashboard, processing (+retry), stories (brief review), articles (+bulk destroy), sources (+health/test/retry/toggle),
  categories, comments (index/destroy), users (index/edit/update/destroy), reports, sanctions (+revoke).
- Sessions are in Redis; API tokens are revoked via `ApiTokenService::revokeAllForUser` / `api_token_epoch` (also used by Sanction).
  Cassandra audit patterns exist (`ModerationAudit`, `CassandraClient`, idempotent inserts, DLQ/stream where needed).
- `database/seeders/UserSeeder.php` creates `admin@vietfeed.com` with role admin. Dev has 4 users.

## Control decisions (owner may override at PR review)

- Roles: `super_admin`, `admin`, `user`. Fixed permission catalog (constants, not editable data), e.g.:
  `content.manage` (categories, sources CRUD/toggle, articles edit/delete/bulk), `comments.manage` (admin comments index/destroy),
  `moderation.manage` (reports, sanctions, revoke, appeals), `operations.manage` (processing page + retry, source health/test/retry),
  `stories.review` (brief review), `users.manage` (users index/edit name/email of plain users; never roles/permissions; never
  delete or edit admins/super admins). Dashboard: any admin. Refine names if a better split falls out of the code, document it.
- Super Admin implicitly has every permission and alone can: change roles, grant/revoke permissions, edit/delete admins.
- Current permissions live on the Mongo User document (`permissions: []`) and are read fresh per request (no cache, no session copy).
- Invariant: at least one active Super Admin always exists. Demoting/deleting the final Super Admin is refused server-side, and
  concurrent demotions of two different Super Admins must not leave zero (MongoDB standalone, no transactions: design a single-
  document guard, e.g. a control document holding the Super Admin id set updated with a conditional `$pull` only when size > 1, then
  the user write, with a documented repair path if the second write fails). A Super Admin cannot demote or delete themself if they are
  the last one; self-demotion otherwise allowed (decide and document).
- Migration of existing data: an idempotent command `rbac:bootstrap --super-admin=<email>` (refuses unknown email; idempotent; prints
  counts) promotes the initial Super Admin; existing `admin` users get ALL permissions on first bootstrap so nothing breaks for them
  (documented). `UserSeeder` makes `admin@vietfeed.com` the Super Admin for fresh installs (UserSeeder is not owner WIP; SourceSeeder
  is). Dev is NOT changed by the implementer; control runs the command on dev after merge.

## Required outcomes

1. Authorization module (`App\Authorization\...`): role/permission catalog, Laravel Gates for each permission (+ `super-admin`), a
   single place mapping routes/actions to abilities. Every `/admin` route and every admin POST/PATCH/DELETE action is protected by its
   ability server-side (route middleware `can:` and/or policies); keep `admin` middleware as the coarse "is staff" check. Navigation
   shows only links the user can use. Existing `review-story-brief` repointed to `stories.review`.
2. Super Admin UI: `/admin/users/{user}/edit` shows role and permission checkboxes only to Super Admins; plain admins with
   `users.manage` can edit name/email of plain users only. Server-side validation rejects unknown permissions, permission sets on
   plain users, role changes by non-super-admins (403), and any edit/delete of a Super Admin by an admin.
3. Invariants and revocation: final Super Admin protection (demote/delete) including the concurrency case above; a role/permission
   change takes effect on the user's next request (no cached copy); demotion to `user` or permission revocation also revokes that
   user's API token families (`revokeAllForUser`) — Blade sessions keep working but lose access on the next request because checks read
   Mongo. Deleting users stays as today for plain users (account lifecycle with 30-day recovery is 9.7b; do not redesign deletion
   here beyond the authorization rules).
4. Audit: every role/permission change and every refused attempt to break an invariant is appended to Cassandra
   (`authorization_audit_by_user` partition by target user, and a bounded global recent table by day bucket; UUIDv7 event ids;
   actor, target, before, after, reason, at). Cassandra failure must not fail the change (log + DLQ or retry pattern used elsewhere;
   justify). A Super Admin page `/admin/authorization/audit` (recent 50) and `php artisan rbac:audit --user=` show it; Cassandra down →
   "audit unavailable" state, not 500.
5. `rbac:bootstrap` as above; `rbac:grant`/`rbac:revoke`/`rbac:role` artisan commands for operators are optional (skip if not
   needed; never bypass invariants).
6. Tests on isolated stores (Mongo `vietfeed_test`, Redis DB15 guard, Cassandra `vietfeed_test`): a matrix test that walks EVERY admin
   route (GET + mutating) for guest / user / admin-with-no-permissions / admin-with-each-permission / super admin and asserts
   allow/deny (derive the route list from the router so new routes cannot be forgotten; fail if an `/admin` route has no mapped
   ability); privilege escalation attempts (admin posting `role`/`permissions`, editing/deleting a super admin, mass-assignment);
   final-super-admin protection incl. simulated concurrency; revocation effective on next request and API tokens revoked; audit rows
   written and Cassandra-down tolerance; bootstrap idempotency. Existing tests updated only where they relied on "any admin can do
   everything" (list them with reasons). Full `composer run test` green.
7. Docs: `docs/database/nosql-local.md` (roles, permissions, Mongo fields, Cassandra tables, commands, bootstrap on dev),
   `GATE-REPORT-9.7a.md` in this folder (decision log, permission→route table, verification commands + exit codes, follow-ups).

## Hard rules / isolation

Never touch dev data (Mongo `vietfeed`, Redis DB0/1/2, Cassandra `vietfeed`); no FLUSHALL, no migrate/seed/new commands against dev, no
`migrate:fresh`/`db:wipe`, no container starts/restarts. Never read, print or edit the active `.env`. Keep the forced AI lines in
`phpunit.xml`. Sequential suites. Never edit existing migrations. No new Composer/npm dependencies (no spatie/permission). Owner WIP
untouched: `CLAUDE.md`, `database/seeders/SourceSeeder.php`, `.agent/tasks/{infra-cassandra-spike,mongo-foundation,neo4j-foundation,
redis-cutover,claude-code-handoff}/`, `.agent/handoff/`, `.agent/migration-pc/`, `.agent/control/`, `.pi/`, `docs/agents/`,
`pi-warden.md`. Do not commit; control commits.

## Non-goals

Account deletion lifecycle (9.7b), API scopes for `/api/v1`, UI redesign beyond the user edit form, audit page and nav filtering.

## Stop conditions

Product change beyond the decisions above; owner WIP edit; need to change the active `.env`; failure not fixable in scope; container
restart or OOM; any need for a new dependency.
