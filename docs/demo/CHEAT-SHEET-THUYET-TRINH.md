# 🎤 CHEAT SHEET — Thuyết trình VietFeed (BẢN ĐẦY ĐỦ)

> Mở sẵn khi bảo vệ. Ctrl+F theo số mục. Mục lục:
> 0·Pitch · 1·Toàn bộ chức năng · 2·Luồng request · 3·Database & đổi tên DB · 4·CRUD · 5·Phân trang · 6·AJAX · 7·Validation · 8·Quan hệ Eloquent · 4B·**Demo CRUD sẵn** · 9·Tính năng nâng cao · 10·**Thêm nguồn RSS mới (+ mẫu test)** · 11·Câu hỏi tủ · 12·Kịch bản demo · 13·Bản đồ "chỗ X" · 14·**Sâu: Google Auth & Reading Pass** · 15·**Luồng RSS (chi tiết)** · 16·**Thanh toán Stripe + Webhook**

---

## 0. PITCH MỞ MÀN (30 giây)
> "VietFeed là trang **tổng hợp tin tức tiếng Việt**, tự động kéo bài từ VnExpress, Tuổi Trẻ, Thanh Niên… qua **RSS** (scheduler mỗi 30 phút). Người dùng có **feed cá nhân hóa**, **bookmark**, **boost (vote)**, **bình luận phân cấp**, **báo cáo vi phạm**; admin có **dashboard thống kê + CRUD + kiểm duyệt (sanction) + theo dõi sức khỏe nguồn**. Có cả **gói Pro (Stripe)** và **đăng nhập Google**. Stack: **Laravel 13 · MySQL · Blade + Bootstrap 5 + Alpine.js**, kiến trúc **MVC** mở rộng thêm tầng **Service / Job / Form Request**."

---

## 1. TOÀN BỘ CHỨC NĂNG (feature inventory)

**👤 Khách (guest)**
- Trang chủ magazine (featured + hero + breaking + community boosted + feed) — `HomeController`
- Xem danh sách bài / chi tiết bài — `ArticleController`
- Lọc theo chuyên mục — `CategoryController`
- Tìm kiếm + live-search — `SearchController`
- Xem bảng giá Pro — `BillingController::pricing`
- Thanh ticker tỉ giá USD/vàng (AJAX) — `TickerController`
- Đọc bài giới hạn (reading pass: 5 bài/ngày cho khách) — `ReadingPassService`

**🔐 Xác thực (auth)**
- Đăng ký / Đăng nhập / Quên mật khẩu / Đặt lại mật khẩu (Laravel Breeze)
- **Xác minh email** (`MustVerifyEmail`)
- **Đăng nhập Google** (OAuth/Socialite) — `GoogleAuthController`
- Onboarding chọn sở thích sau đăng ký — `OnboardingController`

**🙋 Người dùng đã đăng nhập**
- Bookmark (lưu bài) — AJAX toggle, trang `/bookmarks`
- Boost (vote bài, giống like) — AJAX toggle
- Bình luận + trả lời (threaded), sửa/xóa comment của mình — `CommentController`
- Báo cáo bình luận vi phạm — `ReportController`
- Kháng cáo lệnh phạt — `SanctionAppealController`
- Hồ sơ: đổi tên/email, xóa tài khoản, xem reading pass, cập nhật chuyên mục yêu thích — `ProfileController`
- Mua gói Pro (Stripe Checkout) + cổng quản lý — `BillingController`

**🛠️ Admin** (`/admin/*`, chặn bằng middleware)
- Dashboard: thống kê + biểu đồ (Chart.js) + sức khỏe nguồn — `Admin/DashboardController`
- CRUD **Nguồn tin** (Source) + test/retry/health/toggle — `Admin/SourceController`
- CRUD **Chuyên mục** (Category) + toggle active — `Admin/CategoryController`
- Quản lý **Bài viết** (Article): sửa/xóa/xóa hàng loạt — `Admin/ArticleController`
- Quản lý **User**: sửa role, xóa — `Admin/UserController`
- Kiểm duyệt **Bình luận**: xóa — `Admin/CommentController`
- Xử lý **Báo cáo** (Report) — `Admin/ReportController`
- Ra **Lệnh phạt** (Sanction: cảnh cáo/mute/ban) + duyệt kháng cáo — `Admin/SanctionController`

---

## 2. LUỒNG XỬ LÝ 1 REQUEST (đọc vanh vách)
Ví dụ mở `/articles/cong-nghe-abc`:
```
① Trình duyệt: GET /articles/cong-nghe-abc
② routes/web.php:30  → khớp route → ArticleController@show, lấy $slug
③ Middleware       → global 'sanction' (bắt user bị ban); route này public nên không qua auth/admin
④ Controller       → ArticleController::show(): gọi Model lấy data + Service (reading pass)
⑤ Model (Eloquent) → sinh SQL → MySQL → trả object Article
⑥ return view(...) → truyền data sang View
⑦ Blade            → resources/views/articles/show.blade.php render HTML
⑧ Trả HTML về trình duyệt
```
> Câu thần chú: **Route → Middleware → Controller → Model/DB → View → HTML**
> Quy tắc MVC: **View không truy vấn DB · Controller không viết HTML**

---

## 3. DATABASE & ĐỔI TÊN DB

### 🔴 Kịch bản "đổi tên DB rồi chạy lại" — đúng thứ tự
```bash
# B1. Tạo DB mới
mysql -u root -p -e "CREATE DATABASE vietfeed_new CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
# B2. Sửa .env → DB_DATABASE=vietfeed_new
# B3. Xóa cache config (RẤT QUAN TRỌNG, hay quên → vẫn dùng tên DB cũ)
php artisan config:clear
# B4. Tạo lại bảng + dữ liệu mẫu
php artisan migrate:fresh --seed
# B5. Kéo tin RSS thật
php artisan feeds:fetch
```
Cấu hình DB: `.env` (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`) + `config/database.php`.

### Bảng lệnh DB
| Lệnh | Tác dụng |
|---|---|
| `php artisan migrate` | Chạy migration **chưa** chạy |
| `php artisan migrate:fresh` | **Xóa sạch** mọi bảng → tạo lại |
| `php artisan migrate:fresh --seed` | Xóa sạch + tạo lại + **đổ dữ liệu mẫu** |
| `php artisan db:seed` | Chỉ đổ dữ liệu mẫu |
| `php artisan db:seed --class=SourceSeeder` | Chạy **một** seeder |
| `php artisan migrate:rollback` | Lùi batch gần nhất |
| `php artisan route:list` | Xem mọi route (chứng minh routing) |
| `php artisan tinker` | Shell tương tác để query thử |

> **fresh vs refresh:** `fresh` = DROP thẳng (nhanh); `refresh` = chạy `down()` rồi `up()`.

### Schema — **22 bảng** (14 nghiệp vụ + 8 hệ thống) — *đã verify bằng `php artisan db:show`*
**Nghiệp vụ (14):** `users`, `categories`, `sources`, `articles`, `comments`, `bookmarks`, `category_user` (pivot), `boosts`, `article_unlocks`, `reports`, `sanctions`, `subscriptions`, `subscription_items`, `source_fetch_logs`.
**Hệ thống (8):** `migrations`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens`.
> ⚠️ Bảng thật tên `articles` (KHÔNG phải "Posts"), pivot tên `category_user` (KHÔNG phải "User_Interests"), **không có** bảng tags/tag. Khóa chống trùng là `articles.original_url`; nguồn dùng `sources.feed_url`.
Định nghĩa ở `database/migrations/` (24 file). Dữ liệu mẫu ở `database/seeders/` (`CategorySeeder`, `SourceSeeder`, `UserSeeder`, `DemoEngagementSeeder`).

---

## 4. CRUD NẰM Ở ĐÂU (nhiều bảng — đủ yêu cầu "CRUD ≥ 2 bảng")

Khai báo bằng **resource route** ở `routes/web.php:109-118` (1 dòng = 7 route REST). Xem nhanh: `php artisan route:list`.

| Bảng | Controller | Create | Read | Update | Delete | Ghi chú |
|---|---|:--:|:--:|:--:|:--:|---|
| **Sources** ⭐ | `Admin/SourceController` | ✓ | ✓ | ✓ | ✓ | **CRUD đầy đủ** + test/retry/health/toggle |
| **Categories** ⭐ | `Admin/CategoryController` | ✓ | ✓ | ✓ | ✓ | **CRUD đầy đủ** + toggleActive (Form Request) |
| **Articles** | `Admin/ArticleController` | – | ✓ | ✓ | ✓ | Không create (bài đến từ RSS) + `bulkDestroy` xóa hàng loạt |
| **Users** | `Admin/UserController` | – | ✓ | ✓ | ✓ | Sửa role/xóa |
| **Comments** | `Admin/CommentController` | – | ✓ | – | ✓ | Kiểm duyệt (index + xóa) |
| **Reports** | `Admin/ReportController` | – | ✓ | ✓ | ✓ | Đổi trạng thái xử lý |
| **Sanctions** | `Admin/SanctionController` | ✓ | ✓ | ✓ | ✓ | Ra phạt + duyệt kháng cáo |

> 🎤 *Khi thầy hỏi "CRUD ở đâu, làm trên mấy bảng?"* → "Em có CRUD đầy đủ trên **Nguồn tin (sources)** và **Chuyên mục (categories)** — đủ 7 method RESTful, dùng resource route. Ngoài ra còn quản lý Article, User, Comment, Report, Sanction. Mẫu chuẩn nhất là `Admin/SourceController`."

**7 method RESTful (lấy Source làm ví dụ):**
| Method | HTTP | URL | Việc |
|---|---|---|---|
| index | GET | /admin/sources | Read danh sách |
| create | GET | /admin/sources/create | Form thêm |
| store | POST | /admin/sources | **Create** |
| show | GET | /admin/sources/{id} | Read chi tiết |
| edit | GET | /admin/sources/{id}/edit | Form sửa |
| update | PUT/PATCH | /admin/sources/{id} | **Update** |
| destroy | DELETE | /admin/sources/{id} | **Delete** |

---

## 4B. ⭐ DEMO CRUD SẴN — bảng **Chủ đề (Category)** *(làm trực tiếp, không cần mạng)*

> Chọn **Category** vì CRUD đầy đủ + **không phụ thuộc RSS/mạng** → demo nhanh, chắc ăn. Bạn **tự tạo rồi tự xóa** nên không hư dữ liệu thật. (Bảng **Source** y hệt pattern; phần Create của Source xem **Mục 10**.)
> ⚙️ **Form Category chỉ có 3 trường thật:** `name`, `slug`, `is_active` — *KHÔNG có "description", đừng nhắc tới.*

### Bước 1 — CREATE (Thêm)  `[/admin/categories → bấm "Thêm chủ đề"]`
| Trường | Giá trị dán |
|---|---|
| Tên (name) | `Đời sống` |
| Slug | `doi-song` |

> *(Tùy chọn khoe validation:)* gõ slug = `Doi Song` (có HOA + dấu cách) → Lưu → hiện lỗi tiếng Việt **"Slug chỉ được chứa chữ thường, số và dấu gạch ngang."** → sửa lại `doi-song`.

Bấm **Lưu** → chạy `store()`:
```
POST /admin/categories → StoreCategoryRequest validate (name & slug UNIQUE, slug khớp regex ^[a-z0-9-]+$)
→ Category::create(...) + is_active=true → redirect kèm flash "Đã thêm chủ đề mới."
```
🎤 *Nói:* "Validate bằng **Form Request** riêng (`StoreCategoryRequest`) có thông báo lỗi tiếng Việt. Hợp lệ thì `create`, sai thì tự quay lại form kèm `$errors`."

### Bước 2 — READ (Xem / Tìm / Sắp xếp)  `[ở trang danh sách]`
- Thấy "Đời sống" vừa thêm, cột **số bài = 0**, **số nguồn = 0** (đếm bằng `withCount`).
- Gõ ô tìm `Đời` → lọc theo tên · bấm tiêu đề cột để **sắp xếp** · danh sách **phân trang 20/trang**.

🎤 *Nói:* "`index()` dùng `withCount(['articles','sources'])` để đếm quan hệ ngay trong 1 query (**chống N+1**), kèm tìm kiếm + sắp xếp + phân trang."

### Bước 3 — UPDATE (Sửa)  `[bấm "Sửa" ở dòng "Đời sống"]`
Đổi Tên → `Đời sống & Gia đình` (giữ nguyên slug) → **Lưu**:
```
PUT /admin/categories/{id} → UpdateCategoryRequest (rule unique BỎ QUA chính nó: unique:categories,name,{id})
→ $category->update(...) → redirect kèm "Đã cập nhật chủ đề."
```
🎤 *Điểm tinh tế:* "Khi sửa, rule `unique` phải **loại trừ chính bản ghi đang sửa** (`...,{id}`) — không thì nó báo trùng với chính mình."

### Bước 4 — DELETE (Xóa) — demo luôn **lá chắn toàn vẹn dữ liệu**
**(a)** Thử xóa một chủ đề **đã seed** (vd *Công nghệ*) → **bị chặn**:
> ❌ "Không thể xóa chủ đề đang có bài viết hoặc nguồn tin."

**(b)** Xóa "Đời sống & Gia đình" (đang 0 bài, 0 nguồn) → **thành công** ✅:
```
DELETE /admin/categories/{id} → destroy():
   if (còn articles HOẶC sources) → chặn + báo lỗi      ← (a) rơi vào đây
   else → $category->delete() → "Đã xóa chủ đề."          ← (b) rơi vào đây
```
🎤 *Câu ăn điểm:* "Em **chặn xóa** chủ đề còn bài/nguồn để tránh dữ liệu **mồ côi (orphan)** — giữ **toàn vẹn tham chiếu** ở tầng ứng dụng, bổ trợ cho khóa ngoại ở DB."

### (Bonus) Bật/tắt hiển thị  `[bấm nút toggle]`
`PATCH /admin/categories/{id}/toggle-active` → đảo `is_active`. Tắt thì người dùng **không thấy** tin thuộc chủ đề đó.

> 🧭 **Bản đồ 4 thao tác CRUD → code:**
> | Thao tác | Nút | HTTP + Route | Controller | DB |
> |---|---|---|---|---|
> | **C**reate | "Thêm chủ đề" + Lưu | `POST /admin/categories` | `store()` | INSERT |
> | **R**ead | mở trang list | `GET /admin/categories` | `index()` | SELECT + COUNT |
> | **U**pdate | "Sửa" + Lưu | `PUT /admin/categories/{id}` | `update()` | UPDATE |
> | **D**elete | "Xóa" | `DELETE /admin/categories/{id}` | `destroy()` | DELETE (có chặn) |

> ⚠️ **Chuẩn bị:** đăng nhập sẵn admin. Demo này tự tạo "Đời sống" rồi tự xóa → **không đụng dữ liệu thật**. Lỡ tạo mà quên xóa thì vào xóa lại là sạch.

---

## 5. PHÂN TRANG Ở ĐÂU
Logic ở Controller bằng `->paginate(N)`; hiển thị nút ở Blade bằng `{{ $articles->links() }}` (style Bootstrap, cấu hình ở `app/Providers/AppServiceProvider.php`).

| Trang | File:line | Số/trang |
|---|---|---|
| Danh sách bài | `ArticleController.php:38` (+`withQueryString()`) | 12 |
| Theo chuyên mục | `CategoryController.php:21` | 12 |
| Kết quả tìm kiếm | `SearchController.php:27` | 12 |
| Trang chủ (feed) | `HomeController.php:138` | 12 |
| Admin – sources | `Admin/SourceController.php:34` | 20 |
| Admin – articles/categories/users/reports/sanctions | mỗi controller | 20 |
| Admin – comments | `Admin/CommentController.php:20` | 25 |
| Admin – chi tiết nguồn (bài của nguồn) | `Admin/SourceController.php:175` | 15 |

> **Ngoại lệ — trang chủ thêm *infinite scroll*** (cuộn vô tận): `HomeController::loadMore()` + route `/api/articles` (`web.php:43`) trả JSON cho JS nạp tiếp (`app.js` ~dòng 275). Tức trang chủ có CẢ paginate (server) lẫn infinite scroll (client).

---

## 6. AJAX Ở ĐÂU (gọi server, không reload trang)
Code JS: `resources/js/app.js`. Tất cả gửi kèm CSRF token.

| Tính năng | Trigger | Endpoint | Trả về | Controller |
|---|---|---|---|---|
| **Bookmark toggle** | click 🔖 | `POST /bookmarks/toggle` | JSON `{action,count}` | `BookmarkController::toggle` |
| **Boost toggle** (vote) | click ⚡ | `POST /boosts/toggle` | JSON `{action,count}` | `BoostController::toggle` |
| **Live-search** | gõ ≥2 ký tự (debounce 300ms) | `GET /api/live-search?q=` | JSON 6 kết quả | `SearchController::liveSearch` |
| **Infinite scroll** | cuộn tới cuối trang chủ | `GET /api/articles?page=N` | JSON `{html,hasMore}` | `HomeController::loadMore` |
| **Ticker** USD/vàng | tự poll mỗi 60s | `GET /api/ticker` | JSON tỉ giá | `TickerController::index` |
| **Gửi lại email xác minh** | click trong modal | `POST /email/verification-notification` | (gửi mail) | Breeze |

> Client-only (không gọi server): dark mode (localStorage), auto-hide navbar, toggle form trả lời comment.
> 🎤 *Điểm khoe:* live-search **escape HTML chống XSS**, bookmark/boost xử lý lỗi 401 (chưa login → chuyển /login) và 403 (chưa verify email → hiện toast).

---

## 7. VALIDATION Ở ĐÂU
**Cách 1 — Form Request** (class riêng, gọn controller) trong `app/Http/Requests/`:
| Class | Rule chính |
|---|---|
| `Auth/LoginRequest` | email, password + rate-limit 5 lần |
| `ProfileUpdateRequest` | name, email (unique trừ chính mình) |
| `Admin/StoreCategoryRequest` / `Update…` | name unique, slug regex `^[a-z0-9-]+$` |
| `Admin/StoreSourceRequest` / `Update…` | url/feed_url là `url`, category_id `exists`, prestige 1-5 |

**Cách 2 — inline `$request->validate([...])`** trong controller (≈14 chỗ), ví dụ:
- `CommentController:33` → `body required|max:2000`, `parent_id nullable|exists:comments,id`
- `ReportController:17` → `reason in:hate_speech,harassment,spam,...`
- `Admin/UserController:47` → `role required|in:user,admin`
- `Admin/SanctionController:54` → `type in:warning,mute,temporary_ban,permanent_ban`, `duration_days 1-365`
- `RegisteredUserController:36` → `password required|confirmed|min:8`
- `ProfileController:66` (xóa tài khoản) → `password required|current_password`

> 🎤 "Validation 2 kiểu: **Form Request** cho form admin (tách riêng, có `rules()` + `messages()` tiếng Việt), và **inline** cho thao tác nhỏ. Nếu fail, Laravel tự redirect lại kèm lỗi `$errors`."

---

## 8. QUAN HỆ ELOQUENT (thầy hay hỏi)
```
Category 1─∞ Source 1─∞ Article 1─∞ Comment   (Comment.parent_id → Comment: PHÂN CẤP/threaded)
Article 1─∞ Bookmark/Boost ∞─1 User
User ∞─∞ Category   (pivot category_user → feed cá nhân hóa, dùng sync())
User 1─∞ Comment / Report / Sanction / ArticleUnlock
```
- **1-nhiều:** `hasMany`/`belongsTo` — vd `Article::comments()`, `Comment::article()`.
- **Nhiều-nhiều:** `User::favoriteCategories()` ↔ `Category::users()` qua pivot `category_user`. Lưu bằng `->sync($ids)` (`ProfileController:56`, `OnboardingController:31`).
- **Tự tham chiếu (threaded comment):** `Comment::parent()` + `Comment::replies()` qua `parent_id`.
- **Accessor:** `Article::readingTime` (tính phút đọc), `Source::healthStatus`.
- **Scope:** `Category::scopeActive()`, `Comment::scopeVisible()`, `Sanction::scopeActive()`.
- **Chống N+1:** eager load `with(['source','category'])`; đếm bằng `withCount(['bookmarks','boosts'])`.

---

## 9. TÍNH NĂNG NÂNG CAO "ĂN ĐIỂM"
- **Pipeline RSS:** `feeds:fetch` (Command) → `FeedIngestionService::fetchSource()` → `Http::get` tải feed → `SimpleXML` parse → trích ảnh (ưu tiên `enclosure` → `media:content` → regex `<img>`) → `updateOrCreate(['original_url'=>...])` **chống trùng** (`FeedIngestionService.php:243`) → ghi `SourceFetchLog` + cập nhật metrics sức khỏe nguồn.
- **Scheduler:** `routes/console.php:11` chạy `feeds:fetch` mỗi 30 phút (không cần cron bẩn).
- **Job + Queue:** nút "Retry" admin → `FetchSourceFeedJob` (5 lần thử, backoff tăng dần) chạy nền qua queue (database driver).
- **Source health:** trạng thái healthy/warning/failed/stale/critical (`Source::getHealthStatusAttribute`), cấu hình ngưỡng ở `config/source_health.php`.
- **Reading pass (paywall):** Admin/Pro = không giới hạn; user verify = 15 bài/5h; user thường = 8/ngày; khách = 5/ngày (`ReadingPassService`). Khách đọc lưu theo `session_id` vào `article_unlocks`, login thì `transferGuestUnlocks()` chuyển sang user.
- **Phân quyền nhiều lớp (defense in depth):** middleware `['auth','admin']` + `abort_if()` trong controller. `CheckUserSanction` là **middleware toàn cục** chặn user bị ban ở mọi trang.
- **Bảo mật:** mật khẩu cast `'hashed'`; login `session()->regenerate()` chống session fixation; live-search escape HTML.
- **Stripe (Cashier):** gói Pro qua `newSubscription('pro')->checkout()`, webhook cập nhật trạng thái.
- **OAuth Google (Socialite):** redirect → callback → upsert user theo `google_id`/email.
- **Điểm "hot" tính bằng SQL** (`HomeController` `sensational_score`): `bookmark*5 + comment*2 + prestige*4 − tuổi_giờ*0.5` → sắp xfeatured/hero ngay trong DB cho nhanh, **chạy được cả SQLite lẫn MySQL**.

---

## 10. ⭐ THÊM 1 NGUỒN RSS MỚI (+ mẫu để test)

### Cách làm trên giao diện admin
1. Đăng nhập admin → vào **`/admin/sources`** → bấm **"Thêm nguồn"** (`create.blade.php` → `SourceController::create`).
2. Điền form (validate bởi `StoreSourceRequest`):
   | Trường | Bắt buộc | Quy tắc |
   |---|---|---|
   | **Tên** (name) | ✓ | text ≤255 |
   | **URL trang chủ** (url) | ✓ | là URL hợp lệ |
   | **RSS feed URL** (feed_url) | ✓ | là URL hợp lệ |
   | **Logo URL** (logo_url) | – | URL (có thể bỏ trống) |
   | **Chuyên mục** (category_id) | ✓ | chọn từ 8 mục đã seed |
   | **Đang hoạt động** (is_active) | – | checkbox |
   | **Độ uy tín** (prestige) | – | số 1–5 (mặc định 3) |
3. Bấm **Lưu** → `store()` tạo bản ghi → quay lại danh sách.
4. **Lấy bài về** (chọn 1):
   - Cách nhanh nhất cho demo: `php artisan feeds:fetch --source=<ID>` (ID hiện ở danh sách; chạy đồng bộ, thấy ngay).
   - Hoặc trên UI: nút **"Test RSS"** (kiểm tra feed sống không, đếm item — *không lưu*) rồi **"Retry"** (đẩy vào queue, cần `queue:listen` chạy).
   - Hoặc kéo tất cả: `php artisan feeds:fetch`.
5. Mở chuyên mục tương ứng / trang chủ → **bài mới xuất hiện**. ✅

### 📋 Mẫu để dán test (đều là feed CHƯA seed → fetch ra bài mới ngay)
**Khuyến nghị dùng cái này (nhiều bài, luôn mới):**
| Trường | Giá trị |
|---|---|
| Tên | `VnExpress – Tin mới nhất` |
| URL trang chủ | `https://vnexpress.net` |
| RSS feed URL | `https://vnexpress.net/rss/tin-moi-nhat.rss` |
| Logo URL | `https://s1.vnecdn.net/vnexpress/restapi/i/v526/logo_vne_desktop.svg` |
| Chuyên mục | **Thời sự** |
| Độ uy tín | `5` |

**Phương án dự phòng:**
| Tên | feed_url | Chuyên mục |
|---|---|---|
| VnExpress – Pháp luật | `https://vnexpress.net/rss/phap-luat.rss` | Thời sự |
| VnExpress – Khoa học | `https://vnexpress.net/rss/khoa-hoc.rss` | Công nghệ |
| VnExpress – Du lịch | `https://vnexpress.net/rss/du-lich.rss` | Giải trí |

> 🎤 *Câu chốt:* "Em thêm nguồn qua form admin (có validate URL bằng `StoreSourceRequest`), rồi chạy `feeds:fetch --source=ID`. Service sẽ tải RSS, parse XML, **chống trùng bằng `original_url`** nên chạy nhiều lần không tạo bài lặp."

> ⚠️ Mẹo an toàn khi demo: chạy thử `php artisan feeds:fetch --source=<ID>` **một lần trước buổi** để chắc feed sống. Nếu mạng trường chặn, vẫn còn dữ liệu seed sẵn để trình bày.

---

## 11. CÂU HỎI TỦ + TRẢ LỜI MẪU
**Sao chọn Laravel?** → "batteries included": auth (Breeze), ORM (Eloquent), scheduler, migration → tập trung logic RSS.
**MVC ở đâu?** → Model `app/Models`, Controller `app/Http/Controllers`, View `resources/views` (Blade).
**Chống bài trùng RSS?** → `updateOrCreate` theo `original_url` (UNIQUE) — `FeedIngestionService.php:243`.
**Chặn user thường vào /admin?** → middleware `['auth','admin']`; `AdminMiddleware` gọi `isAdmin()` (cột `role`) → không phải admin thì `abort(403)`.
**Mật khẩu lưu sao?** → cast `'hashed'` (bcrypt), không plain text; login `session()->regenerate()`.
**Phân trang?** → mục 5. **CRUD mấy bảng?** → mục 4. **AJAX?** → mục 6. **Validation?** → mục 7.
**Many-to-many ở đâu?** → `User ↔ Category` qua pivot `category_user` (feed cá nhân hóa), lưu bằng `sync()`.
**Comment phân cấp?** → `parent_id` tự tham chiếu (`Comment::parent()`/`replies()`).
**Tự động lấy tin?** → scheduler `feeds:fetch` mỗi 30 phút (`routes/console.php`).
**Tách lớp thế nào?** → logic nặng ở **Service**, chạy nền ở **Job**, validate ở **Form Request** → controller mỏng.
**Xử lý lỗi khi 1 nguồn chết?** → `try/catch` từng nguồn trong `FetchFeeds`, ghi log + đánh dấu health, các nguồn khác vẫn chạy.

---

## 12. KỊCH BẢN DEMO (thứ tự trình bày cho thầy)
1. **Trang chủ (khách):** magazine layout, ticker tỉ giá chạy, cuộn xuống → **infinite scroll** nạp thêm (AJAX). Toggle **dark mode**.
2. **Tìm kiếm:** gõ từ khóa → **live-search** hiện gợi ý tức thì (AJAX) → Enter ra trang kết quả (**phân trang**).
3. **Đăng ký** tài khoản mới → màn **onboarding chọn sở thích** → trang chủ giờ **cá nhân hóa** theo mục đã chọn.
4. **Đăng nhập Google** (nếu cấu hình) — khoe OAuth.
5. Mở 1 **bài viết** → **bookmark** (AJAX, icon đổi) → **boost** (AJAX, +1) → **bình luận** → **trả lời** (threaded) → **báo cáo** một bình luận.
6. Trang **/bookmarks** liệt kê bài đã lưu. Trang **/profile** đổi tên / xem reading pass.
7. (Tùy chọn) **/pricing** → mua **Pro** qua Stripe test card.
8. **Đăng nhập admin** → **Dashboard**: số liệu + biểu đồ Chart.js (bài/ngày, user/ngày) + bảng **sức khỏe nguồn**.
9. **CRUD nguồn tin:** thêm nguồn mới (mục 10) → **Test RSS** → `feeds:fetch --source=ID` → bài mới xuất hiện. Sửa/xóa nguồn.
10. **CRUD chuyên mục:** thêm/sửa/toggle active.
11. **Quản lý bài viết:** sửa 1 bài, xóa hàng loạt (bulkDestroy).
12. **Kiểm duyệt:** xử lý **report** → ra **sanction** (mute/ban) cho user → ẩn bình luận. Đổi **role** user.
13. Mở terminal: `php artisan route:list` (chứng minh routing) · `php artisan migrate:fresh --seed` (dựng lại DB).

---

## 13. BẢN ĐỒ "CHỈ CHO TÔI CHỖ X"
| Hỏi | File / thư mục |
|---|---|
| Định tuyến | `routes/web.php`, `routes/auth.php`, `routes/console.php` (scheduler) |
| Controller | `app/Http/Controllers/` (+ `Admin/`, `Auth/`) |
| Model + quan hệ | `app/Models/` |
| Cấu trúc bảng | `database/migrations/` |
| Dữ liệu mẫu | `database/seeders/` |
| Validation | `app/Http/Requests/` + `$request->validate()` trong controller |
| Middleware/phân quyền | `app/Http/Middleware/` + alias ở `bootstrap/app.php` |
| Tầng nghiệp vụ | `app/Services/` (RSS, ReadingPass, Ticker) |
| Job nền | `app/Jobs/FetchSourceFeedJob.php` |
| Lệnh artisan | `app/Console/Commands/` (FetchFeeds, CleanupOldGuestUnlocks) |
| Giao diện | `resources/views/` (3 layout, 21 component, ~74 file) |
| JS / CSS | `resources/js/app.js`, `resources/css/app.css` |
| Cấu hình | `config/` (database, source_health, cashier, services…) + `.env` |

---

## 14. GIẢI THÍCH SÂU: Google Auth & Reading Pass

### 14A. ĐĂNG NHẬP GOOGLE (chuẩn **OAuth2**, dùng package `laravel/socialite`)

**Hiểu nôm na trước:** thay vì tự nghĩ mật khẩu cho VietFeed, người dùng **nhờ Google đứng ra bảo chứng**: *"Google ơi, xác nhận giùm người này đúng là chủ email X."* Google gật đầu và đưa lại cho VietFeed một ít thông tin (email, tên, ảnh). **VietFeed không bao giờ nhìn thấy mật khẩu Google.**
> 🪪 *Ví dụ đời thường:* như vào quán bar — bạn đưa CMND cho bảo vệ (Google) xem, bảo vệ phát cho bạn cái **vòng tay** (token), vào quán (VietFeed) chỉ cần chìa vòng tay. Quán **không giữ CMND** của bạn.

**Có 2 endpoint (2 cánh cửa), nằm ở `GoogleAuthController.php`:**

**① `redirect()` — đẩy người dùng SANG Google**
```
User bấm "Đăng nhập với Google"  →  GET /auth/google/redirect
→ Socialite::driver('google')->scopes(['openid','profile','email'])->redirect()
→ Trình duyệt nhảy sang trang google.com (kèm lời xin 3 quyền: openid, profile, email)
```
Người dùng đăng nhập + bấm "Đồng ý" **ngay trên Google** (app mình không dính tới bước nhạy cảm này).

**② `callback()` — Google đẩy NGƯỢC về app**
```
Google chuyển về  →  GET /auth/google/callback?code=XYZ   (kèm 1 "mã tạm" gọi là code)
→ Socialite::driver('google')->user()
   → Socialite âm thầm dùng "code" đổi lấy token, rồi tải hồ sơ Google (id, email, tên, avatar)
→ Tìm-hoặc-tạo user (bọc trong DB::transaction + lockForUpdate để 2 request đồng thời không tạo trùng):
     • Tìm user theo google_id  HOẶC  email
     • CHƯA có   → TẠO MỚI tài khoản
     • ĐÃ có rồi → LIÊN KẾT google_id vào tài khoản cũ  (account linking)
→ Auth::login($user, remember: true)        // đăng nhập, nhớ phiên
→ session()->regenerate()                   // cấp session ID mới → chống session fixation
→ transferGuestUnlocks(...)                 // dời "vé đọc" của khách sang tài khoản (xem mục 14B)
→ User MỚI  → /onboarding/interests (chọn sở thích)
   User CŨ  → quay lại trang họ định vào (dashboard)
```

**3 quyết định thiết kế cần hiểu (thầy dễ hỏi "tại sao"):**
| Khi tạo user mới | Làm gì | **Tại sao** |
|---|---|---|
| `password` | đặt **chuỗi ngẫu nhiên 40 ký tự** (`Str::random(40)`) | Login bằng Google nên **không dùng** mật khẩu này; nhưng cột `password` không được trống → nhét đại 1 chuỗi không ai đoán được. |
| `email_verified_at` | gán **`now()`** ngay | Google đã xác minh email hộ rồi → **khỏi bắt user xác minh email lần nữa**. |
| Email đã tồn tại | **liên kết** thay vì báo lỗi | Ai từng đăng ký bằng email, sau bấm Google → gộp vào **1 tài khoản duy nhất** (không đẻ ra 2 account trùng email). |

**Cấu hình:** khai báo `google` trong `config/services.php` (client_id / client_secret / redirect) + `.env` (`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`). Lấy bộ key này ở **Google Cloud Console** (tạo OAuth Client).

> 🎤 *Trả lời mẫu:* "Đăng nhập Google theo chuẩn **OAuth2** qua Socialite: app đẩy user sang Google, Google trả về một `code`, app đổi `code` lấy hồ sơ — **app không bao giờ thấy mật khẩu Google**. Nếu email đã tồn tại thì em **liên kết** (`google_id`) vào tài khoản cũ chứ không tạo trùng, và toàn bộ bọc trong **transaction + lockForUpdate** cho an toàn khi nhiều request cùng lúc."

### 14B. READING PASS (giới hạn lượt đọc — mô hình "metered paywall")

**Hiểu nôm na trước:** giống các báo lớn (NYT, WSJ) — đọc free một số bài, muốn đọc nhiều hơn thì **đăng ký / xác minh email / mua Pro**. Mỗi người có một "**xấp vé đọc**" theo hạng; mở 1 bài mới = xé 1 vé. Mục đích: dẫn dắt người dùng **nâng cấp dần**: khách → đăng ký → verify email → mua Pro. Toàn bộ logic gom vào **1 Service** (`app/Services/ReadingPassService.php`) nên controller chỉ gọi 1 dòng.

**Số vé theo hạng (hàm `allowance()`):**
| Hạng | Đọc được | Hết vé thì nạp lại khi nào |
|---|---|---|
| **Admin** | ∞ — không tính vé | — |
| **Pro** (đã mua qua Stripe) | ∞ | — |
| User **đã verify** email | **15 bài** | theo **cửa sổ 5 giờ trượt** (rolling): bài cũ nhất trôi qua 5h thì được thêm vé |
| User **chưa verify** | **8 bài/ngày** | reset **00:00 giờ VN** |
| **Khách** (chưa đăng nhập) | **5 bài/ngày** | reset 00:00 giờ VN |

> 💡 *"5 giờ trượt" khác "reset 00:00" chỗ nào?* Reset 00:00 = mỗi nửa đêm làm mới sạch. Cửa sổ trượt = luôn nhìn lại **5 tiếng gần nhất tính từ bây giờ**; đọc đủ 15 bài thì phải đợi bài cũ nhất "trôi" ra khỏi mốc 5h mới mở thêm — mượt hơn, không dồn cục vào nửa đêm.

**Khi mở 1 bài — hàm `accessOrLock($article, $user)` (gọi từ `ArticleController::show`) chạy lần lượt:**
```
1. Admin?                 → cho xem, KHÔNG trừ vé
2. Pro / không giới hạn?  → cho xem
3. ĐÃ từng mở bài này?    → cho đọc lại MIỄN PHÍ (không trừ vé nữa)   ← UX tốt: đã mở là đọc lại thoải mái
4. Hết vé (remaining<=0)? → KHÓA bài → hiện nút "Đăng ký / Nâng cấp Pro"
5. Còn vé?                → recordUnlock() xé 1 vé → cho xem
```

**Vé được lưu ở bảng `article_unlocks`** (mỗi lần mở 1 bài mới = 1 dòng):
- User đăng nhập → lưu theo **`user_id`**.
- Khách → lưu theo **`session_id`** (vì chưa có tài khoản).
- Dùng `firstOrCreate` → mở lại đúng bài cũ không tạo dòng trùng.

**Đếm vé đã dùng:** user verify đếm các dòng trong **5 giờ gần nhất**; khách/user thường đếm từ **00:00 hôm nay** theo giờ `Asia/Ho_Chi_Minh`.

**`transferGuestUnlocks()` — mảnh ghép quan trọng:** khi khách **đăng nhập / đăng ký**, các vé đang gắn `session_id` được **dời sang `user_id`**. → Khách lỡ đọc 3 bài rồi mới đăng nhập thì **không bị tính lại từ đầu**. (Được gọi ở 3 nơi: login, register, **và Google callback** — chính là dòng `transferGuestUnlocks()` ở mục 14A.)

**Dọn rác:** command `unlocks:cleanup` chạy `dailyAt('02:15')` (xem `routes/console.php`) — xóa vé của **khách** cũ hơn 30 ngày để bảng không phình (class `CleanupOldGuestUnlocks`).

> 🔗 *Liên kết với mục 16:* hạng "Pro = ∞ vé" được quyết định bởi `User::isPro()` → `subscribed('pro')`, mà trạng thái `subscribed` lại do **Stripe webhook** cập nhật. Tức Reading Pass và thanh toán Stripe **nối với nhau ở đây**.

> 🎤 *Trả lời mẫu:* "Reading Pass là **paywall theo lượt đọc**, chia 5 hạng: admin/Pro vô hạn, user verify 15 bài/5 giờ trượt, user thường 8 bài/ngày, khách 5 bài/ngày. Mỗi bài mở mới ghi 1 dòng vào `article_unlocks`; **bài đã mở đọc lại free**. Khách lưu theo `session_id`, khi đăng nhập thì `transferGuestUnlocks()` dời sang tài khoản nên không mất vé. Hết vé thì khóa bài và mời nâng cấp. Logic gom vào tầng Service để controller chỉ gọi `accessOrLock()`."

---

## 15. 📡 LUỒNG RSS CHI TIẾT (trái tim hệ thống)

**3 cửa kích hoạt** (đều chạy chung 1 lõi `FeedIngestionService::fetchSource()`):
| Cửa | Khi nào | Lệnh/Code |
|---|---|---|
| **Tự động** | mỗi 30 phút | `Schedule::command('feeds:fetch')->everyThirtyMinutes()` — `routes/console.php:11` |
| **Thủ công (CLI)** | gõ tay khi demo | `php artisan feeds:fetch` (tất cả) hoặc `--source=ID` (1 nguồn) |
| **Nút "Retry" admin** | 1 nguồn lỗi, muốn thử lại | đẩy vào queue qua `FetchSourceFeedJob` (chạy nền) |

**Đường đi của dữ liệu (file `app/Services/Rss/FeedIngestionService.php`):**
```
feeds:fetch  →  FetchFeeds::handle()  (app/Console/Commands/FetchFeeds.php)
   • lấy các Source is_active=true  (when --source thì lọc đúng 1 nguồn)
   • lặp TỪNG nguồn trong try/catch  ← 1 nguồn chết, các nguồn khác VẪN chạy
        ↓ gọi
FeedIngestionService::fetchSource($source)
   ① Tạo 1 dòng SourceFetchLog (status='running')        ← nhật ký kiểm tra (audit)
   ② requestFeedItems(): Http::get(feed_url)             ← User-Agent "VietFeedBot", timeout 15s
        - site nào trả HTML + cookie JS (vd Lao Động) → tự trích cookie, gọi LẠI 1 lần
        - HTTP lỗi 429/5xx → đánh dấu "thử lại được"; 4xx khác → "không thử lại"
        - parse bằng simplexml_load_string(... LIBXML_NOCDATA)  ← đọc đúng tiếng Việt trong CDATA
   ③ buildMetrics(): duyệt từng <item>:
        - lấy link (=original_url) + title; thiếu 1 trong 2 → bỏ qua (invalid)
        - description: strip_tags + html_entity_decode (giải mã ký tự tiếng Việt)
        - ảnh: ưu tiên <enclosure> → <media:content> → regex thẻ <img> trong mô tả
        - ngày: Carbon::parse(pubDate)
        - 🔑 updateOrCreate(['original_url'=>$link], [...])  ← CHỐNG TRÙNG (original_url là UNIQUE)
              · wasRecentlyCreated == true  → bài MỚI  (items_created++)
              · ngược lại                   → bài đã có → cập nhật (items_updated++)
   ④ determineOutcomeStatus(): success / warning / failed
        (warning nếu: tỉ lệ item hợp lệ thấp, tỉ lệ trùng quá cao, hoặc fetch quá chậm — ngưỡng ở config/source_health.php)
   ⑤ syncSuccessfulRun()/syncFailedRun(): cập nhật "sức khỏe nguồn" lên bảng sources
        (last_fetched_at, consecutive_failures, last_error_*, …) + chốt lại dòng SourceFetchLog
```

**Vì sao chống trùng được?** `articles.original_url` có ràng buộc **UNIQUE**. `updateOrCreate` tìm theo `original_url`: có rồi thì **update**, chưa có thì **create**. → chạy `feeds:fetch` 100 lần cũng **không sinh bài lặp**. (Slug cũng unique: `Str::slug(title) + 8 ký tự md5(url)`.)

**Vì sao bền (resilient)?**
- `try/catch` **bao từng nguồn** trong `FetchFeeds` → 1 nguồn sập không kéo sập cả lệnh.
- Lỗi chia 2 loại: **retryable** (429/500/502/503/504, timeout) vs **non-retryable** (XML hỏng, feed rỗng). Chỉ retryable mới đáng thử lại.
- `FetchSourceFeedJob`: `$tries = 5`, **backoff tăng dần** `[5, 30, 120, 600, 1800]` giây (`config/source_health.php`) — thử lại thưa dần thay vì dồn dập.
- Mọi lần fetch đều ghi `source_fetch_logs` → admin xem lại được lịch sử & lỗi.

**"Test RSS" khác "Fetch" chỗ nào?** `testSource()` chạy **dry-run**: tải + parse + đếm item nhưng **KHÔNG lưu bài** (`$dryRun=true` nên bỏ qua `updateOrCreate`). Dùng để kiểm tra nhanh feed còn sống không trước khi lưu thật.

> 🎤 *Trả lời mẫu:* "Lệnh `feeds:fetch` (chạy tay hoặc scheduler 30 phút/lần) gọi `FeedIngestionService`: tải RSS bằng HTTP client, parse XML bằng SimpleXML (có xử lý CDATA cho tiếng Việt), trích ảnh theo thứ tự ưu tiên, rồi **`updateOrCreate` theo `original_url`** để chống trùng. Em bọc `try/catch` từng nguồn, phân biệt lỗi thử-lại-được để retry qua Queue với backoff, và ghi log mỗi lần fetch vào `source_fetch_logs` để theo dõi sức khỏe nguồn."

---

## 16. 💳 THANH TOÁN PRO QUA STRIPE + WEBHOOK

**Dùng package `laravel/cashier`** (Stripe). Model `User` khai báo `use Billable` → có sẵn các hàm `newSubscription()`, `subscribed()`, `redirectToBillingPortal()`, cột `stripe_id`… `User::isPro()` chỉ đơn giản là **`return $this->subscribed('pro')`**.

**Luồng mua (file `BillingController.php`):**
```
① /pricing → BillingController::pricing()  hiển thị bảng giá (báo đã cấu hình giá chưa, user đã Pro chưa)
② User bấm "Mua Pro" → POST /billing/checkout → checkout():
     - chưa cấu hình STRIPE_PRO_PRICE_ID → báo lỗi
     - đã Pro rồi → khỏi mua lại
     - $user->newSubscription('pro', $priceId)->checkout([success_url, cancel_url, metadata])
          → trả về REDIRECT sang trang thanh toán do STRIPE host
③ User nhập thẻ & trả tiền NGAY TRÊN trang Stripe   ← VietFeed KHÔNG hề thấy số thẻ (Stripe lo hết, đạt PCI)
④ Trả tiền xong → Stripe đẩy về success_url → /billing/success → chỉ hiện thông báo
     "Stripe đã ghi nhận, Pro sẽ bật sau khi webhook cập nhật"
```

**🔑 Điểm cốt lõi (thầy rất hay xoáy): vì sao cần WEBHOOK?**
Trang `success_url` **KHÔNG phải bằng chứng đã trả tiền** — user có thể đóng trình duyệt trước khi quay về, hoặc thanh toán xử lý bất đồng bộ. Thứ **đáng tin** là **webhook**: Stripe **gọi thẳng server-to-server** vào app để báo "giao dịch này đã xong".
```
Stripe  ──(sự kiện)──►  POST /stripe/webhook   (Cashier tự đăng ký route, path từ config 'stripe')
   • Cashier dùng STRIPE_WEBHOOK_SECRET kiểm CHỮ KÝ request (tolerance 300s)  ← chống kẻ giả mạo webhook
   • Cashier xử lý các event mặc định: customer.subscription.created/updated/deleted,
     invoice.payment_succeeded, ...
   • → tự ghi/cập nhật bảng `subscriptions` + `subscription_items` trong DB của mình
→ Khi đã có dòng subscription 'pro' đang active:  $user->subscribed('pro') == true
   → isPro() == true  →  Reading Pass cho đọc KHÔNG GIỚI HẠN (nối với mục 14B)
```
👉 **Tóm tắt tư duy:** *Stripe là "nguồn chân lý" về việc đã trả tiền; DB của mình chỉ là bản sao trạng thái, được webhook đồng bộ về.* Bật Pro **không** xảy ra ở nút bấm hay trang success, mà ở **webhook**.

**Quản lý sau khi mua:** `/billing/portal` → `redirectToBillingPortal()` đưa user sang **Stripe Customer Portal** (xem hóa đơn, đổi thẻ, hủy gói).

**Cấu hình & test cục bộ:**
- `.env`: `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_PRO_PRICE_ID`, `STRIPE_WEBHOOK_SECRET`. Config ở `config/cashier.php`.
- Khi dev: chạy `stripe listen --forward-to vietfeed.test/stripe/webhook` (Stripe CLI) để chuyển tiếp webhook về máy local; thẻ test `4242 4242 4242 4242`.

> 🎤 *Trả lời mẫu:* "Gói Pro dùng **Laravel Cashier**. Bấm mua → `newSubscription('pro')->checkout()` đẩy user sang trang thanh toán của Stripe, **app không chạm vào số thẻ**. Việc bật Pro **không** dựa vào trang success (không đáng tin) mà dựa vào **webhook**: Stripe gọi `POST /stripe/webhook`, Cashier **xác thực chữ ký** bằng `STRIPE_WEBHOOK_SECRET` rồi cập nhật bảng `subscriptions`. Sau đó `subscribed('pro')` trả true → `isPro()` true → Reading Pass thành không giới hạn."
