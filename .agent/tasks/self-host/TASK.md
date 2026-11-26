# Self-host the public demo at https://vietfeed.app (owner PC, WSL + Cloudflare Tunnel)

Branch `deploy/self-host`, base `main`. **Control stops before merging (owner approval).**

## Context (control, 2026-11-26)

- Owner bought `vietfeed.app` (name.com). `.app` is HSTS-preloaded: HTTPS only; Cloudflare terminates TLS.
- Hosting: the owner's Windows PC, WSL2 Ubuntu 24.04 distro `VietFeed` (`systemd=true`), Docker Desktop with the four stores from
  `docker-compose.yml` (ports bound to 127.0.0.1). PHP 8.4 from the ondrej PPA (CLI has `mongodb`, `redis`, `pcntl`, `opcache`).
- Owner installs with sudo: `php8.4-fpm`, `caddy` (official apt repo), `cloudflared` (official apt repo). The tunnel is created in
  the Cloudflare dashboard; the owner runs `sudo cloudflared service install <token>` (token never in chat/files). Public hostname
  `vietfeed.app` → `http://localhost:8080`.
- Dev keeps working in `/home/dolakhoa/code/VietFeed` with Mongo `vietfeed`, Redis DB 0/1/2, Cassandra `vietfeed`. The demo must
  never touch those.

## Required outcomes (files only; nothing installed or started by the implementer)

1. `deploy/README.md` — the runbook, in Vietnamese with English command blocks: architecture (Cloudflare → cloudflared → Caddy
   :8080 → php-fpm → Laravel in `~/vietfeed-demo` → Docker stores), one-time setup, update procedure (`git -C ~/vietfeed-demo pull`
   + `composer install --no-dev` + `php artisan migrate --force` + caches + `npm ci && npm run build` + restart), start/stop, health
   check, logs, reset, auto-start on Windows boot (Docker Desktop "start when you sign in"; a Windows Task Scheduler entry that
   starts the WSL distro at logon, e.g. `wsl.exe -d VietFeed --exec /bin/true` plus keeping it alive — research and document the
   reliable approach for WSL2 with systemd services, and say clearly what is uncertain), disable Windows sleep, security checklist.
2. Demo checkout as a git **worktree** of `main` at `~/vietfeed-demo` (document the `git worktree add` command; do not run it).
3. `deploy/env.demo.example` — production-style env with NO secrets (placeholders): `APP_ENV=production`, `APP_DEBUG=false`,
   `APP_URL=https://vietfeed.app`, `APP_LOCALE=vi`, `LOG_LEVEL=warning`, `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN=vietfeed.app`,
   trusted proxies for Cloudflare/localhost (check how this Laravel 13 app configures `trustProxies` in `bootstrap/app.php`; if
   a code change is needed for correct HTTPS URLs behind the tunnel, make the minimal, env-driven change and test it),
   `MONGO_DB=vietfeed_demo`, distinct Redis DBs for default/cache/nosql (e.g. 5/6/7 — verify every Redis connection in
   `config/database.php`, cache, queue, session and the nosql connection), `CASSANDRA_KEYSPACE=vietfeed_demo`, `AI_PROVIDER` gemini
   or none (key placeholder), Stripe TEST keys placeholders only, `MAIL_MAILER=log`, Google OAuth optional with the callback
   `https://vietfeed.app/auth/google/callback` (verify the real route path).
4. `deploy/caddy/Caddyfile` — listen `:8080` (bind localhost only), root `~/vietfeed-demo/public`, `php_fastcgi` to the php8.4-fpm
   socket, `encode gzip`, static file caching, security headers that do not break the app, request body limit, access log.
5. `deploy/php-fpm/vietfeed-demo.conf` — a dedicated pool running as `dolakhoa` with sane `pm` limits for a home PC, plus the
   OPcache/production php.ini overrides for FPM only.
6. `deploy/systemd/` — `vietfeed-demo-queue.service` (queue:work on the redis connection with `--max-time`, restart always),
   `vietfeed-demo-scheduler.service` (`schedule:work`; exactly one), with `WorkingDirectory=~/vietfeed-demo`, `User=dolakhoa`,
   `After=docker.service network-online.target` (note Docker Desktop is not a systemd unit in WSL: add an `ExecStartPre` wait-for-
   stores script instead). Install commands documented (owner runs with sudo).
7. `deploy/demo-reset.sh` — idempotent, guarded setup/reset of the demo stores ONLY: refuses unless the loaded env has
   `MONGO_DB=vietfeed_demo`, `CASSANDRA_KEYSPACE=vietfeed_demo` and the demo Redis DB numbers (check through
   `php artisan tinker --execute` with `< /dev/null`, never by parsing `.env` contents into output); steps: migrate --force, all
   schema commands (storylens, interactions, reading-pass, sources history, moderation audit, rbac audit — verify names with
   `php artisan list`), seed Categories (and the default Sources via the committed seeder on `main`, which is the demo checkout),
   `storylens ingest`, `storylens consume` both sections, optional brief generation, `demo:synthetic seed`, create three demo
   accounts (reader, admin with limited permissions, super admin) with emails `*@vietfeed.app` and passwords read from env vars or
   generated and written to a file with mode 600 (never printed). A `--wipe` flag that drops ONLY the demo stores (Mongo
   `vietfeed_demo`, the demo Redis DBs via `getDbNum()` guard, Cassandra keyspace `vietfeed_demo`) behind an interactive
   confirmation. Neo4j is a single shared database in Community edition: document it; the script must not delete Neo4j data.
8. `deploy/health.sh` — read-only checks: stores up, php-fpm socket, Caddy on :8080, queue/scheduler units active, an HTTP 200 from
   `http://localhost:8080/` and `/up` if the route exists, and (optional flag) the public URL.
9. Public-demo hardening in the app only if needed and small (env-driven): registration/login rate limits already exist? verify and
   document; a visible "Demo — Stripe test mode, data may be reset" banner when an env flag like `DEMO_MODE=true` is set (Blade
   layout, Vietnamese copy), off by default. One feature test for the banner flag; nothing else new.

## Hard rules

Do not install packages, start/stop services, run `cloudflared`, create the worktree, or run `demo-reset.sh` — the owner and
control do that. Never read, print or edit any `.env` file (only the new `deploy/env.demo.example`). Never touch dev data. Tests only
via `composer run test` on isolated stores (if the stores are down, report it and skip). No new Composer/npm dependencies. Owner WIP
untouched: `CLAUDE.md`, `database/seeders/SourceSeeder.php`, `.agent/tasks/{infra-cassandra-spike,mongo-foundation,
neo4j-foundation,redis-cutover,claude-code-handoff}/`, `.agent/handoff/`, `.agent/migration-pc/`, `.agent/control/`, `.pi/`,
`docs/agents/`, `pi-warden.md`. Do not commit.

Note: `database/seeders/SourceSeeder.php` has uncommitted owner edits in the dev checkout; the demo worktree uses the committed
version on `main`. Mention this in the runbook.
