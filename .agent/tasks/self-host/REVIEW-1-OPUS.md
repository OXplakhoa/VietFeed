# REVIEW-1 — self-host (Claude Opus 5.5, read-only)

## Verdict: REQUEST CHANGES (1 BLOCKER, 1 MAJOR, 7 MINOR, 5 NIT)

- **B1** `deploy/caddy/Caddyfile:3-6,57-61`, `env.demo.example:28`: Caddy (php_fastcgi = reverse_proxy) overwrites
  `X-Forwarded-Proto`/`X-Forwarded-For` from untrusted cloudflared with `http`/`127.0.0.1`; with `TRUSTED_PROXIES=127.0.0.1`
  Symfony `isSecure()` trusts that and ignores `HTTPS=on`. Result: http asset/form/redirect URLs (mixed content), signed
  verification links 403, every visitor = 127.0.0.1 (shared lockouts). Fix: `servers { trusted_proxies static 127.0.0.1/32 ::1/128 }`,
  backstop `header_up X-Forwarded-Proto https`; health check greps https asset URLs.
- **M1** `Caddyfile:5`: `admin off` breaks `systemctl reload caddy` after the first reload. Remove it (default admin is localhost).
- **Minor:** stale/overwritten passwords in `demo-reset.sh:152-171`; `Environment=MONGO_PORT` in units diverges from FPM (rename to
  `WAIT_MONGO_PORT`); `bind ::1` fails without IPv6 loopback; store credentials must equal the running containers'; shared Redis
  `allkeys-lru` eviction; `MAIL_MAILER=log` leaves new visitors unverified; `key:generate --force`.
- **NIT:** host-only `SESSION_DOMAIN`; top-level dotfile matcher; banner dark mode; `APP_ENV=demo` would load `.env.demo`; unit
  hardening.
- Verified OK: reset guard + wipe, command names/order, Redis DB coverage, trustedproxy config, CSRF, OAuth route, no redirect loop,
  FPM pool/socket perms, units, runbook order, worktree, banner off by default.
