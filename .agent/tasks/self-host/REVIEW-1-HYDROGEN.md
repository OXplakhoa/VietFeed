<!-- Read-only security review by Gemini 4 Hydrogen (agy, plan mode), 2026-11-26. -->
## Verdict: **NO-GO (2 BLOCKER, 2 MAJOR, 1 MINOR, 3 NIT)**

Fix the two **BLOCKER** issues (proxy client-IP collapse in Caddy and missing edge rate limits) before pointing `vietfeed.app` at the PC.

---

### BLOCKER-1: Caddy strips `cloudflared` proxy headers, collapsing all client IPs to `127.0.0.1`
- **Location:** [Caddyfile:57-61](deploy/caddy/Caddyfile#L57-L61), [trustedproxy.php:7-9](config/trustedproxy.php#L7-L9), [env.demo.example:28](deploy/env.demo.example#L28), [TokenController.php:22-24](app/Http/Controllers/Api/TokenController.php#L22-L24), [LoginRequest.php:82-84](app/Http/Requests/Auth/LoginRequest.php#L82-L84)
- **Attack steps:** `cloudflared` connects to Caddy over `127.0.0.1:8080`, sending `CF-Connecting-IP` and `X-Forwarded-For`. Because `Caddyfile` sets no `trusted_proxies`, Caddy treats `127.0.0.1` as untrusted and replaces `X-Forwarded-For` with `127.0.0.1` when calling `php-fpm`. Laravel's `TrustProxies` (`TRUSTED_PROXIES=127.0.0.1,::1`) strips `127.0.0.1` and resolves `$request->ip()` to `127.0.0.1` for every Internet user. One attacker sending 30 failed `POST /api/v1/auth/login` requests/min exhausts the shared `api-login:ip:` bucket and locks out **all** API logins globally (`TokenController.php:24`). Web login throttling (`email|127.0.0.1` in `LoginRequest.php:84`) similarly lets an attacker lock out known accounts globally across all IPs, and all logs lose real client IPs.
- **Fix (config-only):** In [Caddyfile](deploy/caddy/Caddyfile#L3-L6) global options, trust loopback `cloudflared` and read Cloudflare's authoritative header:
  ```caddyfile
  servers {
  	trusted_proxies static 127.0.0.1 ::1
  	client_ip_headers CF-Connecting-IP
  }
  ```

### BLOCKER-2: Unthrottled CPU/DB/API-heavy auth and public routes on a 6-worker home PC
- **Location:** [routes/auth.php:19-42](routes/auth.php#L19-L42), [routes/api.php:8](routes/api.php#L8), [routes/web.php:57,94](routes/web.php#L57), [vietfeed-demo.conf:18](deploy/php-fpm/vietfeed-demo.conf#L18), [env.demo.example:14](deploy/env.demo.example#L14), [HomeController.php:30-37](app/Http/Controllers/HomeController.php#L30-L37), [TickerService.php:33-80](app/Services/TickerService.php#L33-L80)
- **Attack steps:** `POST /register` (runs `BCRYPT_ROUNDS=12`, inserts a Mongo user, writes verification mail to `laravel-*.log`), `POST /forgot-password` (for unknown emails), `POST /reset-password`, `GET /auth/google/redirect|callback` (outbound Google HTTPS call), and `POST /api/v1/auth/refresh` have no route/IP throttle. `POST /login` lacks a per-IP ceiling across rotating emails. `GET /` loads all `Article` documents with non-null `image_url` + 3 Mongo `$group` queries uncached; `GET /api/ticker` triggers synchronous 8s upstream HTTP calls (`EXCHANGERATE_API_KEY`) whenever primary cache is missing/failed; `POST /billing/checkout` (unthrottled for Google-verified users via [GoogleAuthController.php:79](app/Http/Controllers/Auth/GoogleAuthController.php#L79)) calls Stripe API per hit. Even low-rate concurrent requests saturate `pm.max_children = 6` (DoS), grow Mongo/log disk, and burn upstream API quotas.
- **Fix (Cloudflare-side):** Add Cloudflare WAF Rate Limiting rules (e.g., 5–10 req/min/IP) and/or Managed Challenge/Turnstile on `/register`, `/login`, `/forgot-password`, `/reset-password`, `/auth/google/*`, `/api/v1/auth/*`, `/billing/checkout`, and `/api/ticker`; add a tighter rate limit or Cloudflare cache/challenge rule for unauthenticated bursts on `/`.

### MAJOR-1: Shared 200 MB `allkeys-lru` Redis mixes sessions, queues, reset tokens, and rate limiters
- **Location:** [docker-compose.yml:107](docker-compose.yml#L107), [env.demo.example:31-54](deploy/env.demo.example#L31-L54), [config/auth.php:100-101](config/auth.php#L100-L101), [config/cache.php:73-77](config/cache.php#L73-L77)
- **Attack steps:** Demo and dev share one Redis instance capped at `200mb` with `allkeys-lru`. Unauthenticated requests to `/login` or `/` allocate Redis session keys in DB 5 (`SESSION_CONNECTION=default`), which also stores queue jobs and locks, while DB 6 stores `RateLimiter` counters and password-reset tokens (`auth.php:100-101`). Flooding session creation or cache fills 200 MB and triggers LRU eviction of rate-limit counters (bypassing brute-force throttling), active sessions, reset tokens, and queued jobs.
- **Fix (config-only):** Run a dedicated demo Redis instance (or separate container) with `--maxmemory-policy noeviction` for sessions/queues/rate-limits/reset-tokens, and enable Cloudflare Bot Fight Mode to drop automated session-creating traffic at the edge.

### MAJOR-2: `/admin/*` publicly exposed with RSS SSRF and optional Gemini trigger
- **Location:** [routes/web.php:115-149](routes/web.php#L115-L149), [demo-reset.sh:201-216](deploy/demo-reset.sh#L201-L216), [StoreSourceRequest.php:19](app/Http/Requests/Admin/StoreSourceRequest.php#L19), [FeedIngestionService.php:144](app/Services/Rss/FeedIngestionService.php#L144), [StoryBriefController.php:75](app/Http/Controllers/Admin/StoryBriefController.php#L75), [deploy/README.md:229](deploy/README.md#L229)
- **Attack steps:** `/admin/*` is exposed to the Internet with predictable demo emails (`admin@vietfeed.app`, `superadmin@vietfeed.app`), and Cloudflare Access is listed only as optional (`README.md:229`). If `admin@vietfeed.app` (granted `content.manage` and `stories.review`) is ever shared or compromised, `StoreSourceRequest` accepts any `url` without private-IP blocking—allowing `FeedIngestionService` to fetch loopback/internal services (e.g., `127.0.0.1:7474` Neo4j HTTP)—and `admin.stories.retry` dispatches Gemini brief jobs if `AI_PROVIDER=gemini`.
- **Fix (Cloudflare-side + config):** Gate `/admin` and `/admin/*` behind **Cloudflare Access** (Zero Trust email/OTP policy restricted to the owner), keep `AI_PROVIDER=none` (`env.demo.example:101`), and never share `admin@`/`superadmin@` credentials.

### MINOR-1: No `Content-Security-Policy` header and no SRI hashes on third-party CDN assets
- **Location:** [Caddyfile:37](deploy/caddy/Caddyfile#L37), [app.blade.php:27-28,346](resources/views/layouts/app.blade.php#L27-L28), [guest.blade.php:20-21,56](resources/views/layouts/guest.blade.php#L20-L21)
- **Attack steps:** `Caddyfile:37` omits CSP, and CDN Bootstrap/Icon `<script>`/`<link>` tags lack `integrity` (SRI) attributes. Any XSS or `cdn.jsdelivr.net` compromise executes unrestricted JS in user/admin sessions.
- **Fix (config-only):** Add a baseline CSP in [Caddyfile](deploy/caddy/Caddyfile#L29-L38) (`default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; img-src 'self' https: data:`), and add SRI hashes when touching layouts.

### NITs (config-only hardening)
1. **Cookie scope & Redis session encryption ([env.demo.example:34,36](deploy/env.demo.example#L34-L36)):** Set `SESSION_DOMAIN=` (empty/host-only instead of `vietfeed.app`) and `SESSION_ENCRYPT=true` so sessions are not readable in plaintext inside shared Redis.
2. **Home-directory traverse permission ([deploy/README.md:102](deploy/README.md#L102)):** Use `sudo setfacl -m u:caddy:x /home/dolakhoa` instead of `chmod o+x /home/dolakhoa`.
3. **Nested dotfile rule ([Caddyfile:41-45](deploy/caddy/Caddyfile#L41-L45)):** `path /.*` matches top-level dotfiles only; `path */.*` with `not path /.well-known/*` also blocks any nested dotfiles (verify `/build/.vite/` is not fetched by browser).

---

### Verified Clean (no issues found)
- **DB & origin exposure:** `docker-compose.yml` binds Mongo/Cassandra/Neo4j/Redis to `127.0.0.1`; `Caddyfile` sets `bind 127.0.0.1 ::1` and `admin off`; no `0.0.0.0` listeners in deploy configs.
- **Exposed paths / debug tools / uploads:** No Telescope/Pulse/Horizon/Ignition in `composer.json`; `APP_DEBUG=false`; `php-fpm` sets `security.limit_extensions = .php` and `expose_php = Off`; `10M`/`12M` upload caps with no file-upload routes.
- **Stripe webhook & cookies & passwords & Mongo `LIKE`:** `BillingGateway::receive` verifies HMAC fail-closed and re-fetches subscriptions from Stripe; cookies enforce `Secure`, `HttpOnly`, `SameSite=Lax`; demo accounts use 20-char `/dev/urandom` passwords in mode `600` files; `laravel-mongodb` `Builder` calls `preg_quote()` on `LIKE` queries.
