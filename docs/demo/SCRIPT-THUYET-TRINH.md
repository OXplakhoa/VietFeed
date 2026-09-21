# 🎬 SCRIPT THUYẾT TRÌNH VietFeed (bản nói thành lời)

> **Cách dùng:** đây là bản để **đọc/tập nói**. Chữ thường = lời thoại nói ra. `[trong ngoặc vuông]` = thao tác bạn làm trên màn hình. 🎤 = câu chốt "ăn điểm". ⏱️ = mốc thời gian gợi ý (tổng ~12–15 phút + demo).
> **Cặp đôi với** `CHEAT-SHEET-THUYET-TRINH.md` (mở tab bên cạnh để tra khi thầy hỏi xoáy).
> **Chuẩn bị trước khi vào phòng:** chạy `composer run dev` (serve + queue + vite) · đăng nhập sẵn 1 tab admin · chạy thử `php artisan feeds:fetch --source=<ID>` 1 lần để chắc mạng OK · mở sẵn các tab: trang chủ, `/admin/dashboard`, VS Code, terminal.

---

## PHẦN 0 — MỞ MÀN ⏱️ 0:00–0:45

> Em chào thầy/cô và các bạn. Nhóm em xin trình bày đồ án **VietFeed — trang tổng hợp tin tức tiếng Việt**.
>
> Ý tưởng: thay vì phải vào từng báo VnExpress, Tuổi Trẻ, Thanh Niên… để đọc, VietFeed **tự động kéo bài từ tất cả các nguồn đó về một chỗ** qua chuẩn **RSS**, và cá nhân hóa feed theo sở thích người dùng.
>
> Hệ thống có 3 nhóm người dùng: **khách**, **người dùng đã đăng nhập**, và **admin**. Em sẽ demo lần lượt theo hành trình đó, rồi đi sâu vào kỹ thuật.
>
> 🎤 Stack: **Laravel 13, PHP 8.3, MySQL**, giao diện **Blade + Bootstrap 5 + Alpine.js**. Kiến trúc **MVC**, và em mở rộng thêm 3 tầng: **Service** (logic nghiệp vụ nặng), **Job** (chạy nền), **Form Request** (validate) — để controller luôn mỏng, dễ bảo trì.

---

## PHẦN 1 — DEMO THEO HÀNH TRÌNH NGƯỜI DÙNG ⏱️ 0:45–6:00

### 1.1 — Khách vào trang chủ `[mở trang chủ /]`
> Đây là trang chủ dưới dạng **tạp chí** (magazine layout): có bài nổi bật (featured), tin nóng (breaking), và khu **"cộng đồng bình chọn"**.
>
> `[chỉ lên thanh ticker]` Trên cùng là **thanh tỉ giá USD/vàng**, nó **tự cập nhật mỗi 60 giây bằng AJAX** mà không cần load lại trang.
>
> `[cuộn xuống cuối trang]` Khi cuộn tới cuối, trang **tự nạp thêm bài** — đây là **infinite scroll**, cũng bằng AJAX, gọi `GET /api/articles` trả về JSON rồi chèn vào.
>
> `[bấm nút dark mode 🌙]` Có cả **chế độ tối**, lưu lựa chọn vào `localStorage` của trình duyệt.

🎤 *Nếu thầy hỏi "trang chủ sắp xếp bài theo gì?":* Em tính một **"điểm hot" (sensational score) ngay trong câu lệnh SQL**: `bookmark×5 + comment×2 + prestige nguồn×4 − tuổi bài×0.5`. Tính trong DB nên rất nhanh, và viết kiểu chạy được cả MySQL lẫn SQLite.

### 1.2 — Tìm kiếm `[click ô tìm kiếm, gõ "công nghệ"]`
> Khi em gõ từ 2 ký tự trở lên, có **live-search** — gợi ý hiện ra **tức thì** dưới ô tìm kiếm. Nó gọi `GET /api/live-search`, có **debounce 300ms** để không spam server mỗi lần gõ phím.
>
> `[nhấn Enter]` Nhấn Enter ra trang kết quả đầy đủ, **có phân trang** ở dưới.

🎤 Live-search **escape HTML** trước khi hiển thị → **chống XSS**, đề phòng tiêu đề bài chứa mã độc.

### 1.3 — Đăng ký + Onboarding `[vào /register, tạo tài khoản mới]`
> Em đăng ký một tài khoản mới. `[điền form, submit]` Mật khẩu phải **xác nhận lại và tối thiểu 8 ký tự** — validate ngay.
>
> `[màn onboarding hiện ra]` Ngay sau đăng ký, hệ thống đưa vào màn **chọn sở thích** — chọn các chuyên mục em quan tâm. `[chọn vài mục → lưu]`
>
> `[về trang chủ]` Giờ trang chủ đã **cá nhân hóa** — ưu tiên các chuyên mục em vừa chọn.

🎤 *Cá nhân hóa này* là quan hệ **nhiều-nhiều** giữa `User` và `Category`, lưu qua **bảng trung gian `category_user`**, dùng hàm `sync()`.

### 1.4 — Đăng nhập Google `[vào /login, bấm "Đăng nhập với Google"]` *(nếu đã cấu hình)*
> Ngoài đăng ký thường, có **đăng nhập bằng Google** theo chuẩn **OAuth2** (dùng Laravel Socialite).
>
> `[bấm nút → sang trang Google → chọn tài khoản → quay về]`
>
> 🎤 Điểm hay: app **không bao giờ thấy mật khẩu Google** của em. App chỉ nhận lại hồ sơ. Nếu email đó **đã có tài khoản** thì hệ thống tự **liên kết** (account linking) chứ không tạo trùng — và toàn bộ bọc trong **transaction + khóa dòng** để an toàn khi nhiều request cùng lúc.

*(Nếu chưa cấu hình OAuth trên máy demo: bỏ qua thao tác, chỉ nói "phần này em trình bày bằng code ở mục kỹ thuật".)*

### 1.5 — Đọc bài + tương tác `[mở 1 bài viết]`
> Mở một bài. `[chỉ vào nút]` Người dùng đã đăng nhập có thể:
> - `[bấm 🔖]` **Bookmark** — lưu bài, icon đổi ngay, **không reload** (AJAX).
> - `[bấm ⚡]` **Boost** — bình chọn cho bài, số đếm +1 tức thì (AJAX).
> - `[kéo xuống phần bình luận]` **Bình luận**, và **trả lời bình luận** — bình luận **phân cấp** (threaded). `[gõ 1 comment → gửi → bấm trả lời]`
> - `[bấm báo cáo 1 comment]` **Báo cáo** bình luận vi phạm để admin xử lý.

🎤 *Bình luận phân cấp* là quan hệ **tự tham chiếu**: bảng `comments` có cột `parent_id` trỏ về chính bảng `comments`.

### 1.6 — Khu cá nhân `[vào /bookmarks rồi /profile]`
> `[/bookmarks]` Đây là các bài em đã lưu.
>
> `[/profile]` Trang hồ sơ: đổi tên/email, cập nhật chuyên mục yêu thích, và xem **"Reading Pass"** — tức **hạn mức đọc bài** còn lại. Em sẽ giải thích cơ chế này ở phần kỹ thuật.

*(Tùy chọn)* `[/pricing]` Có cả **gói Pro** thanh toán qua **Stripe** (dùng Laravel Cashier) để đọc không giới hạn.

---

## PHẦN 2 — DEMO ADMIN ⏱️ 6:00–9:00

### 2.1 — Dashboard `[đăng nhập admin → /admin/dashboard]`
> Em chuyển sang tài khoản **admin**. Đây là **dashboard**: số liệu tổng quan, **biểu đồ Chart.js** (số bài/ngày, user mới/ngày), và một bảng quan trọng — **"sức khỏe nguồn"** (source health).

🎤 *Source health:* mỗi nguồn RSS được gắn trạng thái **healthy / warning / failed / stale**, dựa trên lần fetch gần nhất và tỉ lệ lỗi — ngưỡng cấu hình ở `config/source_health.php`. Nhờ vậy admin biết nguồn nào đang chết.

### 2.2 — CRUD Nguồn tin (bảng 1) `[/admin/sources]`
> Đây là **CRUD đầy đủ** trên bảng **Nguồn tin**. `[bấm "Thêm nguồn"]`
>
> `[điền form bằng mẫu test]` Em thêm một nguồn mới — ví dụ "VnExpress – Tin mới nhất", feed `https://vnexpress.net/rss/tin-moi-nhat.rss`, chuyên mục Thời sự. `[Lưu]`
>
> Form này được **validate bằng Form Request** (`StoreSourceRequest`): URL phải hợp lệ, chuyên mục phải tồn tại, độ uy tín 1–5.
>
> `[bấm "Test RSS" trên nguồn vừa tạo]` Nút **Test RSS** kiểm tra feed còn sống không và đếm số bài — *chưa lưu gì cả*.
>
> `[mở terminal]` Giờ em kéo bài thật về: `php artisan feeds:fetch --source=<ID>`.
>
> `[quay lại trang chủ / chuyên mục Thời sự]` Bài mới đã xuất hiện. ✅ `[sửa lại nguồn để demo Update, rồi có thể xóa demo Delete]`

🎤 Chạy `feeds:fetch` nhiều lần **không tạo bài trùng** — vì em dùng `updateOrCreate` theo `original_url` (khóa chống trùng).

### 2.3 — CRUD Chuyên mục (bảng 2) `[/admin/categories]`
> Đây là **CRUD đầy đủ thứ hai**, trên bảng **Chuyên mục**. `[thêm 1 mục → sửa → bật/tắt active]` Có cả nút **bật/tắt hoạt động** riêng.

🎤 *Khi thầy hỏi "CRUD trên mấy bảng?":* Em có **CRUD đầy đủ 7 method REST trên 2 bảng: Nguồn tin và Chuyên mục**, khai báo bằng **resource route** (1 dòng = 7 route). Ngoài ra còn quản lý Bài viết, User, Bình luận, Báo cáo, Lệnh phạt.

### 2.4 — Quản lý bài viết + Kiểm duyệt `[/admin/articles → /admin/reports]`
> `[/admin/articles]` Bài viết thì **không có Create** (vì bài đến từ RSS), nhưng có sửa, xóa, và **xóa hàng loạt** (bulk).
>
> `[/admin/reports]` Đây là các **báo cáo** người dùng gửi. `[xử lý 1 report → ra sanction]` Em có thể ra **lệnh phạt** (sanction): cảnh cáo, mute, hoặc ban tài khoản. `[/admin/users → đổi role 1 user]` Và đổi **vai trò** user.

🎤 *Khi user bị ban,* có một **middleware toàn cục `CheckUserSanction`** chạy trên **mọi trang** để chặn họ ngay lập tức.

---

## PHẦN 3 — ĐI SÂU KỸ THUẬT (mở VS Code) ⏱️ 9:00–13:00

> Giờ em xin trình bày phần kiến trúc để trả lời các câu hỏi về luồng xử lý và code.

### 3.1 — Luồng 1 request `[mở routes/web.php + ArticleController]`
> Khi mở một bài, request đi qua chuỗi: **Route → Middleware → Controller → Model/DB → View → HTML**.
> - `[routes/web.php]` Route khớp URL, gọi đúng controller.
> - **Middleware** là chốt kiểm tra *trước khi* vào controller.
> - **Controller** điều phối: gọi Model lấy dữ liệu, gọi Service nếu cần.
> - **Model (Eloquent)** sinh câu SQL, truy vấn MySQL.
> - **Blade** render ra HTML trả về.

🎤 Quy tắc MVC em giữ chặt: **View không truy vấn database, Controller không viết HTML**.

### 3.2 — Phân quyền nhiều lớp `[mở AdminMiddleware + bootstrap/app.php]`
> Câu hỏi kinh điển: *một user thường gõ thẳng `/admin/dashboard` thì sao?*
> - Route admin bọc bởi middleware `['auth', 'admin']` — trước hết phải **đăng nhập**, rồi **phải là admin**.
> - `[AdminMiddleware]` `AdminMiddleware` gọi `isAdmin()` (kiểm tra cột `role`). Không phải admin → **`abort(403)`**, request **không bao giờ tới controller**.
> - Tên ngắn `admin`, `verified` là **alias** khai báo ở `bootstrap/app.php`.

🎤 Em còn **kiểm tra lần hai trong controller** (ví dụ xóa comment: phải là chủ comment *hoặc* admin). Bảo vệ ở **nhiều lớp** — đây gọi là **defense in depth**.

### 3.3 — Pipeline RSS `[mở FeedIngestionService + routes/console.php]`
> Trái tim của VietFeed. Luồng: lệnh **`feeds:fetch`** → **Service** tải feed bằng HTTP → **parse XML** bằng SimpleXML (có giải mã tiếng Việt) → trích ảnh (enclosure → media:content → regex thẻ `<img>`) → **`updateOrCreate` theo `original_url`** để chống trùng → ghi **log fetch** + cập nhật sức khỏe nguồn.
> - `[routes/console.php]` Lệnh này **tự chạy mỗi 30 phút** qua scheduler — không cần cron thủ công.
> - Nút "Retry" trên admin đẩy việc vào **Queue** qua **Job** (`FetchSourceFeedJob`), thử lại 5 lần với backoff tăng dần — chạy nền, không bắt admin chờ.

🎤 *Nếu một nguồn chết?* Em `try/catch` từng nguồn → ghi log, đánh dấu health, **các nguồn khác vẫn chạy bình thường**.

### 3.4 — Reading Pass `[mở ReadingPassService]`
> Đây là **paywall theo lượt đọc** — mô hình metered paywall giống các báo lớn.
> - 5 hạng: **admin/Pro = không giới hạn**, **user đã verify email = 15 bài/5 giờ trượt**, **user chưa verify = 8 bài/ngày**, **khách = 5 bài/ngày**.
> - Mỗi lần mở **bài mới** ghi 1 dòng vào bảng `article_unlocks` — user lưu theo `user_id`, khách lưu theo `session_id`.
> - **Bài đã mở thì đọc lại miễn phí**, không trừ lượt.
> - Hết quota → **khóa bài**, mời nâng cấp.

🎤 Khi khách đăng nhập, hàm `transferGuestUnlocks()` **chuyển các lượt đọc từ session sang tài khoản** → khách không bị tính lại từ đầu. (Logic gom hết vào tầng **Service** nên controller chỉ gọi 1 dòng.)

### 3.5 — Validation & Bảo mật (nói nhanh)
> - **Validation 2 kiểu:** Form Request (class riêng cho form admin, có rule + thông báo lỗi tiếng Việt) và inline `$request->validate()` cho thao tác nhỏ.
> - **Bảo mật:** mật khẩu **cast `hashed`** (bcrypt, không lưu plain text); sau đăng nhập gọi **`session()->regenerate()`** chống session fixation; mọi form có **CSRF token**; live-search **escape HTML** chống XSS.

---

## PHẦN 4 — DATABASE: ĐỔI TÊN & CHẠY LẠI ⏱️ 13:00–14:30
*(thầy yêu cầu cụ thể phần này — làm trực tiếp)*

> Em xin demo việc **đổi tên database và dựng lại từ đầu**.
>
> `[mở MySQL Workbench HOẶC terminal]`
> **B1 — Tạo DB mới:**
> ```sql
> CREATE DATABASE vietfeed_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
> ```
> **B2 —** `[mở .env]` Sửa `DB_DATABASE=vietfeed_v2`.
>
> **B3 — Xóa cache config** (bước hay quên):
> ```bash
> php artisan config:clear
> ```
> 🎤 Không có bước này Laravel vẫn nhớ tên DB cũ trong cache.
>
> **B4 — Tạo bảng + đổ dữ liệu mẫu:**
> ```bash
> php artisan migrate:fresh --seed
> ```
> 🎤 Chỉ một cờ `--seed` là có **đủ data**, vì nó chạy class `DatabaseSeeder`, bên trong gọi **4 seeder con theo đúng thứ tự phụ thuộc** — Category trước (vì Source cần `category_id`), rồi Source, User, dữ liệu demo.
>
> **B5 — Kéo tin RSS thật:**
> ```bash
> php artisan feeds:fetch
> ```
>
> `[có thể chạy luôn để chứng minh]` `php artisan route:list` — liệt kê toàn bộ route, chứng minh hệ thống định tuyến.

---

## PHẦN 5 — KẾT ⏱️ 14:30–15:00
> Tóm lại, VietFeed là một hệ thống tổng hợp tin hoàn chỉnh: **tự động thu thập RSS**, **cá nhân hóa**, **tương tác cộng đồng** (bookmark/boost/comment), **kiểm duyệt**, **phân quyền nhiều lớp**, **thanh toán Pro**, và **đăng nhập Google**. Về kỹ thuật, em chú trọng **tách lớp Service/Job/Form Request** để code sạch và dễ mở rộng.
>
> Em xin hết phần trình bày, rất mong nhận góp ý của thầy/cô ạ. Em xin sẵn sàng trả lời câu hỏi.

---

## 📌 PHỤ LỤC — TRẢ LỜI NHANH KHI BỊ HỎI XOÁY
*(tra `CHEAT-SHEET-THUYET-TRINH.md` mục tương ứng nếu cần chi tiết)*

| Thầy hỏi | Trả lời 1 câu | Mục cheat sheet |
|---|---|---|
| Sao chọn Laravel? | "Batteries included": auth, ORM, scheduler, migration sẵn → tập trung logic RSS. | 11 |
| MVC ở đâu? | Model `app/Models`, Controller `app/Http/Controllers`, View `resources/views`. | 2 |
| Chống bài trùng? | `updateOrCreate` theo `original_url`. | 9 |
| Chặn user vào /admin? | middleware `['auth','admin']` → `AdminMiddleware` → `abort(403)`. | 3.2 / 9 |
| Mật khẩu lưu sao? | cast `hashed` (bcrypt), không plain text. | 9 |
| Phân trang ở đâu? | `->paginate(12)` trong controller, `->links()` ở Blade. | 5 |
| CRUD mấy bảng? | Đầy đủ trên **Sources** + **Categories** (resource route). | 4 |
| AJAX gồm gì? | bookmark, boost, live-search, infinite scroll, ticker. | 6 |
| Validation? | Form Request + inline `$request->validate()`. | 7 |
| Many-to-many? | `User ↔ Category` qua `category_user`, dùng `sync()`. | 8 |
| Comment phân cấp? | `parent_id` tự tham chiếu. | 8 |
| Tự động lấy tin? | Scheduler `feeds:fetch` mỗi 30 phút. | 9 |
| Nguồn chết thì sao? | `try/catch` từng nguồn, log + đánh dấu health, nguồn khác vẫn chạy. | 11 |
| Google login? | OAuth2 qua Socialite, không lưu mật khẩu, account linking. | 14A |
| Reading Pass? | Paywall theo lượt, 5 hạng, lưu `article_unlocks`. | 14B |

---

> ✅ **Checklist phút chót:** server chạy? · queue listen chạy (cho Retry)? · đã login sẵn 1 tab admin? · đã test `feeds:fetch --source=ID` ra bài? · mở sẵn cheat sheet ở tab phụ? · mẫu RSS để dán đã copy?
