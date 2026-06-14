# Tài liệu Use Case & Quy trình nghiệp vụ — VietFeed

**Phiên bản:** 1.0  
**Ngày:** 2026-06-08  
**Dự án:** VietFeed — Vietnamese News Aggregator (Laravel 13 + MySQL)  
**Mục đích:** Tổng hợp toàn bộ tính năng, quy trình và luồng dữ liệu để teammates vẽ sơ đồ Use Case (UML), Activity Diagram và Sequence Diagram.

---

## 1. Tổng quan hệ thống

VietFeed là nền tảng tổng hợp tin tức tiếng Việt tự động qua RSS. Hệ thống cung cấp:

- **Tự động thu thập tin** từ nhiều nguồn báo (VnExpress, Tuổi Trẻ, Thanh Niên, Dân Trí, v.v.).
- **Cá nhân hóa** nội dung dựa trên chủ đề yêu thích.
- **Tương tác:** bookmark, boost (đẩy tin), bình luận đa cấp (threaded).
- **Tìm kiếm:** tìm nhanh (live search AJAX) và tìm kiếm trang kết quả.
- **Gói Pro:** thanh toán qua Stripe, mở khóa giới hạn đọc tin.
- **Quản trị:** dashboard thống kê, kiểm duyệt nguồn tin, bài viết, bình luận, người dùng.

---

## 2. Các Actor (Tác nhân)

| Actor | Mô tả |
|-------|-------|
| **Guest** | Khách vãng lai, chưa đăng nhập. Chỉ xem tin, tìm kiếm, xem ticker. |
| **User** | Người dùng đã đăng ký, đăng nhập, xác minh email. Có thể bookmark, comment, boost, cá nhân hóa, đăng ký Pro. |
| **Admin** | Quản trị viên (`role = admin`). Quản lý toàn bộ hệ thống. |
| **System** | Tác nhân hệ thống: scheduler, queue worker, RSS fetcher, Stripe webhook. |

---

## 3. Phân rã Use Case theo Module

### 3.1 Module: Xác thực & Quản lý Tài khoản (Auth)

#### UC-A1: Đăng ký tài khoản
- **Actor:** Guest
- **Mô tả:** Tạo tài khoản mới qua email/password.
- **Preconditions:** Chưa đăng nhập.
- **Main Flow:**
  1. Guest truy cập `/register`.
  2. Nhập **Tên**, **Email**, **Mật khẩu**, **Xác nhận mật khẩu**.
  3. Hệ thống validate: email unique, password confirmed, đúng định dạng.
  4. Tạo User với `role = user`, mật khẩu đã hash.
  5. Phát sự kiện `Registered` → gửi email xác minh.
  6. Đăng nhập tự động, regenerate session.
  7. Chuyển guest bookmarks (nếu có session ID) sang tài khoản mới.
  8. Redirect đến `/dashboard` (rồi chuyển tiếp `/onboarding/interests`).
- **Alternative Flows:**
  - Email đã tồn tại → báo lỗi.
  - Password không khớp confirm → báo lỗi validation.
- **Post-conditions:** Tài khoản được tạo, chưa xác minh email (`show_verify_banner`).

#### UC-A2: Đăng nhập (Email/Password)
- **Actor:** Guest / User
- **Mô tả:** Đăng nhập bằng thông tin đã đăng ký.
- **Main Flow:**
  1. Truy cập `/login`.
  2. Nhập email, password.
  3. Hệ thống xác thực.
  4. Nếu đúng → regenerate session, redirect `/dashboard`.
  5. Nếu user là Admin → redirect `/admin/dashboard`.

#### UC-A3: Đăng nhập bằng Google (OAuth)
- **Actor:** Guest
- **Main Flow:**
  1. Click "Đăng nhập với Google" → redirect đến Google OAuth.
  2. Google callback → hệ thống tạo/tìm User theo `google_id`.
  3. Đăng nhập tự động.

#### UC-A4: Xác minh Email
- **Actor:** User (chưa verified)
- **Main Flow:**
  1. Sau đăng ký, hệ thống gửi email chứa link có signed URL (`/verify-email/{id}/{hash}`).
  2. User click link trong email.
  3. Hệ thống đánh dấu `email_verified_at`.
  4. Redirect về home.
- **Alternative:** User chưa nhận được → nhấn "Gửi lại" (`/email/verification-notification`), giới hạn 6 lần/phút.

#### UC-A5: Quên / Đặt lại mật khẩu
- **Actor:** Guest
- **Main Flow:**
  1. Truy cập `/forgot-password`, nhập email.
  2. Hệ thống gửi link reset chứa token.
  3. Guest click link `/reset-password/{token}`.
  4. Nhập mật khẩu mới + confirm.
  5. Cập nhật password, redirect login.

#### UC-A6: Đổi mật khẩu
- **Actor:** User (đã đăng nhập)
- **Main Flow:**
  1. Vào Profile → đổi mật khẩu (yêu cầu nhập current password).
  2. Cập nhật `password` (hashed).

#### UC-A7: Cập nhật Hồ sơ (Profile)
- **Actor:** User
- **Main Flow:**
  1. Truy cập `/profile` (auth, chưa cần verified).
  2. Cập nhật **Tên**, **Email**.
  3. Nếu đổi email → xóa `email_verified_at` (buộc xác minh lại).
  4. Có thể upload/update **avatar**.

#### UC-A8: Xóa tài khoản
- **Actor:** User
- **Main Flow:**
  1. Truy cập Profile → yêu cầu nhập current password để xác nhận.
  2. Logout, invalidate session, xóa User và dữ liệu liên quan (cascade bookmarks, comments, boosts).

---

### 3.2 Module: Khám phá & Đọc tin (Public Browsing)

#### UC-B1: Xem Trang chủ (Homepage)
- **Actor:** Guest / User
- **Mô tả:** Hiển thị layout tạp chí (magazine) với nhiều vùng nội dung.
- **Dữ liệu hiển thị:**
  - **Featured:** Bài có điểm "sensational" cao nhất (tính từ bookmarks, comments, prestige nguồn, độ mới).
  - **Hero Grid:** 5 bài nhỏ theo sở thích User (nếu đã chọn), nếu không thì tổng hợp.
  - **Breaking:** 5 bài mới nhất, đa dạng chủ đề.
  - **Community Boosted:** 6 bài được boost nhiều nhất trong 72 giờ.
  - **Main Feed:** Phân trang 12 bài/trang, cá nhân hóa theo favorite categories. Có **Infinite Scroll** (AJAX load more).
  - **Trending Sidebar:** 5 bài boost nhiều nhất 72 giờ.
  - **Category Tabs:** Sticky tab chuyển nhanh giữa các chủ đề.
- **Điều kiện cá nhân hóa:** Nếu User đã đăng nhập và có `favoriteCategories`, Main Feed lọc theo các category đó.

#### UC-B2: Xem Danh sách Bài viết
- **Actor:** Guest / User
- **Main Flow:**
  1. Truy cập `/articles`.
  2. Lọc theo **Category**, **Source**, **Từ khóa** (`q`).
  3. Phân trang 12 bài/trang (paginate chuẩn, **không** infinite scroll).
  4. Mỗi card hiển thị: ảnh, tiêu đề, mô tả, nguồn, thời gian, số bookmarks, số boosts.

#### UC-B3: Xem Chi tiết Bài viết
- **Actor:** Guest / User
- **Main Flow:**
  1. Click bài viết → `/articles/{slug}`.
  2. Hiển thị: tiêu đề, ảnh, mô tả, metadata (nguồn, chủ đề, thời gian đọc ước tính).
  3. Nút "Đọc bài gốc" (link đến `original_url`).
  4. Nút Bookmark (toggle), Boost (toggle).
  5. **Reading Pass Check:** Nếu chưa đăng ký Pro, hệ thống kiểm tra giới hạn đọc miễn phí (`ArticleUnlock`). Nếu vượt quá, hiển thị khóa + CTA nâng cấp Pro.
  6. Nếu có quyền đọc: hiển thị bình luận (threaded comments + replies).
  7. Hiển thị **Bài liên quan** (10 bài cùng chủ đề hoặc mới nhất).
  8. Nút chia sẻ: Copy link, Facebook, Twitter/X.

#### UC-B4: Xem Tin theo Chủ đề (Category)
- **Actor:** Guest / User
- **Main Flow:**
  1. Truy cập `/categories/{slug}`.
  2. Hiển thị bài viết thuộc category đó, phân trang chuẩn.

#### UC-B5: Tìm kiếm (Search Page)
- **Actor:** Guest / User
- **Main Flow:**
  1. Nhập từ khóa (`q`) tại `/search`, tối thiểu 2 ký tự.
  2. Tìm trong `title` và `description` (LIKE).
  3. Kết quả phân trang 12 bài, có thể lọc thêm category/source.

#### UC-B6: Tìm kiếm trực tiếp (Live Search)
- **Actor:** Guest / User
- **Main Flow:**
  1. Gõ từ khóa trên thanh navbar (debounce 300ms).
  2. AJAX gọi `/api/live-search`.
  3. Trả về JSON 6 kết quả: tiêu đề, ảnh, category, thời gian.
  4. Hiển thị dropdown skeleton loading trong lúc chờ.

#### UC-B7: Xem Ticker (Giá vàng / Chứng khoán)
- **Actor:** Guest / User
- **Main Flow:**
  1. Trang chủ gọi AJAX `/api/ticker`.
  2. TickerService lấy dữ liệu giá vàng / chứng khoán.
  3. Hiển thị marquee/ribbon trên navbar.

---

### 3.3 Module: Tương tác Người dùng (Bookmarks, Comments, Boosts)

#### UC-I1: Bookmark / Bỏ bookmark Bài viết
- **Actor:** User (đã đăng nhập, **bắt buộc verified email**)
- **Main Flow:**
  1. User click nút Bookmark (trên card hoặc trang chi tiết).
  2. AJAX POST `/bookmarks/toggle` với `article_id`.
  3. Nếu chưa bookmark → tạo record, trả JSON `action: added` + tổng số bookmark.
  4. Nếu đã bookmark → xóa record, trả JSON `action: removed`.
  5. UI cập nhật: icon trái tim đổi màu + hiệu ứng pop animation.
- **Exception:** User chưa xác minh email → trả 403 + thông báo "Vui lòng xác minh email".

#### UC-I2: Xem Danh sách Bookmark của tôi
- **Actor:** User (đã đăng nhập, verified)
- **Main Flow:**
  1. Truy cập `/bookmarks`.
  2. Hiển thị grid các bài đã bookmark (lấy từ bảng `bookmarks`, eager load `article.source`, `article.category`).

#### UC-I3: Boost / Bỏ boost Bài viết
- **Actor:** User (đã đăng nhập)
- **Mô tả:** "Đẩy tin" để tăng độ nổi bật trong Community Boosted & Trending.
- **Main Flow:**
  1. User click nút Boost (tia sét / lửa).
  2. AJAX POST `/boosts/toggle` với `article_id`.
  3. Toggle tương tự Bookmark: thêm/xóa record trong `boosts`.
  4. Trả JSON `action` + `count` tổng boosts.

#### UC-I4: Đăng Bình luận (Comment)
- **Actor:** User (đã đăng nhập, verified)
- **Main Flow:**
  1. Ở trang chi tiết bài viết, nhập nội dung bình luận (max 2000 ký tự).
  2. POST `/articles/{article:slug}/comments`.
  3. Nếu có `parent_id` → đây là **Trả lời** (reply) cho bình luận cha.
  4. Hệ thống lưu vào `comments` (user_id, article_id, parent_id, body).
  5. Reload trang hoặc append comment mới vào thread.

#### UC-I5: Sửa Bình luận
- **Actor:** User (chỉ chủ sở hữu)
- **Main Flow:**
  1. Click "Sửa" trên comment của mình.
  2. PUT `/comments/{comment}` với `body` mới.
  3. Validate `body`, cập nhật record.

#### UC-I6: Xóa Bình luận
- **Actor:** User (chủ sở hữu) hoặc Admin
- **Main Flow:**
  1. Click "Xóa".
  2. DELETE `/comments/{comment}`.
  3. Hệ thống kiểm tra: `user_id === Auth::id()` hoặc `Auth::user()->isAdmin()`.
  4. Xóa record (cascade replies nếu DB có cascade, hoặc chỉ xóa bản thân).

---

### 3.4 Module: Cá nhân hóa & Onboarding

#### UC-P1: Chọn Sở thích sau Đăng ký (Onboarding)
- **Actor:** User (mới đăng ký, chưa chọn sở thích)
- **Main Flow:**
  1. Sau khi đăng ký, redirect `/onboarding/interests`.
  2. Hiển thị danh sách Category đang active.
  3. User chọn 1 hoặc nhiều chủ đề.
  4. POST `/onboarding/interests` → sync pivot bảng `category_user`.
  5. Redirect về Home.
- **Ghi chú:** Nếu User chưa chọn sở thích, Home vẫn hiển thị tất cả bài viết.

#### UC-P2: Cập nhật Sở thích (Preferences)
- **Actor:** User (đã verified)
- **Main Flow:**
  1. Truy cập Profile → tab Preferences.
  2. Chọn/bỏ chọn favorite categories.
  3. PUT `/profile/preferences` → sync `category_user`.
  4. Feed trang chủ được cá nhân hóa lại ngay lần load sau.

#### UC-P3: Xem Reading Pass (Giới hạn đọc miễn phí)
- **Actor:** User (đã đăng nhập)
- **Main Flow:**
  1. Truy cập `/profile/reading-pass`.
  2. Hiển thị: số bài đã đọc / giới hạn miễn phí, số bài còn lại hôm nay.
  3. Nếu là Pro → hiển thị "Không giới hạn".
- **Business Rule:** Guest/User miễn phí có giới hạn số bài đọc chi tiết/ngày. Mỗi lần mở bài mới tạo `ArticleUnlock`. Vượt quá → yêu cầu nâng cấp Pro.

---

### 3.5 Module: Thanh toán & Gói Pro (Billing)

#### UC-PAY1: Xem Trang Pricing
- **Actor:** User (đã đăng nhập)
- **Main Flow:**
  1. Truy cập `/pricing`.
  2. Hiển thị các gói: Free vs Pro.
  3. Kiểm tra `isPro()` → nếu đã Pro thì hiển thị trạng thái hiện tại.

#### UC-PAY2: Đăng ký Gói Pro (Stripe Checkout)
- **Actor:** User (đã đăng nhập, verified)
- **Main Flow:**
  1. Click "Nâng cấp Pro".
  2. POST `/billing/checkout`.
  3. Hệ thống tạo Stripe Checkout Session với `price_id` (cấu hình trong `.env`).
  4. Redirect user đến trang thanh toán Stripe.
  5. Thành công → Stripe webhook cập nhật subscription status (`active`).
  6. User được redirect về `/billing/success` → chuyển tiếp profile reading-pass.
- **Exception:** Chưa cấu hình `STRIPE_PRO_PRICE_ID` → báo lỗi.

#### UC-PAY3: Quản lý Thanh toán (Customer Portal)
- **Actor:** User (đã có subscription)
- **Main Flow:**
  1. Truy cập `/billing/portal`.
  2. Redirect đến Stripe Customer Portal để hủy/đổi gói, cập nhật thẻ.

---

### 3.6 Module: Quản trị Hệ thống (Admin)

**Lưu ý:** Tất cả route bắt đầu bằng `/admin/*`, yêu cầu middleware `auth` + `admin`. Nếu không phải admin → abort 403.

#### UC-AD1: Xem Dashboard Thống kê
- **Actor:** Admin
- **Dữ liệu hiển thị:**
  - Summary cards: Tổng Articles, Users, Comments, Sources active, Bookmarks, Boosts, Unlocks hôm nay, Pro Users.
  - Biểu đồ (Chart.js):
    - Articles/ngày (30 ngày) — line/area.
    - Users/ngày (30 ngày) — line.
    - Articles per Category — donut.
    - Articles per Source — horizontal bar.
  - Danh sách: Most Bookmarked (top 10), Most Boosted (top 10, 72h).
  - Recent Activity: 8 bài mới nhất, 6 bình luận mới nhất.
  - Source Health: trạng thái của các nguồn tin (healthy, warning, stale, failed, critical, disabled, never_fetched).
  - Problem Sources: 5 nguồn gặp sự cố nghiêm trọng nhất.

#### UC-AD2: Quản lý Nguồn tin (Sources) — CRUD
- **Actor:** Admin
- **Tính năng:**
  1. **List:** `/admin/sources` — bảng nguồn tin, tìm kiếm theo tên, sort theo tên / số bài / lần fetch cuối, phân trang 20.
  2. **Create:** Thêm nguồn mới (tên, URL trang chủ, URL RSS, logo, chọn Category, prestige).
  3. **Show:** Chi tiết nguồn + danh sách bài viết + log fetch gần đây.
  4. **Edit:** Cập nhật thông tin nguồn.
  5. **Delete:** Xóa nguồn (cascade articles tùy cấu hình).

#### UC-AD3: Kiểm tra & Giám sát Sức khỏe Nguồn tin (Source Health)
- **Actor:** Admin
- **Main Flow:**
  1. Truy cập `/admin/sources/health`.
  2. Xem tất cả nguồn với trạng thái sức khỏe tự động tính toán:
     - `healthy`: fetch gần đây thành công.
     - `warning/stale/failed/critical`: dựa trên `consecutive_failures`, thời gian since last success.
     - `disabled`: `is_active = false`.
  3. **Test RSS:** Chọn nguồn → POST `/admin/sources/{source}/test`. Hệ thống chạy `FeedIngestionService::testSource()`, trả kết quả: HTTP status, số items, valid items, missing images, sample items, lỗi nếu có.
  4. **Retry Fetch:** Chọn nguồn lỗi → POST `/admin/sources/{source}/retry`. Dispatch `FetchSourceFeedJob` vào queue.
  5. **Toggle Active:** PATCH `/admin/sources/{source}/toggle-active` — bật/tắt nguồn.
  6. **View Logs:** Xem lịch sử fetch (`source_fetch_logs`) của từng nguồn.

#### UC-AD4: Quản lý Chủ đề (Categories) — CRUD
- **Actor:** Admin
- **Tính năng:**
  1. **List:** `/admin/categories` — tìm kiếm, lọc active/inactive, sort.
  2. **Create:** Thêm category mới (name, slug tự động, `is_active = true`).
  3. **Edit:** Cập nhật name/slug.
  4. **Toggle Active:** Bật/tắt category. Khi tắt → user không thấy bài thuộc category này trên public.
  5. **Delete:** Chỉ xóa nếu category **không có** bài viết và nguồn tin nào.

#### UC-AD5: Quản lý Bài viết (Articles) — CRUD + Bulk
- **Actor:** Admin
- **Tính năng:**
  1. **List:** `/admin/articles` — tìm kiếm theo title/description, lọc category, source, date range, sort theo bookmarks/boosts/time, phân trang 20.
  2. **Show:** Chi tiết bài + comments + số liệu (bookmarks, boosts, unlocks).
  3. **Edit:** Cập nhật title, description, image_url, category, source, published_at. Nếu đổi title → regenerate slug unique.
  4. **Delete:** Xóa từng bài.
  5. **Bulk Delete:** Chọn nhiều bài (checkbox) → POST `/admin/articles/bulk-destroy`.

#### UC-AD6: Kiểm duyệt Bình luận (Comment Moderation)
- **Actor:** Admin
- **Tính năng:**
  1. **List:** `/admin/comments` — tìm kiếm theo nội dung, phân trang 25.
  2. **Delete:** Xóa bất kỳ bình luận (kể cả không phải của mình).

#### UC-AD7: Quản lý Người dùng (Users)
- **Actor:** Admin
- **Tính năng:**
  1. **List:** `/admin/users` — tìm kiếm theo name/email, lọc Pro users, sort, phân trang 20. Hiển thị số comments, bookmarks, unlocks, reading pass allowance.
  2. **Edit:** Cập nhật name, email, **role** (`user` / `admin`).
  3. **Delete:** Xóa user. **Không thể xóa chính mình** (tài khoản đang đăng nhập).

---

### 3.7 Module: Hệ thống Tự động (System)

#### UC-SYS1: Thu thập RSS Tự động (Fetch Feeds)
- **Actor:** System (Scheduler + Queue Worker)
- **Trigger:** Laravel Scheduler chạy mỗi 30 phút (`routes/console.php`).
- **Main Flow:**
  1. Scheduler gọi command `feeds:fetch`.
  2. Lặp qua các `Source::where('is_active', true)`.
  3. Với mỗi source:
     - `Http::get(feed_url)` với timeout 15s.
     - Parse XML bằng `SimpleXML` (decode entities cho tiếng Việt).
     - Với mỗi `<item>`:
       - Extract ảnh từ `<enclosure>`, `<media:content>`, hoặc regex `<img>` trong description.
       - `Article::updateOrCreate(['original_url' => $link], [...])` để tránh duplicate.
     - Cập nhật `source->last_fetched_at`.
     - Log số bài mới/cập nhật.
  4. Nếu lỗi → catch exception, log lỗi, tiếp tục source tiếp theo.
- **Ngoại lệ:** Source lỗi nhiều lần liên tiếp → `consecutive_failures` tăng, health status chuyển `warning` → `critical`.

#### UC-SYS2: Cập nhật Ticker Tự động
- **Actor:** System
- **Main Flow:**
  1. Frontend gọi AJAX `/api/ticker`.
  2. `TickerService` lấy dữ liệu từ nguồn bên ngoài (giá vàng SJC / chứng khoán VN-Index).
  3. Cache / trả JSON.

#### UC-SYS3: Xử lý Webhook Stripe
- **Actor:** System
- **Main Flow:**
  1. Stripe gửi webhook events (checkout.session.completed, invoice.paid, v.v.).
  2. Hệ thống xác thực signature Stripe.
  3. Cập nhật bảng `subscriptions` (Laravel Cashier): `stripe_status`, `type = pro`.
  4. User `isPro()` được cập nhật real-time.

---

## 4. Bảng Phân quyền Chức năng (Authorization Matrix)

| Chức năng | Guest | User (Unverified) | User (Verified) | Admin |
|-----------|-------|-------------------|-----------------|-------|
| Xem Home, Articles, Category, Search, Ticker | ✅ | ✅ | ✅ | ✅ |
| Live Search | ✅ | ✅ | ✅ | ✅ |
| Xem Chi tiết Bài viết (trừ comments nếu bị khóa) | ✅ | ✅ | ✅ | ✅ |
| Đăng ký / Đăng nhập / OAuth | ✅ | — | — | — |
| Xác minh Email | — | ✅ | — | — |
| Onboarding (Chọn sở thích) | — | ✅ | ✅ | — |
| Bookmark / Bỏ bookmark | — | ❌ | ✅ | ✅ |
| Xem Bookmarks của mình | — | ❌ | ✅ | ✅ |
| Boost / Bỏ boost | — | ✅ | ✅ | ✅ |
| Bình luận / Trả lời | — | ❌ | ✅ | ✅ |
| Sửa bình luận của mình | — | ❌ | ✅ | ✅ |
| Xóa bình luận của mình | — | ❌ | ✅ | ✅ |
| Cập nhật Profile / Preferences | — | ✅ | ✅ | ✅ |
| Xem Reading Pass | — | ✅ | ✅ | ✅ |
| Nâng cấp Pro (Stripe) | — | ✅ | ✅ | ✅ |
| Quản lý Subscription (Portal) | — | ✅ | ✅ | ✅ |
| Truy cập `/admin/*` | ❌ | ❌ | ❌ | ✅ |
| Dashboard thống kê | ❌ | ❌ | ❌ | ✅ |
| CRUD Sources, Categories | ❌ | ❌ | ❌ | ✅ |
| CRUD Articles (admin edit/delete/bulk) | ❌ | ❌ | ❌ | ✅ |
| Moderate Comments (xóa bất kỳ) | ❌ | ❌ | ❌ | ✅ |
| Quản lý Users (edit role, delete) | ❌ | ❌ | ❌ | ✅ |
| Chạy RSS Test / Retry | ❌ | ❌ | ❌ | ✅ |

---

## 5. Luồng dữ liệu tổng quan (Context cho Activity Diagram)

```
[RSS Sources] --(HTTP GET)--> [FeedIngestionService]
                                    |
                                    v
[SimpleXML Parse] --> [Image Extraction] --> [Article::updateOrCreate]
                                    |
                                    v
                              [MySQL Database]
                                    |
                    +---------------+---------------+
                    |               |               |
              [Homepage]     [Search]       [Admin Dashboard]
                    |               |               |
              [User View]    [User View]     [Admin Actions]
                    |               |               |
              [Bookmark]     [Comment]        [CRUD]
              [Boost]        [Share]
```

---

## 6. Ghi chú cho Teammates vẽ Diagram

### 6.1 Use Case Diagram (UML)
- Vẽ 3 actor chính: **Guest**, **User**, **Admin**.
- Group các use case theo **System Boundary** (hệ thống VietFeed).
- Dùng `<<include>>` nếu có use case bắt buộc gọi use case khác (ví dụ: *Đăng ký* `<<include>>` *Xác minh Email* — tùy cách hiểu, thực tế là phát sự kiện gửi mail, không nhất thiết phải include).
- Dùng `<<extend>>` cho các trường hợp ngoại lệ (ví dụ: *Đọc bài chi tiết* `<<extend>>` *Yêu cầu nâng cấp Pro* khi vượt giới hạn).

### 6.2 Activity Diagram (cần thiết cho các flow phức tạp)
Nên vẽ activity diagram cho:
- **Đăng ký → Onboarding → Cá nhân hóa Feed**
- **Đọc bài viết chi tiết (có kiểm tra Reading Pass)**
- **Admin: Test RSS → Retry → Health Update**
- **System: Fetch Feeds (loop qua sources, try/catch, updateOrCreate)**

### 6.3 Sequence Diagram (tùy chọn, nếu cần)
- **Bookmark Toggle:** User → Browser → `BookmarkController::toggle` → `bookmarks` table → JSON response → UI update.
- **Stripe Checkout:** User → `BillingController::checkout` → Stripe API → Webhook → Cashier/DB → Redirect.

---

## 7. Từ điển thuật ngữ (Glossary)

| Thuật ngữ | Giải thích |
|-----------|------------|
| **Source** | Nguồn tin RSS (một trang báo hoặc chuyên mục báo). |
| **Category** | Chủ đề lớn (Thời sự, Công nghệ, Thể thao...). |
| **Article** | Bài viết được crawl từ RSS, lưu title, description, image, original_url. |
| **Bookmark** | Lưu bài viết để đọc lại sau. |
| **Boost** | "Đẩy tin" — tương tác giúp bài nổi bật hơn trong trending. |
| **Reading Pass** | Giới hạn số bài đọc chi tiết/ngày đối với user miễn phí. |
| **ArticleUnlock** | Bản ghi mỗi lần user đọc một bài (dùng để tính reading pass). |
| **Source Health** | Trạng thái sức khỏe nguồn RSS dựa trên tần suất lỗi fetch. |
| **Onboarding** | Quy trình chào mừng user mới: chọn chủ đề yêu thích. |

---

*Hết tài liệu.*
