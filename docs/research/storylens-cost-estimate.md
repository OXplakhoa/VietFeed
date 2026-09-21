# Ước tính chi phí VietFeed StoryLens

**Ngày kiểm tra:** 2026-08-18  
**Phạm vi:** Đồ án sinh viên chạy local trong khoảng hai tháng; 100–300 RSS articles, 10–20 breaking-news events; MongoDB, Cassandra, Neo4j và Redis chạy bằng Docker; controlled-hybrid summary chỉ nhận evidence packet đã cấu trúc.

> Giá, quota và điều khoản dịch vụ có thể thay đổi. Trước tuần demo cần kiểm tra lại các trang giá/quota chính thức và quota thực tế trong dashboard của tài khoản.

## Kết luận ngắn

- **Có thể hoàn thành MVP với chi phí tiền mặt $0**: bốn database chạy local bằng community/open-source edition; Docker Desktop miễn phí cho mục đích giáo dục; Gemini API Free Tier đủ cho tập demo nhỏ nếu có retry và extractive fallback.
- Nếu muốn prompt/response **không được Google dùng để cải thiện sản phẩm**, phương án hợp lý là bật Gemini Paid Tier. Google hiện có thể yêu cầu **nạp trước tối thiểu $10**; số tiền StoryLens thực sự tiêu trong hai tháng nhiều khả năng chỉ khoảng **$1–$4** theo workload bên dưới.
- Không cần mua cloud database hoặc server public. Chi phí không tính trong bảng là laptop, điện và kết nối Internet đã có sẵn.

## 1. Chi phí bốn database và Docker local

| Thành phần | Edition/phiên bản phù hợp | Phí license cho demo local | Lưu ý license |
|---|---|---:|---|
| Docker Desktop | Docker Personal | $0 | Docker xác nhận Desktop miễn phí cho giáo dục, personal use, non-commercial open source và small business đủ điều kiện ([Docker Desktop license](https://docs.docker.com/subscription/desktop-license/), [Docker Personal](https://www.docker.com/products/personal/)). |
| MongoDB | Community Server | $0 | Community Server mới dùng SSPL; SSPL không được OSI công nhận là open-source. MongoDB nói ứng dụng SaaS chỉ *dùng MongoDB làm database* không tự động chịu điều khoản công khai toàn bộ ứng dụng; điều khoản đặc biệt nhắm vào việc cung cấp chức năng MongoDB như một dịch vụ ([MongoDB licensing](https://www.mongodb.com/legal/licensing/community-edition), [SSPL FAQ](https://www.mongodb.com/legal/licensing/server-side-public-license/faq)). Với đồ án local, không có phí license. |
| Apache Cassandra | Bản Apache chính thức | $0 | Apache Cassandra mang Apache License 2.0; tài liệu chính thức có hướng dẫn chạy image `cassandra` bằng Docker ([repository/license](https://github.com/apache/cassandra), [Cassandra Docker install](https://cassandra.apache.org/doc/stable/cassandra/installing/installing.html), [Apache License 2.0](https://www.apache.org/licenses/LICENSE-2.0.html)). |
| Neo4j | Community Edition | $0 | Community Edition là bản self-managed miễn phí, community-supported, dùng GPLv3 ([Neo4j pricing](https://neo4j.com/pricing/), [Neo4j legal terms](https://neo4j.com/legal-terms/)). Không dùng Enterprise image/licence cho MVP. |
| Redis | Redis Open Source 8 | $0 | Redis 8 có ba lựa chọn license: RSALv2, SSPLv1 hoặc AGPLv3; AGPLv3 là lựa chọn OSI-approved. Redis 7.2 trở xuống dùng BSD-3-Clause, còn 7.4–7.8 dùng RSALv2/SSPLv1 ([Redis licences](https://redis.io/legal/licenses/)). Cho đồ án nên pin Redis 8 và ghi rõ chọn AGPLv3. |

Đây là kết luận về **chi phí sử dụng phần mềm**, không phải tư vấn pháp lý. Nếu sau này thương mại hóa, phân phối image đã chỉnh sửa hoặc cung cấp database-as-a-service, cần rà lại license riêng.

## 2. AI API phù hợp

### Khuyến nghị chính: Gemini 2.5 Flash-Lite

StoryLens không yêu cầu model tự nghiên cứu hay suy luận dài. Model chỉ cần paraphrase evidence packet thành JSON ngắn có `evidence_ids`; vì vậy model rẻ, nhanh là phù hợp hơn model frontier.

Theo [Gemini Developer API pricing](https://ai.google.dev/gemini-api/docs/pricing):

| Model | Free Tier | Paid input / 1M tokens | Paid output / 1M tokens | Vai trò đề xuất |
|---|---:|---:|---:|---|
| Gemini 2.5 Flash-Lite | Có | $0.10 | $0.40 | Mặc định cho controlled paraphrase |
| Gemini 2.5 Flash | Có | $0.30 | $2.50 | Thử lại các packet khó hoặc dùng để so sánh chất lượng |

Hai model đều có structured output phù hợp với JSON schema. Không cần Search Grounding vì bằng chứng phải đến từ RSS/evidence packet của chính StoryLens; bật grounding chỉ làm provenance khó kiểm soát hơn.

### Paid fallback hợp lý: OpenAI GPT-5 mini

[GPT-5 mini](https://developers.openai.com/api/docs/models/gpt-5-mini) hỗ trợ Structured Outputs, có giá $0.25/1M input tokens và $2.00/1M output tokens. Model này không có API Free Tier; Tier 1 công bố 500 RPM và 500.000 TPM, cao hơn rất nhiều nhu cầu demo. OpenAI nói dữ liệu API không được dùng để train mặc định trừ khi khách hàng chủ động opt in; abuse-monitoring logs mặc định có thể được giữ tối đa 30 ngày ([OpenAI API data controls](https://platform.openai.com/docs/models/default-usage-policies-by-endpoint)).

Không cần tích hợp hai provider ngay trong MVP. Nên tạo một interface `SummaryGenerator` để đổi provider được, nhưng chỉ hoàn thiện một provider và extractive fallback.

## 3. Rate limit và độ ổn định khi demo

Google hiện không công bố một bảng RPM/RPD cố định áp dụng cho mọi tài khoản. [Tài liệu rate limits](https://ai.google.dev/gemini-api/docs/rate-limits) nói quota phụ thuộc model và usage tier, phải xem quota đang active trong AI Studio; giới hạn được áp dụng theo project, không theo API key, và capacity không được bảo đảm.

Thiết kế an toàn cho demo:

1. Debounce theo `event_id`: nhiều article đến trong một khoảng ngắn chỉ tạo một summary version.
2. Chạy request tuần tự hoặc với concurrency rất thấp; không cần burst hàng chục request.
3. Retry `429`/timeout bằng exponential backoff có jitter.
4. Cache brief đã validate trong MongoDB, không generate lại khi reload trang.
5. Nếu API fail hoặc validator reject, trả ngay extractive brief.
6. Chuẩn bị replay dataset và một bộ brief đã validate để buổi demo không phụ thuộc mạng hoặc quota.

Với chỉ 10–20 event, rate limit không phải rủi ro về quy mô; **phụ thuộc mạng và quota không bảo đảm** mới là rủi ro cần thiết kế fallback.

## 4. Privacy: Free không chỉ khác Paid ở quota

Theo [Gemini API Additional Terms](https://ai.google.dev/gemini-api/terms):

- Với **Unpaid Services**, Google có thể dùng nội dung gửi lên và response để cung cấp/cải thiện/phát triển sản phẩm; human reviewers có thể đọc, annotate và xử lý input/output. Google yêu cầu không gửi dữ liệu nhạy cảm, bí mật hoặc dữ liệu cá nhân vào dịch vụ miễn phí.
- Với **Paid Services** qua Cloud project có active billing, Google nói prompt/response không được dùng để cải thiện sản phẩm. Dữ liệu vẫn có thể được log trong một thời gian giới hạn cho safety, abuse prevention và nghĩa vụ pháp lý.

Quy tắc cho StoryLens Free Tier:

- Chỉ gửi title, RSS excerpt, publisher, observed time và evidence IDs từ nội dung báo chí công khai.
- Không gửi email, profile, browsing history, bookmark hoặc “last read” của người dùng.
- Không gửi secret/API key trong prompt hoặc log.
- Chỉ gửi phần evidence tối thiểu cần để viết brief; lưu citation và kết quả validator tại local.

Nếu muốn trình bày một hệ thống có privacy posture tốt hơn, dùng Paid Tier dù chi phí token rất nhỏ.

## 5. Ước tính theo workload StoryLens

### Giả định

- Một lần generate nhận **5.000 input tokens**: system rules + JSON schema + khoảng 5–15 RSS excerpts/evidence items.
- Model trả tối đa **600 output tokens** cho headline, 4–6 bullets, conflicts và citations.
- Một event có trung bình ba summary versions sau debounce.
- Tập demo có 20 events: `20 × 3 = 60 calls`.
- Để tính cả prompt tuning, retry và chạy lại fixture, dùng thêm kịch bản `600 calls` (gấp 10 lần corpus demo).

Công thức:

```text
cost = input_tokens / 1,000,000 × input_price
     + output_tokens / 1,000,000 × output_price
```

| Model | Giá mỗi call 5k in + 600 out | 60 calls | 600 calls | 1.200 calls (20/ngày × 60 ngày) |
|---|---:|---:|---:|---:|
| Gemini 2.5 Flash-Lite | $0.00074 | $0.044 | $0.44 | $0.89 |
| Gemini 2.5 Flash | $0.00300 | $0.18 | $1.80 | $3.60 |
| OpenAI GPT-5 mini | $0.00245 | $0.15 | $1.47 | $2.94 |

Các con số này là usage cost, chưa tính thuế/chuyển đổi ngoại tệ. Khi implement phải dùng token-count endpoint/usage metadata để thay giả định bằng số thật.

## 6. Hai phương án ngân sách

### Phương án $0 — khuyến nghị để bắt đầu

- Docker Personal + bốn database chạy local: $0.
- Gemini 2.5 Flash-Lite Free Tier: $0.
- Chỉ gửi public RSS evidence, tuyệt đối không gửi dữ liệu người dùng.
- Extractive fallback luôn hoạt động; cache kết quả và có replay fixture.
- Chưa deploy server public, chưa mua managed database.

Phương án này đủ cho việc phát triển và bảo vệ kiến trúc. Trade-off là quota không bảo đảm và prompt/response Free Tier có thể được Google dùng để cải thiện sản phẩm.

### Phương án ngân sách nhỏ — khuyến nghị trước demo cuối kỳ

- Bật Gemini Paid Tier và nạp trước **$10** nếu tài khoản được yêu cầu prepay.
- Tắt auto-reload và đặt project spend cap khoảng $10.
- Dùng Flash-Lite mặc định; chỉ dùng Flash cho một số summary không pass quality rules.
- Usage dự kiến trong hai tháng: khoảng $1–$4; tuy nhiên cash outlay có thể vẫn là $10 vì [Google billing](https://ai.google.dev/gemini-api/docs/billing) hiện nêu mức prepay tối thiểu $10. Credit chưa dùng hết hết hạn sau 12 tháng và nhìn chung không hoàn lại; billing/cap có độ trễ khoảng 10 phút nên vẫn phải giới hạn request ở application layer.

Không cần mua thêm OpenAI credit trừ khi Gemini gặp vấn đề thực tế trong spike. Nếu cần fallback trả phí, GPT-5 mini có cost cùng cấp độ và đủ quota cho demo.

## Quyết định đề xuất

Bắt đầu bằng **phương án $0** trong 6–7 tuần đầu. Một tuần trước buổi demo:

1. Kiểm tra quota Free Tier thực tế trong AI Studio.
2. Chạy replay toàn bộ dataset và xác nhận fallback.
3. Nếu muốn privacy tốt hơn hoặc quota không ổn định, nạp $10 cho Paid Tier.

AI không phải phần làm đội ngân sách. Rủi ro lớn hơn là **scope và thời gian tích hợp bốn database**, nên không nên dành thời gian săn nhiều free AI provider hoặc xây multi-provider routing trước khi vertical slice StoryLens hoạt động.
