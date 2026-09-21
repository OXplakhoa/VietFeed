# VietFeed StoryLens — Demo runbook

> Supporting demo sequence for `docs/specs/vietfeed-nosql-storylens.md`. The canonical spec defines product and architecture scope.

## Mục tiêu

Demo theo một câu chuyện xuyên suốt thay vì lần lượt click mọi màn CRUD. Bản core nhắm khoảng 20 phút; khi được hỏi sâu hoặc có khoảng 30 phút thì mở các module bổ sung. Tất cả bước phải dùng frozen fixture/test accounts và có lệnh reset để chạy lại.

## Chuẩn bị trước giờ demo

- Khởi động MongoDB, Cassandra, Neo4j, Redis và các worker cần thiết.
- Xác nhận cả bốn database đang chạy bằng Docker và resource usage nằm trong giới hạn của máy demo.
- Mở sẵn các DBeaver connections/query tabs cần dùng để minh họa dữ liệu; không tạo connection hoặc nhập credential trong lúc trình bày.
- Chạy health check và xác nhận không còn SQLite/MySQL runtime.
- Reset về frozen replay fixture đã review.
- Seed User, Admin và Super Admin test; không dùng credential thật trong slide/command history.
- Warm các dependency/image trước; không download package/image trong giờ demo.
- Mở sẵn Story page, Admin processing view, database evidence views và terminal chạy `curl`.
- Chạy smoke test Stripe/Google/Gemini trước buổi demo nhưng chuẩn bị fallback cho external-network failure.
- Ưu tiên mạng của phòng demo và chuẩn bị 5G cá nhân; không xem hotspot là cơ chế recovery duy nhất.

## Core demo — khoảng 20 phút

### 0:00–1:30 — Product thesis và architecture

- Nêu bài toán: nhiều báo lặp lại cùng một breaking story; người đọc cần hiểu trong 60 giây.
- Nêu một câu ownership: MongoDB=current, Cassandra=history, Neo4j=relationships, Redis=realtime.
- Nói rõ StoryLens không phán quyết nguồn nào đúng.

### 1:30–4:00 — Admin source và deterministic replay ingestion

- Super Admin/Admin có permission phù hợp mở Source Health.
- Replay một frozen breaking-news fixture có nhiều nguồn/tier.
- Chỉ ra canonical Article/Story được lưu trước; pipeline phía sau tiếp tục xử lý.

### 4:00–8:00 — StoryLens progressive experience

- Mở Story ngay khi MongoDB đã sẵn sàng.
- Hiển thị title/excerpt/source/tier/basic timeline trước.
- Quan sát trạng thái timeline, graph, ranking và AI brief chuyển `pending` -> `ready`.
- Chỉ provenance/evidence links, common/unique aspects và extractive fallback contract.

### 8:00–12:00 — Chứng minh bốn database bằng feature/query

- Dùng DBeaver cho các query/data inspection đơn giản mà connection driver hỗ trợ.
- MongoDB: current Story document và Article CRUD/current state.
- Cassandra: `story_events_by_story` hoặc Source Health/Reading History theo thời gian.
- Neo4j: Article–Story–Source–Entity–Aspect path và related/recommendation explanation.
- Redis: Stream processing status và Sorted Set trending.
- Nếu một capability không hiển thị phù hợp trong DBeaver, dùng native CLI/browser đã chuẩn bị sẵn thay vì debug GUI trong giờ demo.
- Nêu điều gì sẽ mất nếu bỏ từng database.

### 12:00–15:00 — Existing feature vertical slice

- User bookmark/boost/comment một bài; current state thay đổi ngay.
- Redis trending phản ánh interaction; Cassandra activity/history xuất hiện sau.
- Admin/Super Admin permission khác nhau; mở nhanh Report/Moderation sau khi feature baseline đã được repair.

### 15:00–18:00 — Failure and recovery

- Dừng Neo4j projection consumer, ingest/update Story và chứng minh canonical page vẫn hoạt động.
- Show `pending`/`failed`, restore consumer, retry và graph trở thành `ready` mà không ingest lại Article.

### 18:00–19:30 — API token failure bằng `curl`

- Gọi `/api/v1/auth/me` với Access Token hợp lệ.
- Show invalid/expired token response hoặc Refresh Token rotation/reuse detection bằng script deterministic.
- Không in raw credentials lên slide/log; token chỉ nằm trong shell variables của phiên demo.

### 19:30–20:00 — Kết luận

- Nhắc lại database-by-workload, controlled eventual consistency và khả năng replay/rebuild.
- Dừng ở outcome; chỉ mở màn hình bổ sung khi giảng viên hỏi.

## Extended modules — thêm tối đa khoảng 10 phút

### Stripe Test Mode end-to-end

- Checkout bằng test card, nhận signed webhook, MongoDB cập nhật subscription và Reading Pass trở thành Pro.
- Gửi lại cùng Stripe event để chứng minh idempotency.

### Source Health sâu hơn

- So current health trong MongoDB với history query trong Cassandra.
- Retry một source và xem worker/lock/status.

### Moderation và account lifecycle

- User report, Admin review/sanction, Super Admin-only permission management.
- Minh họa soft deletion, 30-day retention và purge workflow state; không chờ 30 ngày thật trong demo.

### Authentication failure matrix

- Refresh Token hết hạn trước Access Token.
- Reuse rotated Refresh Token làm revoke family.
- Ban/soft-delete user làm token hiện hành mất hiệu lực ngay.

### CRUD coverage theo yêu cầu

- Chỉ mở thêm Category/Source/Article/User/Comment/Report/Sanction CRUD nếu giảng viên muốn kiểm tra feature parity.
- Dùng checklist/test report để chứng minh các CRUD không được click trong golden path vẫn hoạt động.

## Quy tắc cắt ngắn

Nếu thời gian còn 15 phút:

- Giữ Product thesis, StoryLens progressive experience, bốn DB query, một interaction và Neo4j recovery.
- Bỏ live Stripe, CRUD tour và token expiry timing; cho xem test output/diagram khi được hỏi.

Không cắt:

- StoryLens outcome.
- Lý do dùng từng DB.
- Ít nhất một query/feature hữu hình cho mỗi DB.
- Một bằng chứng controlled eventual consistency hoặc recovery.
