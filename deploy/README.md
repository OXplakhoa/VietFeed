# VietFeed demo công khai — https://vietfeed.app (self-host trên PC của chủ dự án)

Runbook vận hành. Văn bản bằng tiếng Việt, lệnh bằng tiếng Anh. Các lệnh `sudo` do chủ máy tự chạy; không bao giờ dán
token, mật khẩu hay nội dung `.env` vào chat hoặc commit.

## 1. Kiến trúc

```
Trình duyệt ──HTTPS──> Cloudflare (TLS, DDoS, cache edge)
                          │  Cloudflare Tunnel (kết nối đi ra từ PC, không mở cổng vào)
                          ▼
                    cloudflared (systemd, WSL)
                          │ http://127.0.0.1:8080
                          ▼
                    Caddy :8080 (chỉ loopback)  ── file tĩnh /build, /favicon...
                          │ FastCGI unix socket /run/php/vietfeed-demo.sock
                          ▼
                    php8.4-fpm, pool "vietfeed-demo" (user dolakhoa)
                          ▼
                    Laravel 13 tại ~/vietfeed-demo  (git worktree của main)
                          ▼
        Docker Desktop (127.0.0.1): Redis demo :6380 · MongoDB · Cassandra · Neo4j (dev)
        + 2 dịch vụ systemd: vietfeed-demo-queue, vietfeed-demo-scheduler
```

- Phát triển vẫn ở `/home/dolakhoa/code/VietFeed` (Mongo `vietfeed`, Redis DB 0/1/2, Cassandra `vietfeed`). Demo **không bao giờ**
  dùng các tên đó.
- Demo dùng **container Redis riêng** (cổng 6380) và **chung** container Mongo/Cassandra/Neo4j với dev, tách dữ liệu bằng tên: Mongo `vietfeed_demo`, Cassandra keyspace
  `vietfeed_demo`, Redis DB 5 (default: session, queue, lock) / 6 (cache) / 7 (nosql: streams, trending, quota).
- Neo4j Community chỉ có **một database dùng chung** (không tạo thêm được). Node StoryLens của demo nằm cạnh dev, phân biệt theo UUID. Hệ quả:
  `demo-reset.sh --wipe` **không** xoá Neo4j; muốn dọn thì làm thủ công bằng `cypher-shell` sau khi đã backup, và chỉ khi chắc chắn.
- `.app` nằm trong danh sách HSTS preload: chỉ chạy HTTPS, Cloudflare lo TLS. Caddy nghe HTTP thuần trên loopback.
- Mã nguồn demo là bản **commit trên `main`**. File `database/seeders/SourceSeeder.php` đang có chỉnh sửa chưa commit ở checkout dev
  **không** đi vào demo; demo seed danh sách nguồn RSS mặc định từ bản đã commit.

## 2. Cài đặt một lần

Điều kiện: WSL2 Ubuntu 24.04, distro `VietFeed`, `systemd=true` trong `/etc/wsl.conf`; Docker Desktop đã bật tích hợp WSL; PHP 8.4
CLI (có `mongodb`, `redis`, `pcntl`, `opcache`), Composer, Node/npm.

### 2.1 Gói (sudo)

```bash
sudo apt-get install -y php8.4-fpm
# caddy và cloudflared: cài theo kho apt chính thức của từng dự án (xem trang cài đặt của họ).
sudo apt-get install -y caddy cloudflared
```

### 2.2 Worktree của `main` tại `~/vietfeed-demo`

```bash
git -C /home/dolakhoa/code/VietFeed fetch origin
git -C /home/dolakhoa/code/VietFeed worktree add --track -b demo-live ~/vietfeed-demo origin/main
```

Dùng nhánh `demo-live` (theo dõi `origin/main`) vì Git không cho hai worktree cùng checkout `main`. Cập nhật sau này là
`git -C ~/vietfeed-demo pull --ff-only`.

### 2.3 Env (không secrets trong repo)

```bash
cd ~/vietfeed-demo
cp deploy/env.demo.example .env && chmod 600 .env
$EDITOR .env          # thay mọi CHANGE_ME/giá trị rỗng: mật khẩu store, Stripe TEST keys, Google (tuỳ chọn), AI
composer install --no-dev --optimize-autoloader
php artisan key:generate --force
npm ci && npm run build
```

**Thông tin kho dữ liệu:** `MONGO_ROOT_USER/PASSWORD` và `NEO4J_USER/PASSWORD` trong `.env` demo phải **bằng đúng** giá trị mà các container dev đang chạy
(mặc định compose hoặc `.env` dev của bạn); đó không phải tài khoản mới. `REDIS_PASSWORD` thì khác: là mật khẩu mới cho Redis demo riêng (mục 2.4); chỉ dùng chữ và số (compose đọc `.env` và nội suy `$`), và lưu ý mật khẩu hiển thị được qua `docker inspect` trên máy này.

Điểm cần kiểm tra trong `.env` của demo (đã có sẵn giá trị đúng trong mẫu): `MONGO_DB=vietfeed_demo`,
`CASSANDRA_KEYSPACE=vietfeed_demo`, `REDIS_PORT=6380`, `REDIS_DB=5`, `REDIS_CACHE_DB=6`, `REDIS_NOSQL_DB=7`, không đặt `REDIS_URL`, `MONGO_PORT`
khớp cổng compose trên máy (27018 trên PC), `TRUSTED_PROXIES=127.0.0.1`, `DEMO_MODE=true`.
Google OAuth (tuỳ chọn): redirect URI trong Google Cloud Console phải đúng `https://vietfeed.app/auth/google/callback`.
Stripe: chỉ khoá **test**; webhook thử nghiệm trỏ tới `https://vietfeed.app/stripe/webhook`.

### 2.4 Kho dữ liệu và dữ liệu mẫu

Redis của demo là **container riêng** (`vietfeed-demo-redis`, `127.0.0.1:6380`, `noeviction`, volume và mật khẩu riêng) để tải/spam không
làm LRU đẩy mất session, job, bộ đếm rate-limit hay token reset (Redis dev dùng `allkeys-lru` 200 MB). Mongo, Cassandra, Neo4j vẫn là container dev.

```bash
cd /home/dolakhoa/code/VietFeed && docker compose up -d && ./scripts/nosql-health.sh --wait 120   # kho dev nếu chưa chạy
docker compose -p vietfeed-demo -f ~/vietfeed-demo/deploy/docker-compose.demo.yml --env-file ~/vietfeed-demo/.env up -d
cd ~/vietfeed-demo && deploy/demo-reset.sh
```

`deploy/demo-reset.sh` (idempotent): từ chối chạy nếu env nạp vào không phải env demo; migrate; tạo schema Cassandra
(storylens, interactions, reading-pass, sources history, moderation audit, rbac audit); seed Categories + Sources; `storylens ingest` và
`consume` cả hai section; `demo:synthetic seed`; tạo 3 tài khoản `reader@`, `admin@` (quyền giới hạn: content, comments,
stories.review) và `superadmin@vietfeed.app`. Mật khẩu lấy từ biến `DEMO_READER_PASSWORD`, `DEMO_ADMIN_PASSWORD`,
`DEMO_SUPERADMIN_PASSWORD` hoặc được sinh ngẫu nhiên và ghi vào `~/.vietfeed-demo-credentials` (mode 600, không in ra màn hình).
Mật khẩu có trong file hoặc biến môi trường sẽ được ghi lại (mode 600) và tự **đặt lại** trong Mongo khi file chưa tồn tại, khi bạn cung cấp biến, hoặc khi mật khẩu vừa được sinh; nhờ đó file luôn đúng với dữ liệu.
`--reset-passwords` luôn sinh mật khẩu mới cho cả ba tài khoản, kể cả khi bạn đã cung cấp biến môi trường (biến bị bỏ qua).
Tuỳ chọn: `--with-brief` (tạo AI brief nếu `AI_PROVIDER` khác `none`), `--reset-passwords`, `--wipe`.

Lưu ý: dùng `APP_ENV=demo` chỉ cho một tiến trình đó; Laravel sẽ thử nạp `.env.demo` nếu có, vì vậy **đừng** tạo file tên đó. `demo:synthetic` cố ý từ chối khi `APP_ENV=production`; script chạy **riêng lệnh đó** với `APP_ENV=demo` (chỉ cho tiến trình đó, sau khi
guard đã xác nhận đúng store demo).

### 2.5 php-fpm, Caddy, systemd (sudo)

```bash
cd ~/vietfeed-demo
sudo cp deploy/php-fpm/vietfeed-demo.conf /etc/php/8.4/fpm/pool.d/vietfeed-demo.conf
sudo cp deploy/php-fpm/99-vietfeed-demo.ini /etc/php/8.4/fpm/conf.d/99-vietfeed-demo.ini
sudo systemctl enable --now php8.4-fpm && sudo systemctl restart php8.4-fpm

# Caddy chạy bằng user "caddy": cần đi qua /home/dolakhoa và đọc public/
sudo setfacl -m u:caddy:x /home/dolakhoa   # nếu thiếu: sudo apt-get install -y acl
sudo cp deploy/caddy/Caddyfile /etc/caddy/Caddyfile
sudo caddy validate --config /etc/caddy/Caddyfile && sudo systemctl enable --now caddy && sudo systemctl reload caddy

sudo cp deploy/systemd/vietfeed-demo-queue.service deploy/systemd/vietfeed-demo-scheduler.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now vietfeed-demo-queue vietfeed-demo-scheduler
```

Trước khi bật unit, sửa `Environment=WAIT_MONGO_PORT=` (chỉ script chờ store đọc biến này) trong hai file `.service` nếu cổng Mongo trên máy khác 27018. Chỉ chạy **một**
`vietfeed-demo-scheduler`. Docker Desktop không phải unit systemd trong WSL nên `After=docker.service` không đủ; hai unit dùng
`ExecStartPre=deploy/systemd/wait-for-stores.sh` (chờ 4 cổng store mở, tối đa 240 giây rồi systemd thử lại).

### 2.6 Tunnel (sudo, token không vào chat/file)

Tạo tunnel trong Cloudflare dashboard, public hostname `vietfeed.app` → `http://127.0.0.1:8080` (dùng `127.0.0.1`, không dùng
`localhost`, để tránh phân giải sang IPv6). Sau đó:

```bash
sudo cloudflared service install <TOKEN>     # token dán trực tiếp ở terminal, không lưu vào repo/chat
```

Nên thêm trong Cloudflare: quy tắc Rate Limiting cho `/login`, `/register`, `/forgot-password` (xem mục 9) và bật "Always Use HTTPS".

### 2.7 IP khách thật đi qua các tầng như thế nào

1. Cloudflare nhận request, đặt `CF-Connecting-IP: <IP khách>` (không thể giả vì Cloudflare ghi đè) và nối IP khách vào `X-Forwarded-For`; `X-Forwarded-Proto: https`.
2. `cloudflared` chuyển tiếp tới Caddy từ loopback (`127.0.0.1`).
3. Caddy khai báo `servers { trusted_proxies static 127.0.0.1/32 ::1/128; client_ip_headers CF-Connecting-IP }`: vì kết nối đến từ proxy tin cậy,
   Caddy tính IP khách từ `CF-Connecting-IP` (biến `{client_ip}`) và `php_fastcgi` đặt `header_up X-Forwarded-For {client_ip}` cùng
   `header_up X-Forwarded-Proto https`, nên php-fpm luôn nhận IP khách thật và scheme https (không bị ghi đè thành `127.0.0.1`/`http`).
4. Laravel có `TRUSTED_PROXIES=127.0.0.1` nên tin `X-Forwarded-*` từ Caddy và lấy IP khách cho `$request->ip()`, rate limit và log.
Caddy chỉ bind `127.0.0.1`, nên không ai ngoài tunnel gửi được `CF-Connecting-IP` giả. `deploy/health.sh` kiểm tra `/login` phát ra asset `https://vietfeed.app/build/`.

### 2.8 Giới hạn tốc độ trong ứng dụng (Laravel `throttle`, theo IP, cửa sổ 1 phút)

| Route | Giới hạn |
|---|---|
| `POST /register` | 10 |
| `POST /forgot-password` | 6 |
| `POST /reset-password` | 6 |
| `GET /auth/google/redirect`, `/auth/google/callback` (chung) | 10 |
| `POST /api/v1/auth/refresh` | 10 |
| `POST /login` (có sẵn) | 5 lần sai theo email+IP; API login có bộ đếm riêng |

Vượt giới hạn trả HTTP 429. Mỗi route có bộ đếm riêng (tham số prefix của `throttle`).

### 2.9 Cloudflare dashboard (chủ máy tự làm, từng bước)

1. **Bot Fight Mode**: Security → Settings (hoặc Bots) → bật *Bot Fight Mode*.
2. **Always Use HTTPS**: SSL/TLS → Edge Certificates → bật. SSL/TLS mode không cần cấu hình origin vì tunnel tự mã hoá.
3. **Một rule Rate Limiting** (gói Free chỉ cho số rule rất ít, thường 1 rule, một cửa sổ cố định ngắn; nếu giao diện khác thì giữ nguyên ý): Security → WAF → Rate limiting rules → Create.
   - Expression (edit expression): `(http.request.uri.path in {"/login" "/register" "/forgot-password"}) or starts_with(http.request.uri.path, "/api/v1/auth/")`
   - Counting characteristics: IP; ngưỡng ví dụ 20 request / 1 phút (hoặc giá trị nhỏ nhất gói cho phép); Action: Block hoặc Managed Challenge; duration theo giới hạn gói.
   - Rule này là lớp ngoài; lớp trong là `throttle` ở mục 2.8.
4. **Cloudflare Access cho `/admin*`** (Zero Trust, gói Free đến 50 user): one.dash.cloudflare.com → Zero Trust → Access → Applications → Add → *Self-hosted*.
   - Application domain: `vietfeed.app`, path `admin*` (thêm một path thứ hai `admin/*` nếu giao diện yêu cầu).
   - Policy: Action *Allow*, Include → *Emails* → chỉ email của chủ dự án. Login method: *One-time PIN* (email OTP).
   - Kiểm tra: mở `https://vietfeed.app/admin` ở cửa sổ ẩn danh phải thấy trang đăng nhập Access trước khi chạm tới ứng dụng.
   - Không chia sẻ mật khẩu `admin@`/`superadmin@vietfeed.app`.
5. **AI**: giữ `AI_PROVIDER=none` trong `.env` demo. `gemini` là tuỳ chọn có chủ ý: admin có thể kích hoạt tạo brief tốn quota/tiền API; chỉ bật khi đã có Access và giới hạn chi tiêu ở phía Google.
6. **Đăng ký công khai**: để mở nhưng *không xác minh được email* (`MAIL_MAILER=log` không gửi thư thật). Banner demo và runbook này yêu cầu khách dùng tài khoản reader demo do bạn cấp. Nếu bị lạm dụng, đóng bằng một rule Cloudflare chặn `POST /register`.

### 2.10 CSP (báo cáo, chưa áp)

Caddy gửi `Content-Security-Policy-Report-Only` (không chặn gì). Mở các trang chính (trang chủ, bài viết, đăng nhập, admin, thanh toán thử)
với DevTools → Console, ghi lại vi phạm. Khi sạch, đổi tên header trong `deploy/caddy/Caddyfile` thành `Content-Security-Policy`, `caddy validate`, rồi reload. Lưu ý: bản Alpine 3 tiêu chuẩn cần `'unsafe-eval'` trong `script-src` (hoặc chuyển sang `@alpinejs/csp`), nếu không thì enforcing sẽ làm hỏng giao diện. Thư viện CDN chưa có SRI.

## 3. Quy trình cập nhật

```bash
cd ~/vietfeed-demo
git pull --ff-only
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
# nếu bản mới thêm schema Cassandra, các lệnh schema đều idempotent:
#   php artisan storylens schema; php artisan interactions schema; php artisan reading-pass schema
#   php artisan sources:history-manage bootstrap; php artisan moderation:audit schema; php artisan rbac:audit schema
php artisan optimize                      # cache config, route, view, event
sudo systemctl reload php8.4-fpm          # bắt buộc: OPcache không kiểm tra timestamp
sudo systemctl restart vietfeed-demo-queue vietfeed-demo-scheduler
deploy/health.sh
```

## 4. Khởi động / dừng

```bash
# Dừng
sudo systemctl stop vietfeed-demo-scheduler vietfeed-demo-queue
sudo systemctl stop cloudflared caddy php8.4-fpm
docker compose -p vietfeed-demo -f ~/vietfeed-demo/deploy/docker-compose.demo.yml --env-file ~/vietfeed-demo/.env stop
docker compose -f /home/dolakhoa/code/VietFeed/docker-compose.yml stop     # chỉ khi cả dev cũng không cần

# Khởi động (kho dữ liệu trước)
docker compose -f /home/dolakhoa/code/VietFeed/docker-compose.yml up -d && /home/dolakhoa/code/VietFeed/scripts/nosql-health.sh --wait 120
docker compose -p vietfeed-demo -f ~/vietfeed-demo/deploy/docker-compose.demo.yml --env-file ~/vietfeed-demo/.env up -d
sudo systemctl start php8.4-fpm caddy cloudflared vietfeed-demo-queue vietfeed-demo-scheduler
```

## 5. Kiểm tra sức khoẻ

```bash
~/vietfeed-demo/deploy/health.sh            # kho (Redis demo :6380), php-fpm socket, Caddy :8080, 2 unit, HTTP 200 ở / và /up, và `/login` phải chứa URL asset `https://vietfeed.app/build/`
~/vietfeed-demo/deploy/health.sh --public   # thêm https://vietfeed.app/
```

Script chỉ đọc, thoát khác 0 nếu có mục FAIL. Cổng mặc định: Redis demo 6380, Mongo 27018 (cổng compose trên PC), Cassandra 9042, Neo4j 7687; ghi đè bằng biến môi trường, ví dụ
`MONGO_PORT=27017 deploy/health.sh`.

## 6. Log

```bash
journalctl -u vietfeed-demo-queue -u vietfeed-demo-scheduler -f
journalctl -u caddy -u cloudflared -u php8.4-fpm -f
tail -f ~/vietfeed-demo/storage/logs/laravel-*.log
tail -f ~/vietfeed-demo/storage/logs/php-fpm-error.log
sudo tail -f /var/log/caddy/vietfeed-demo-access.log      # JSON, xoay vòng 20 MiB × 5
```

## 7. Reset

Reset nhẹ (giữ dữ liệu, tạo lại phần thiếu): `deploy/demo-reset.sh`.
Reset sạch (chỉ kho demo, có xác nhận gõ tay):

```bash
sudo systemctl stop vietfeed-demo-scheduler vietfeed-demo-queue
deploy/demo-reset.sh --wipe         # drop Mongo vietfeed_demo, flush Redis DB 5/6/7, drop keyspace vietfeed_demo, rồi dựng lại
sudo systemctl start vietfeed-demo-queue vietfeed-demo-scheduler
```

`--wipe` không bao giờ chạm dữ liệu dev (guard so khớp tên DB/keyspace/số DB Redis trước khi xoá) và **không** xoá Neo4j.
Dữ liệu demo có thể bị đặt lại bất cứ lúc nào; banner "Bản demo" (biến `DEMO_MODE`) báo điều đó cho khách.

## 8. Tự khởi động khi bật Windows

Mục tiêu: sau khi Windows lên, Docker Desktop chạy, distro `VietFeed` chạy (kéo theo systemd: php-fpm, caddy, cloudflared, hai unit).

1. **Docker Desktop**: Settings → General → "Start Docker Desktop when you sign in to your computer".
2. **Đánh thức và giữ distro**: Task Scheduler → Create Task (không phải Basic):
   - Trigger: *At log on* của user của bạn (có thể thêm delay 30 giây cho Docker Desktop).
   - Action: Program `wsl.exe`, arguments `-d VietFeed --exec /usr/bin/sleep infinity` (chạy ẩn: đặt "Run whether user is logged on or not"
     sẽ không có cửa sổ, nhưng xem phần chưa chắc chắn bên dưới).
   - Settings: bỏ "Stop the task if it runs longer than"; "If the task fails, restart every 1 minute".
   Tiến trình `sleep infinity` giữ một phiên WSL mở nên VM/distro không bị tắt vì nhàn rỗi; khi distro khởi động, systemd tự bật các service đã `enable`.
3. **Khởi động systemd service**: `systemctl enable` đã làm ở mục 2 (php8.4-fpm, caddy, cloudflared, hai unit vietfeed). Hai unit tự chờ store (Docker Desktop lên chậm hơn WSL).
4. **Không ngủ**: Windows chạy lệnh dưới (quyền admin) hoặc Settings → System → Power:

```powershell
powercfg /change standby-timeout-ac 0
powercfg /change hibernate-timeout-ac 0
powercfg /change monitor-timeout-ac 15
powercfg /hibernate off
```

Đặt hành động đóng nắp laptop (nếu có) = "Do nothing". Cắm sạc, tắt Windows "Fast Startup" nếu thấy WSL không lên sau khi tắt máy.
Cân nhắc tắt tự khởi động lại sau Windows Update (Active hours) vì tunnel sẽ rớt lúc đó.

**Chưa chắc chắn (cần thử và ghi lại kết quả trên máy thật):**
- Với `systemd=true`, các service nền *có thể* tự giữ distro sống, nhưng báo cáo từ cộng đồng không thống nhất theo phiên bản WSL; `sleep infinity` là cách bảo thủ, chưa được kiểm chứng tại đây.
- Task Scheduler "Run whether user is logged on or not" có thể khiến `wsl.exe` chạy trong phiên khác; nếu distro không lên, đổi sang "Run only when user is logged on" (chấp nhận cửa sổ ẩn bằng `conhost.exe --headless wsl.exe ...` hoặc shortcut thu nhỏ).
- Docker Desktop cần đăng nhập Windows; reboot khi không ai đăng nhập (mất điện, update) thì demo không tự sống lại trừ khi bật auto-logon (`netplwiz`, đánh đổi bảo mật cho máy ở nhà).
- Tuỳ chọn `vmIdleTimeout` trong `%UserProfile%\.wslconfig` (`[wsl2]`) có ở các bản WSL mới nhưng giá trị tắt hẳn chưa xác nhận; không phụ thuộc vào nó.
- Cách kiểm chứng: reboot Windows, đăng nhập, đợi 3–5 phút, chạy `wsl -l -v` (VietFeed Running) rồi `deploy/health.sh --public`.

## 9. Checklist bảo mật

- [ ] `.env` mode 600, chỉ trên PC; `APP_DEBUG=false`, `APP_ENV=production`; Stripe chỉ khoá test; `MAIL_MAILER=log`.
- [ ] Docker publish cổng chỉ `127.0.0.1` (đã đúng trong `docker-compose.yml`); Cassandra CQL không có auth nên **không bao giờ** mở 9042 ra ngoài.
- [ ] Caddy chỉ bind `127.0.0.1`; không port-forward router; tunnel chỉ trỏ vào `:8080`.
- [ ] Mật khẩu 3 tài khoản demo nằm ở `~/.vietfeed-demo-credentials` (600), không phát tán; super admin dùng mật khẩu riêng, mạnh.
- [ ] `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN=vietfeed.app`; `TRUSTED_PROXIES` chỉ loopback.
- [ ] Cloudflare: Bot Fight Mode, rate-limit rule, Access cho `/admin*` (mục 2.9) đã bật.
- [ ] Giới hạn tốc độ có sẵn: đăng nhập (`LoginRequest`: 5 lần sai/khoá theo email+IP), toggle tương tác (`interaction-toggles`), xác minh email (`throttle:6,1`), reset mật khẩu (broker 60 giây). đăng ký, reset mật khẩu, Google OAuth và API refresh đã có throttle (mục 2.8); thêm Cloudflare Rate Limiting rule như mục 2.9.
- [ ] Cloudflare: Bot Fight Mode và "Always Use HTTPS" đã bật.
- [ ] Banner demo (`DEMO_MODE=true`) bật; không dùng dữ liệu thật của người dùng thật.
- [ ] Cập nhật định kỳ `sudo apt-get upgrade`, `cloudflared update`, image Docker; sao lưu nếu cần giữ dữ liệu (volume Docker).
- [ ] `public/` chỉ chứa `index.php` và tài nguyên; Caddy chặn dotfile (trừ `.well-known`).
