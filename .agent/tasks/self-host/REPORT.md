# Self-host demo: implementation report

Branch `deploy/self-host`. Nothing installed, started, committed or run against stores. No `.env` read.

## Decisions
- Trusted proxies: new `config/trustedproxy.php` (`proxies => env('TRUSTED_PROXIES')`). Laravel 13's `TrustProxies` falls back to that config key, and the
  app had no setting at all. Config (not `env()` in `bootstrap/app.php`) so it survives `config:cache`. Default null = trust nobody; demo sets `127.0.0.1,::1`.
  Caddy additionally passes `env HTTPS on` to php-fpm so URLs/secure cookies are HTTPS even if `X-Forwarded-Proto` were lost.
- Redis DBs: `default` (sessions via `SESSION_CONNECTION=default`, queue via `REDIS_QUEUE_CONNECTION` default, cache lock connection) = `REDIS_DB=5`;
  `cache` = `REDIS_CACHE_DB=6`; `nosql` = `REDIS_NOSQL_DB=7`. Verified in config/database.php, cache, queue, session, nosql. `REDIS_URL` must stay unset (it overrides `/db`).
- `demo:synthetic` refuses `APP_ENV=production`. `demo-reset.sh` runs only that command with `env APP_ENV=demo`, after the store guard passed and with config cache cleared.
- Demo accounts: super admin via `rbac:bootstrap`, limited admin via `UserAuthorization::edit` (content.manage, comments.manage, stories.review), reader plain.
- Worktree: branch `demo-live` tracking `origin/main` (Git forbids two worktrees on `main`); update = `git pull --ff-only`.
- Banner: `config('app.demo_mode')` from `DEMO_MODE` (default false), anonymous component `x-demo-banner`, inline styles (no CSS rebuild), in both layouts.
- No new throttle added (registration has none); documented, with a Cloudflare rate-limit recommendation.
- Mongo host port defaults to 27018 in health/wait scripts and the units (owner PC); editable.

## Files
New: `deploy/README.md`, `deploy/env.demo.example`, `deploy/demo-reset.sh`, `deploy/health.sh`, `deploy/caddy/Caddyfile`,
`deploy/php-fpm/vietfeed-demo.conf`, `deploy/php-fpm/99-vietfeed-demo.ini` (FPM-only OPcache/prod ini),
`deploy/systemd/{vietfeed-demo-queue.service,vietfeed-demo-scheduler.service,wait-for-stores.sh}`,
`config/trustedproxy.php`, `resources/views/components/demo-banner.blade.php`, `tests/Feature/DemoBannerTest.php`.
Modified: `config/app.php` (`demo_mode`), `resources/views/layouts/app.blade.php`, `resources/views/layouts/guest.blade.php`.

## Verified
- `bash -n` on demo-reset.sh, health.sh, wait-for-stores.sh: exit 0. Embedded PHP (guard, wipe, accounts) extracted and `php -l`: clean.
- `php -l` on changed PHP: clean. `vendor/bin/pint` then `--test`: passed.
- Banner component rendered through `Blade::render` in tinker (no stores needed); `view:cache` compiles all views (exit 0), then `view:clear`.
- `demo-reset.sh` run in the dev checkout with stores down: guard refused with exit 1 before any change (it only ran `config:clear`).
- Test suite NOT run: Docker/stores are not reachable here (127.0.0.1:6379/27017/27018 refused; no `docker` binary). `DemoBannerTest` is therefore unexecuted.
- Caddy/php-fpm/systemd not installed here: configs reviewed by hand only (`caddy validate`, `php-fpm8.4 -t`, `systemd-analyze verify` not run).

## Owner must run
1. `composer run test` with stores up (expect existing suite + `DemoBannerTest`).
2. Follow `deploy/README.md` section 2; run `sudo caddy validate`, `sudo php-fpm8.4 -t`, `systemd-analyze verify` on the units.
3. First `deploy/demo-reset.sh` (watch the accounts step and `rbac:bootstrap`), then `deploy/health.sh --public`.
4. Reboot test for Windows auto-start (section 8).

## Open risks
- Account step (`UserAuthorization::edit` as the new super admin) and `demo:synthetic` under `APP_ENV=demo` are untested against live stores.
- Caddy directives (`php_fastcgi { env }`, `request_body`, `roll_keep_for`, `bind 127.0.0.1 ::1`) need Caddy >= 2.7; `::1` bind fails if IPv6 loopback is off (drop it then).
- Caddy user needs traverse access to `/home/dolakhoa` (documented chmod/ACL).
- Dev and demo share Redis/Mongo/Cassandra/Neo4j containers: a store outage or memory pressure (Redis `allkeys-lru`, 200 MB) hits both; Neo4j data is shared.
- WSL keep-alive and Windows boot behaviour are uncertain (documented in README section 8).
- Registration/Google login have no throttle in-app.

## Fix round 1
| Finding | Change |
|---|---|
| Opus B1 / Hydrogen B1 (client IP, https URLs) | Caddyfile global `servers { trusted_proxies static 127.0.0.1/32 ::1/128; client_ip_headers CF-Connecting-IP }`; `header_up X-Forwarded-Proto https` in `php_fastcgi`; bind `127.0.0.1` only; `TRUSTED_PROXIES=127.0.0.1`; README 2.7 explains the IP flow; `health.sh` checks `/login` has `https://vietfeed.app/build/`. |
| Opus M1 | `admin off` removed. |
| Hydrogen B2 | Built-in `throttle` with per-route prefixes: register 10/min, forgot-password 6, reset-password 6, Google redirect+callback 10 (shared), API refresh 10 (`routes/auth.php`, `routes/api.php`). `RegistrationTest::test_new_users_can_register` extended: 11th attempt returns 429. Documented in README 2.8. |
| Cloudflare hardening | README 2.9: Bot Fight Mode, one rate-limit rule (free-plan limit stated), Access (email OTP) for `/admin*`, `AI_PROVIDER=none` default and why. |
| Hydrogen M1 + Opus minor (Redis) | `deploy/docker-compose.demo.yml`: `vietfeed-demo-redis` on 127.0.0.1:6380, 256mb, noeviction, own volume, password from demo `.env`. Env example uses port 6380; DB numbers stay 5/6/7. Guard in `demo-reset.sh` also requires port 6380; `health.sh` and `wait-for-stores.sh` default to 6380. |
| Opus minors | Credentials logic rewritten (file sourced first, env overrides, generated/supplied/absent file forces re-hash, file always rewritten 600); units use `WAIT_MONGO_PORT` read only by the wait script; README/env state MONGO/NEO4J creds must equal the dev containers'; `key:generate --force`; unverified sign-ups documented (registration kept open; banner copy tells visitors to use the reader account). |
| Hydrogen CSP | `Content-Security-Policy-Report-Only` header added; README 2.10 on switching to enforcing. |
| NITs | `SESSION_DOMAIN=null` (host-only), `SESSION_ENCRYPT=true`, `setfacl -m u:caddy:x`, units get `NoNewPrivileges`, `ProtectSystem=full`, `PrivateTmp` (storage/ in /home stays writable), `.env.demo` / `APP_ENV=demo` warning in README and env example, banner uses explicit high-contrast colours (readable in dark theme). Nested-dotfile matcher not changed (not on the fix list). |

Verification: `bash -n` on the three scripts (exit 0); `php -l` and pint (`--test` passed) on changed PHP; guard heredoc PHP re-linted. Test suite: see final message.

## Fix round 2
- `health.sh`: local curls (`/`, `/up`, `/login`) send `Host: vietfeed.app`; the https asset grep is kept.
- Queue unit: comment lines restored, stray line removed; scheduler unit checked, it was clean.
- Caddyfile: `header_up X-Forwarded-For {client_ip}`; README 2.7 step 3 reworded.
- README: CSP enforcing needs `'unsafe-eval'` (Alpine 3) or `@alpinejs/csp`; alphanumeric Redis password and `docker inspect` visibility; `--reset-passwords` always regenerates, ignoring supplied env passwords.
- `demo-reset.sh` matches that doc: `RESET_PASSWORDS` already blanked all three before generation (the earlier code ran this after the env overrides), so supplied values are discarded.
- Verified: `bash -n` on the three scripts, exit 0. Caddy not run.
