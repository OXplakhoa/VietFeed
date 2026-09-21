# Hướng innovation cho VietFeed với MongoDB, Cassandra, Neo4j và Redis

> **Trạng thái tài liệu:** Đây là research/đề xuất ở giai đoạn khám phá. `docs/specs/vietfeed-nosql-storylens.md` là canonical implementation source of truth và được ưu tiên khi có mâu thuẫn.

**Ngày nghiên cứu:** 2026-08-17

**Phạm vi:** Nâng cấp VietFeed hiện tại; application layer tiếp tục là Laravel/PHP; hệ thống chỉ dùng bốn database MongoDB, Cassandra, Neo4j và Redis.

## Kết luận ngắn

Hướng mạnh nhất là **VietFeed StoryLens — một news observatory lấy sự kiện làm trung tâm**.

> VietFeed giúp người đọc bận rộn hiểu một sự kiện đang diễn ra mà không phải mở nhiều bài trùng lặp, bằng cách gom tin theo sự kiện thành một timeline sống, chỉ ra điều các nguồn cùng đề cập, góc mỗi nguồn nhấn mạnh, nguồn nào hệ thống quan sát trước và những gì mới thay đổi kể từ lần đọc trước.

Đây là innovation về trải nghiệm sản phẩm: chuyển từ **article-first** (mỗi RSS item là một card độc lập) sang **event-first** (nhiều bài là những quan sát khác nhau về cùng một sự kiện). Nó tận dụng đúng tài sản VietFeed đã có — RSS ingestion, nguồn, category, bookmark/boost, feed cá nhân hóa và source-health — nhưng tạo ra một năng lực mới có thể trình diễn rõ ràng.

Không nên quảng bá sản phẩm là “phát hiện fake news”, “xóa filter bubble”, “chấm nguồn nào đúng” hay “phát hiện đạo văn”. Dữ liệu RSS không đủ để chứng minh các kết luận đó. Các nhãn an toàn hơn là **coverage diversity**, **first observed by VietFeed**, **text reuse similarity**, **common/unique aspects** và **source transparency**.

## Vì sao đây là vấn đề thật

- Reuters Institute ghi nhận năm 2026 có 42% người được hỏi tránh tin tức ít nhất đôi lúc; mức quan tâm cao đến tin tức giảm từ 59% năm 2021 xuống dưới 46%. Điều này củng cố bài toán “đọc ít hơn nhưng vẫn theo kịp diễn biến”, dù khảo sát toàn cầu không đại diện riêng cho Việt Nam ([Digital News Report 2026](https://reutersinstitute.politics.ox.ac.uk/digital-news-report/2026/dnr-executive-summary)).
- NIST đã định nghĩa từ lâu các bài toán tổ chức dòng tin theo sự kiện: topic detection, tracking, first-story detection, link detection và story segmentation. Vì vậy event clustering là một bài toán thông tin có nền tảng học thuật, không phải tính năng trang trí ([NIST Topic Detection and Tracking](https://www.nist.gov/publications/topic-detection-and-tracking-evaluation-overview)).
- Nghiên cứu NewsCube đã xây một giao diện gom nhiều bài của cùng sự kiện theo các khía cạnh khác nhau và user study cho thấy cách trình bày này giúp người dùng đọc đa dạng khía cạnh hơn ([Park et al., CHI 2009](https://nclab.kaist.ac.kr/files/papers/Conference/NewsCube.pdf)).
- Với stream clustering, mô hình kết hợp nội dung, entity và thời gian vượt baseline trong nghiên cứu EACL 2021. Bài học thực dụng cho đồ án là không cần bắt đầu bằng mô hình AI lớn: TF-IDF + entity + time window là baseline hợp lý và đo được ([Saravanakumar et al., EACL 2021](https://aclanthology.org/2021.eacl-main.198/)).
- Bằng chứng về “filter bubble” phức tạp hơn khẩu hiệu thường thấy. Một nghiên cứu hành vi 50.000 người tìm thấy cả gia tăng phân cực lẫn gia tăng tiếp xúc với phía ít ưa thích, với hiệu ứng tổng thể tương đối khiêm tốn ([Flaxman et al., 2016](https://academic.oup.com/poq/article/80/S1/298/2223402)). Một thí nghiệm thực địa còn cho thấy ép người dùng tiếp xúc với quan điểm đối lập có thể phản tác dụng ở một số nhóm ([Bail et al., PNAS 2018](https://doi.org/10.1073/pnas.1804840115)). Vì thế StoryLens nên **cho người dùng quyền chọn so sánh**, không tự nhận là công cụ sửa tư tưởng.
- Provenance hữu ích để đánh giá chất lượng và độ tin cậy, nhưng phải mô tả đúng quan sát. W3C PROV cung cấp ngôn ngữ như `wasDerivedFrom`, `wasQuotedFrom`, `wasRevisionOf` và `hadPrimarySource`; với RSS, VietFeed chỉ nên khẳng định thứ tự “hệ thống quan sát được”, không suy diễn quyền tác giả ([W3C PROV](https://www.w3.org/TR/prov-overview/)).

## Shortlist hướng sản phẩm

### 1. StoryLens: Một sự kiện, nhiều lăng kính — **khuyến nghị**

**Người dùng / vấn đề:** Người đọc thấy nhiều headline gần giống nhau, khó biết đó là tin mới hay bài lặp lại, và phải mở nhiều tab mới hiểu toàn cảnh.

**Giá trị:** Trang sự kiện gom bài từ nhiều nguồn, timeline diễn biến, phần “các nguồn cùng nói”, “mỗi nguồn nhấn mạnh gì”, “mới từ lần bạn đọc trước” và chỉ số độ phủ nguồn minh bạch.

**Vai trò tự nhiên của bốn DB:**

- **MongoDB:** source of truth cho user, source, article và event snapshot; article có metadata/extracted entities/aspects khác nhau nên document model phù hợp. Embedding cho phép đọc một aggregate liên quan trong một operation và cập nhật một document atomically ([MongoDB embedded data](https://www.mongodb.com/docs/manual/data-modeling/embedding/)).
- **Cassandra:** ledger append-only cho article arrivals, event updates, views/clicks/bookmarks và hourly aggregates; schema được thiết kế theo query như `event timeline`, `activity by hour`, `history by user`. Cassandra nhấn mạnh data modeling theo query và clustering keys để truy xuất cục bộ, độ trễ thấp ([Apache Cassandra data modeling](https://cassandra.apache.org/doc/3.11/cassandra/data_modeling/intro.html), [CQL data definition](https://cassandra.apache.org/doc/latest/cassandra/developing/cql/ddl.html)).
- **Neo4j:** graph `Event–Article–Source–Entity–Aspect`, với các cạnh `COVERS`, `MENTIONS`, `EMPHASIZES`, `RELATED_TO`, `FIRST_OBSERVED`; truy vấn các nguồn cùng cover event, entity bridge giữa các event và bài bổ sung góc còn thiếu. GDS có sẵn community detection, similarity và path-finding ([Neo4j GDS algorithms](https://neo4j.com/docs/graph-data-science/current/algorithms/)).
- **Redis:** Redis Streams làm pipeline ingest/classify/update theo consumer groups; Sorted Sets giữ hot-event leaderboard theo velocity + source breadth; cache story cards/feed chỉ là vai trò phụ. Streams là append-only log, còn sorted set duy trì thứ tự theo score ([Redis Streams](https://redis.io/docs/latest/develop/data-types/streams/), [Redis sorted sets](https://redis.io/docs/latest/develop/data-types/sorted-sets/)).

**Khả thi:** Cao nếu MVP chỉ dùng title, description, published time và source. Có thể cluster bằng TF-IDF/cosine + named entities + cửa sổ thời gian, rồi chuẩn bị 10–20 event được gán nhãn tay để demo/evaluate.

**Rủi ro:** Gộp nhầm event; “góc nhìn” dễ bị hiểu thành thiên kiến chính trị; eventual consistency giữa bốn DB. Giảm rủi ro bằng confidence score, cho admin tách/gộp cluster, dùng nhãn “khía cạnh đưa tin”, và consumer idempotent.

**Đo thành công:** pairwise hoặc B-Cubed precision/recall/F1 của cluster; số nguồn khác nhau/event; source entropy; tỷ lệ người dùng mở từ hai nguồn trở lên; p95 trang event; độ trễ từ lúc ingest đến khi event/trend cập nhật.

### 2. Catch-up Mode: “Có gì mới từ lần cuối?”

**Người dùng / vấn đề:** Tin kéo dài nhiều ngày gây mệt mỏi; người quay lại không biết nội dung nào thực sự mới.

**Giá trị:** Một bản change-log của sự kiện: entity/aspect mới, nguồn mới, mốc thời gian mới và “last seen marker” cá nhân.

**DB fit:** Mongo lưu current event snapshot và user state; Cassandra lưu immutable event revisions/timeline; Neo4j tìm node/edge mới trong event graph; Redis giữ unread counters và phát update realtime.

**Khả thi:** Cao khi xây trên StoryLens; yếu hơn nếu đứng độc lập vì vẫn cần event clustering trước.

**Rủi ro:** Diff câu chữ có thể bị hiểu là thay đổi sự thật. Chỉ hiển thị “nội dung mới VietFeed thu thập được”, không kết luận fact đã đổi.

**Đo:** thời gian hoàn thành task “kể lại ba thay đổi mới”; số bài phải mở; precision của detected changes trên mẫu gán nhãn tay.

### 3. Origin Map: “Tin này đi từ đâu đến đâu?”

**Người dùng / vấn đề:** Người đọc khó thấy một nội dung được dẫn lại, cập nhật hoặc lan truyền qua các nguồn như thế nào.

**Giá trị:** Đồ thị first-observed, quote/link rõ ràng, text-similarity và revision timeline. Nghiên cứu CHI 2020 đã thử giao diện provenance trên 45.300 bài và nhấn mạnh ích lợi của việc cho độc giả thấy chuỗi tái sử dụng nội dung ([News Provenance, CHI 2020](https://www.microsoft.com/en-us/research/wp-content/uploads/2020/02/CHI2020_NewsProvenance.pdf)).

**DB fit:** Mongo giữ article/revision text; Cassandra giữ observation timestamps; Neo4j là provenance graph; Redis cập nhật live propagation và cache đường đi phổ biến.

**Khả thi:** Trung bình. Một demo tốt có thể dùng exact URL/link và sentence similarity; không cần kết luận đạo văn.

**Rủi ro:** “Đăng trước” không đồng nghĩa “nguồn gốc”; syndication hợp pháp tạo nội dung giống nhau. Mọi UI phải ghi rõ **first observed by VietFeed** và **similar text**, không ghi “copied from”.

**Đo:** precision/recall của lineage edges trên mẫu review tay; độ đầy đủ timestamp/source; tỷ lệ người dùng hiểu đúng disclaimer.

### 4. Pulse Radar: Xu hướng đang tăng, không chỉ đang lớn

**Người dùng / vấn đề:** Bảng trending theo tổng lượt boost thiên về chủ đề đã lớn; khó phát hiện sự kiện mới tăng nhanh ở nhiều nguồn.

**Giá trị:** Xếp hạng bằng velocity, acceleration và source breadth; giải thích “vì sao đang nổi”. Burst detection có nền tảng từ mô hình dòng tài liệu của Kleinberg ([Kleinberg, KDD 2002](https://www.cs.cornell.edu/info/people/kleinber/bhs.pdf)).

**DB fit:** Redis Sorted Sets tính top-K tức thời; Cassandra giữ time buckets để so baseline; Mongo giữ event detail; Neo4j đo độ lan qua source/entity communities.

**Khả thi:** Cao về demo; có thể phát synthetic traffic để cho thấy rank thay đổi realtime.

**Rủi ro:** Spam/duplicate có thể tạo trend giả; Neo4j ít trung tâm hơn so với StoryLens.

**Đo:** time-to-detect; precision@K so với nhãn tay; false-positive rate; số nguồn độc lập/event; p95 update latency.

### 5. Diversity Passport: Feed có “ngân sách khám phá”

**Người dùng / vấn đề:** Feed hiện tại lọc theo category yêu thích, có thể lặp nguồn/chủ đề và bỏ sót phạm vi đưa tin khác.

**Giá trị:** Giải thích thành phần feed theo preferred, adjacent và explore; user tự chỉnh diversity slider; hiển thị source/topic coverage thay vì gán nhãn chính trị.

**DB fit:** Mongo giữ profile/policy; Cassandra ghi exposure/click history; Neo4j sinh candidate qua user–topic–source–event; Redis cache/rerank feed realtime.

**Khả thi:** Trung bình; recommendation cần traffic hoặc synthetic users để chứng minh. Nên là phase 2 sau StoryLens.

**Rủi ro:** “Diverse” không đồng nghĩa “better”; forced opposition có thể phản tác dụng. Đo coverage và quyền kiểm soát, không tuyên bố giảm phân cực.

**Đo:** source/topic entropy, catalog coverage, click-through cùng survey “feed có dễ hiểu/kiểm soát không”; không dùng engagement đơn độc làm success metric.

## Kiến trúc khuyến nghị cho StoryLens

```text
RSS fetch
   │
   ├── MongoDB: canonical Article + current Event snapshot
   │
   └── Redis Stream: article.ingested
          ├── cluster consumer ──> MongoDB Event
          ├── graph consumer ───> Neo4j relations
          ├── history consumer ─> Cassandra timelines/metrics
          └── pulse consumer ───> Redis Sorted Sets

Laravel reads:
  story detail -> MongoDB + Neo4j
  timeline/history -> Cassandra
  live pulse/feed cache -> Redis
```

Quy tắc ownership để tránh “bốn bản sao không biết cái nào đúng”:

- MongoDB sở hữu **current content/state** và ID chuẩn.
- Cassandra sở hữu **historical facts/events theo thời gian**, không phải current article CRUD.
- Neo4j sở hữu **relationship topology** và các truy vấn nhiều-hop, không lưu full article body.
- Redis sở hữu **ephemeral realtime state/pipeline**, có thể rebuild từ ba store còn lại.

Không dùng distributed transaction. Mỗi stream event có `event_id`, `article_id`, `version`, consumers upsert idempotently và có retry/dead-letter stream. Giao diện chấp nhận eventual consistency ngắn và có `processed_at` để giải thích trạng thái.

## MVP có thể bảo vệ trước giáo viên

1. Giữ Blade UI và RSS fetcher của Laravel; thay persistence layer, không rewrite toàn bộ frontend.
2. MongoDB chứa user/auth, sources, categories, articles, events và bookmarks cần cho demo. Redis chứa session/queue/cache để không còn MySQL/SQLite runtime.
3. Chọn 6–10 nguồn, khoảng 100–300 bài và 10–20 event đã review tay. Có frozen fixture để demo không phụ thuộc RSS live.
4. Xây trang Event Lens: headline đại diện, timeline, source list, common/unique keywords/entities, related events và live pulse.
5. Chuẩn bị tối thiểu một query đặc trưng cho mỗi DB và một màn hình/metric dùng kết quả đó. Nếu bỏ một DB, phải mất một năng lực nghiệp vụ rõ ràng.
6. Demo load synthetic interaction events vào Cassandra/Redis để chứng minh workload shape; không giả vờ rằng 200 bài cần quy mô Cassandra.

Nên tạm hoãn billing/Stripe, threaded moderation phức tạp và AI-generated summary. Chúng làm rộng migration nhưng không giúp bảo vệ quyết định NoSQL.

## Gate kỹ thuật quan trọng với Laravel/PHP

- MongoDB có Laravel integration chính thức, hỗ trợ Laravel 10+ và dùng Eloquent/Query Builder syntax; đây là phần tái sử dụng Laravel ít rủi ro nhất ([MongoDB Laravel integration](https://www.mongodb.com/docs/drivers/php/laravel-mongodb/current/)).
- Neo4j liệt kê PHP client do cộng đồng duy trì, hỗ trợ Bolt/HTTP và PHP 8+, nhưng không phải official driver; vẫn phù hợp đồ án nếu có integration test ([Neo4j community PHP drivers](https://neo4j.com/docs/getting-started/languages-guides/community-drivers/)).
- Cassandra là gate lớn nhất: repository DataStax PHP driver ở maintenance mode và bảng compatibility công bố PHP 5.6–7.1, trong khi repo VietFeed dùng PHP 8.3+ ([DataStax PHP driver](https://github.com/datastax/php-driver)). Trước khi cam kết toàn PHP, cần làm spike kết nối/CRUD với đúng Docker image và PHP runtime.

Nếu spike Cassandra thất bại, phương án an toàn là giữ Laravel làm web/core và thêm một **Cassandra adapter rất hẹp bằng Java hoặc .NET** (hai nền tảng cô cho phép), chỉ expose các operation timeline/analytics. Đây không thêm database thứ năm, nhưng tăng vận hành; vì vậy chỉ dùng sau khi xác nhận pure-PHP không khả thi.

## Kế hoạch đánh giá học thuật tối thiểu

| Câu hỏi | Metric | Cách tạo ground truth |
|---|---|---|
| Gộp bài cùng event có đúng không? | Pairwise/B-Cubed precision, recall, F1 | Hai người gán nhãn độc lập 100–200 bài; resolve disagreement |
| Trend có phát hiện sớm và đúng không? | time-to-detect, precision@5, false positives | Script replay timestamps + danh sách event đã biết |
| Coverage có thực sự đa nguồn? | distinct sources, source entropy, aspect coverage | Đếm source và review aspect labels |
| Provenance có bị suy diễn quá mức? | edge precision; disclaimer comprehension | Review 30–50 edges; quiz ngắn “first observed có nghĩa gì?” |
| Polyglot persistence có đáng không? | p95 latency, throughput, query/demo per DB, rebuild test | Script benchmark và restart/rebuild Redis state |
| Trải nghiệm có tốt hơn feed bài rời? | task completion time, số tab/bài mở, comprehension questions | Small within-subject study 10–20 người: feed cũ vs Event Lens |

## Quyết định đề xuất

Chọn **StoryLens** làm product thesis, đưa **Catch-up Mode** và **Pulse Radar** vào cùng vertical slice, còn **Origin Map** là stretch goal. Diversity Passport chỉ nên là phase sau khi có event graph và interaction data.

Tên demo gợi ý: **VietFeed StoryLens — Đọc một sự kiện, không đọc lại cùng một tin.**

Điểm bảo vệ quan trọng nhất không phải “mỗi database chứa một loại bảng”, mà là:

> MongoDB trả lời *sự kiện hiện tại là gì*; Cassandra trả lời *nó đã diễn biến thế nào theo thời gian*; Neo4j trả lời *những nguồn, bài, thực thể và góc đưa tin liên hệ ra sao*; Redis trả lời *điều gì đang thay đổi ngay lúc này*.
