# VietFeed NoSQL — Feature ownership và lý do chọn database

> Detailed ownership rationale supporting `docs/specs/vietfeed-nosql-storylens.md`. The canonical spec takes precedence if wording differs.

## 1. Nguyên tắc quyết định

VietFeed dùng polyglot persistence ở cấp **toàn hệ thống**, không ép mọi feature chạm cả bốn database.

Môi trường local chạy cả MongoDB, Cassandra, Neo4j và Redis qua Docker. DBeaver được dùng để inspect và chạy query đơn giản, nhưng không nằm trong runtime architecture của VietFeed.

1. Mỗi dữ liệu nghiệp vụ có một nơi chịu trách nhiệm chính.
2. Chỉ thêm database thứ hai khi có một access pattern khác biệt và có thể demo bằng query cụ thể.
3. MongoDB giữ current operational state và phần lớn CRUD.
4. Cassandra giữ dữ liệu append-only/time-ordered được thiết kế theo query.
5. Neo4j giữ graph projection phục vụ traversal và recommendation có giải thích.
6. Redis giữ state realtime/ephemeral có thể rebuild; không giữ dữ liệu nghiệp vụ duy nhất.
7. Projection sang database phụ được cập nhật bất đồng bộ, idempotent, có retry và replay.
8. UUIDv7 do Laravel sinh là canonical ID xuyên mọi store.

## 2. Vai trò không thể thay thế của từng database

| Database | Vai trò | Nếu bỏ database này sẽ mất gì? |
|---|---|---|
| MongoDB | Canonical current state, document CRUD, authentication data | Không còn nơi phù hợp để quản lý user/source/article/story/comment/report hiện hành |
| Cassandra | Story evolution, reading history, source-health history và activity theo thời gian | Không còn timeline/history query được thiết kế theo partition và thời gian; khó replay analytics |
| Neo4j | Story–Article–Source–Entity–Aspect graph và recommendation | Mất multi-hop related-story/source-coverage queries và phần giải thích quan hệ |
| Redis | Session, cache, queue/stream, locks và realtime ranking | Mất phản hồi realtime, background pipeline và hot-news top-K độ trễ thấp |

## 3. Feature map tổng quát

Ký hiệu:

- **Primary:** nguồn dữ liệu chính cho trạng thái của feature.
- **Projection/history:** dữ liệu dẫn xuất hoặc lịch sử, có thể cập nhật bất đồng bộ.
- **Infrastructure:** session, queue, cache, lock hoặc rate limit; không phải source of truth.

| Feature | Primary | Database hỗ trợ | Lý do |
|---|---|---|---|
| Register/login/Google OAuth/profile | MongoDB | Redis infrastructure | User là current document CRUD; Redis phù hợp web session, throttle và token có TTL |
| First-party `/api/v1` authentication | Redis token state | MongoDB user/permissions | Opaque AT/RT hashes, token-family rotation/revocation và TTL giây là ephemeral security state; identity/authorization hiện hành vẫn thuộc MongoDB |
| Email verification/password reset | MongoDB user | Redis token/throttle | Token tồn tại ngắn hạn và cần TTL; mật khẩu/trạng thái verified vẫn thuộc User |
| RBAC Super Admin/Admin/User | MongoDB | Cassandra audit | Permission hiện hành cần đọc chính xác mỗi request; thay đổi quyền cần lịch sử append-only |
| Category CRUD | MongoDB | Redis cache | Category là current configuration, ít ghi và cần CRUD/index |
| Source CRUD/prestige tier | MongoDB | Cassandra history, Redis jobs | Source và tier là current config; fetch attempt là time-series; retry chạy nền |
| RSS ingestion | MongoDB Article | Redis Stream/locks, Cassandra arrivals, Neo4j projection | Lưu article canonical trước; downstream xử lý timeline, graph và realtime bất đồng bộ |
| Article CRUD/detail/category page | MongoDB | Redis cache, Neo4j related content | Article là document; cache tăng tốc; graph chỉ phục vụ quan hệ |
| Search/live search | MongoDB | Redis cache/rate limit | Search title/excerpt/full text trên canonical documents; không cần graph traversal |
| Home feed/load more | MongoDB | Redis ranking/cache, Neo4j candidates | Mongo trả article/story; Redis sắp hot; Neo4j bổ sung candidate theo quan hệ |
| Ticker/trending | Redis Sorted Set | Cassandra interaction history, Mongo details | Redis phù hợp top-K realtime; Cassandra giúp rebuild/baseline; Mongo trả nội dung hiển thị |
| Bookmark hiện hành | MongoDB | Cassandra activity, Redis cache | Toggle/list là current CRUD có unique `(user_id, article_id)`; history là append-only |
| Boost hiện hành | MongoDB | Cassandra activity, Redis ranking | Unique toggle thuộc current state; Redis cập nhật trending; Cassandra giữ hành vi theo thời gian |
| Comment/reply CRUD | MongoDB | Redis rate limit/cache, Cassandra moderation history | Nội dung hiện hành cần CRUD/ownership; rate limit là ephemeral; edit/hide history là audit |
| Report/moderation | MongoDB | Redis review queue, Cassandra audit | Case/status hiện hành cần atomic update; queue cần ưu tiên nhanh; quyết định admin cần lịch sử |
| Sanction/appeal | MongoDB | Cassandra audit, Redis session revoke | Sanction hiện hành quyết định quyền truy cập; audit giữ diễn biến; Redis thu hồi session tức thời |
| Billing/Stripe | MongoDB local projection | Không đưa DB khác vào critical path | Stripe là external authority; Mongo giữ customer/subscription state và idempotent webhook receipt |
| Reading Pass | MongoDB subscription | Redis quota, Cassandra unlock history | Plan hiện hành thuộc Mongo; Redis atomic counter; Cassandra truy vấn lịch sử theo user/tháng |
| Onboarding/interests | MongoDB | Neo4j preference projection | Mongo giữ lựa chọn hiện hành; graph dùng quan hệ User–Category để recommendation |
| Recommendation/related news | Neo4j graph read model | Mongo details, Redis cache | Multi-hop traversal là workload tự nhiên của graph; Mongo trả document hoàn chỉnh |
| Admin dashboard | Tổng hợp read models | Cả bốn theo metric | Dashboard đọc current counts, timelines, graph metrics và realtime counters; không sở hữu dữ liệu mới |
| Account deletion | MongoDB workflow state | Redis queue/session, Neo4j cleanup, Cassandra anonymization | Đây là workflow cleanup bất đồng bộ; không có distributed transaction xuyên bốn DB |
| StoryLens | MongoDB canonical Story | Cassandra timeline, Neo4j graph, Redis pipeline/realtime | Đây là vertical slice tự nhiên cần cả bốn access pattern |

## 4. Mapping chi tiết theo nhóm tính năng

### 4.1 Authentication và profile

MongoDB collections dự kiến:

```text
users
subscriptions
processed_stripe_events
account_deletion_workflows
```

MongoDB phù hợp vì profile, provider IDs, verification state, role và subscription projection có shape linh hoạt nhưng cần current CRUD và unique indexes (`email`, `google_id`, `stripe_customer_id`). Redis chỉ giữ session, rate-limit và reset token có TTL. Không lưu permission chỉ trong session/cache để việc revoke có hiệu lực ngay.

### 4.2 Content và admin CRUD

MongoDB collections dự kiến:

```text
categories
sources
articles
stories
comments
reports
sanctions
bookmarks
boosts
```

Đây là dữ liệu người dùng/admin tạo, đọc, sửa, xóa theo trạng thái hiện hành. Quan hệ thường dùng có thể denormalize bằng snapshot nhỏ, ví dụ Article giữ `source_id`, `source_name`, `source_tier`, để tránh mô phỏng SQL join trên mọi request. Source document vẫn là canonical record cho cấu hình hiện hành.

Không dùng Neo4j làm nơi lưu full article/comment và không dùng Cassandra làm generic CRUD database.

### 4.3 Cassandra: query-first history

Cassandra table không được thiết kế theo SQL entity; mỗi table phục vụ query đã biết:

```text
story_events_by_story
  partition: story_id
  clustering: occurred_at DESC, event_id

reading_history_by_user_month
  partition: (user_id, year_month)
  clustering: occurred_at DESC, event_id

source_fetches_by_source_day
  partition: (source_id, day)
  clustering: fetched_at DESC, fetch_id

activity_by_article_day
  partition: (article_id, day)
  clustering: occurred_at DESC, event_id

moderation_events_by_case
  partition: case_id
  clustering: occurred_at ASC, event_id
```

Các query demo bắt buộc:

- Diễn biến của một Story theo thời gian.
- Lịch sử đọc/mở khóa gần nhất của một user.
- Sức khỏe một RSS source trong một ngày/tuần.
- Interaction history dùng để rebuild trending hoặc giải thích spike.
- Chuỗi quyết định của một report/sanction case.

Bookmark/Boost hiện hành vẫn ở MongoDB. Cassandra chỉ nhận `BOOKMARKED`, `UNBOOKMARKED`, `BOOSTED`, `UNBOOSTED` events; cách này tránh biến Cassandra thành store CRUD nhiều toggle/delete và tombstone không cần thiết.

### 4.4 Neo4j: graph có truy vấn cụ thể

Graph dự kiến:

```text
(User)-[:INTERESTED_IN]->(Category)
(Article)-[:FROM_SOURCE]->(Source)
(Article)-[:IN_CATEGORY]->(Category)
(Article)-[:PART_OF]->(Story)
(Article)-[:MENTIONS]->(Entity)
(Article)-[:EMPHASIZES]->(Aspect)
(Story)-[:RELATED_TO]->(Story)
```

Các query demo bắt buộc:

- Tìm bài/Story liên quan qua entity/aspect chung.
- Hiển thị các nguồn đang cover cùng Story và góc mỗi nguồn nhấn mạnh.
- Sinh recommendation candidate từ sở thích user tới category/entity/story và trả lại đường giải thích.

Neo4j giữ graph read model với canonical UUIDv7, không dùng internal node ID trong business logic. Projection có thể rebuild từ MongoDB và event history.

### 4.5 Redis: realtime nhưng có thể mất và dựng lại

Redis responsibilities:

```text
sessions:*                 session
cache:article:*            document/page cache
trending:articles          Sorted Set
trending:stories           Sorted Set
stream:ingestion           Redis Stream
stream:moderation          Redis Stream
stream:dead-letter         failed processing
lock:source-fetch:*        distributed lock
quota:reading-pass:*       atomic quota with TTL
rate-limit:*               throttling
```

Redis không phải nơi duy nhất lưu bookmark, subscription, report hay article. Nếu Redis bị flush, hệ thống phải khởi động lại được từ MongoDB/Cassandra/Neo4j; cache có thể warm lại, trending có thể replay, session có thể yêu cầu login lại.

### 4.6 StoryLens: một vertical slice dùng cả bốn DB hợp lý

```text
MongoDB
  canonical Article + current Story + validated current summary
       |
       v
Redis Stream
  ingestion pipeline, retry, dead-letter, processing status, hot ranking
       |                         |
       v                         v
Cassandra                    Neo4j
story revisions/timeline     Article–Story–Source–Entity–Aspect graph
```

First render đọc MongoDB và không chờ AI. Cassandra/Neo4j/Redis projections có thể trễ khoảng năm giây. AI brief chạy riêng, có validator, timeout và extractive fallback.

## 5. Feature không nên bị ép dùng nhiều DB

- Billing không cần Neo4j/Cassandra/Redis trên critical path.
- Category CRUD không cần Cassandra hoặc Neo4j.
- Password reset không cần graph hoặc time-series store.
- Permission check không nên phụ thuộc Redis cache.
- Search keyword không nên chuyển sang Neo4j chỉ vì graph đã có sẵn.
- Report chỉ dùng Neo4j khi có graph-assisted abuse query thật; đây là future feature, không phải MVP.

Đây không phải thiếu tích hợp. Đây là bằng chứng hệ thống chọn database theo workload thay vì chia bảng tùy ý.

## 6. Cách giải thích ngắn khi bảo vệ

> MongoDB trả lời dữ liệu hiện hành là gì và cung cấp CRUD. Cassandra trả lời điều gì đã xảy ra theo thời gian. Neo4j trả lời các đối tượng liên hệ với nhau như thế nào và vì sao một nội dung được đề xuất. Redis trả lời điều gì đang nóng hoặc đang được xử lý ngay lúc này. Một feature chỉ dùng thêm database khi câu hỏi nghiệp vụ của database đó thực sự xuất hiện.

## 7. Điều kiện để mapping được xem là hoàn thành

- Mỗi DB có ít nhất một màn hình hoặc metric mà người chấm nhìn thấy.
- Mỗi DB có ít nhất một query đặc trưng được trình bày và test.
- Có failure demo: tắt một projection consumer, canonical content vẫn đọc được, sau đó retry/replay thành công.
- Có sơ đồ ownership chỉ rõ primary, projection và ephemeral state.
- Runtime không còn cần SQLite/MySQL.
- Không tuyên bố eventual consistency là strong consistency và không giấu dữ liệu pending/failed trên admin UI.
