# Hướng dẫn gán nhãn và review StoryLens cho thành viên không code

> Evaluation protocol supporting `docs/specs/vietfeed-nosql-storylens.md`.

## 1. Mục tiêu công việc

Công việc gồm hai phần độc lập:

1. Gom các RSS article thật vào cùng một **Story** khi chúng nói về cùng một sự kiện cụ thể.
2. Review bản tóm tắt StoryLens để kiểm tra từng claim có được evidence hỗ trợ hay không.

Người gán nhãn không cần biết MongoDB, Cassandra, Neo4j, Redis, code hoặc thuật toán clustering.

## 2. Những điều tuyệt đối không làm

- Không xem kết quả gom nhóm của hệ thống trước khi tự gán nhãn.
- Hai annotator không trao đổi label trong vòng independent annotation.
- Không dùng source tier để quyết định một bài “đúng” hay “sai”.
- Không gom hai bài chỉ vì cùng category, cùng nhân vật hoặc dùng từ khóa giống nhau.
- Không tự kiểm chứng chân lý ngoài phạm vi bài toán. Mục tiêu là xác định bài có nói về cùng event hay không và summary có bám evidence hay không.
- Không dùng AI để gán nhãn ground truth.

## 3. Định nghĩa cần nhớ

### Article

Một bài/RSS item từ một nguồn, có title, excerpt hoặc full text, URL và thời gian xuất bản.

### Story

Một sự kiện hoặc chuỗi cập nhật trực tiếp của cùng một sự kiện thực tế, có thể được nhiều báo đưa tin.

> Hai article thuộc cùng Story khi một người đọc hợp lý có thể theo dõi chúng trên cùng một timeline mà không đổi sang một sự kiện khác.

### Cùng chủ đề không đồng nghĩa cùng Story

Ví dụ:

- “Bộ GD&ĐT công bố phương án thi mới” trên ba báo: **cùng Story**.
- “Bộ GD&ĐT công bố phương án thi mới” và “Đại học X công bố điểm chuẩn”: cùng chủ đề giáo dục nhưng **khác Story**.
- “Bão số 3 đổ bộ” và “Thiệt hại sau bão số 3”: **cùng Story** nếu bài sau là hậu quả/cập nhật trực tiếp.
- “Giá vàng sáng thứ Hai” và “Giá vàng sáng thứ Ba”: mặc định **khác Story**, trừ khi cả hai mô tả cùng một biến động/sự kiện kinh tế đang tiếp diễn và có liên hệ trực tiếp rõ ràng.

## 4. Quy tắc quyết định cùng Story

Xem lần lượt các câu hỏi sau:

1. Các bài có nói về cùng hành động/sự cố/quyết định/trận đấu/công bố cụ thể không?
2. Chủ thể chính có giống nhau không?
3. Địa điểm và khoảng thời gian có tương thích không?
4. Bài sau có phải update, hậu quả, phản ứng hoặc phân tích trực tiếp của event ban đầu không?
5. Nếu đặt hai bài trên cùng timeline, người đọc có thấy đó là một diễn biến liên tục không?

Nếu phần lớn câu trả lời là “có”, gom cùng Story. Nếu chỉ giống category/từ khóa/nhân vật nhưng hành động chính khác nhau, tách Story.

## 5. Edge cases

### Bài trùng hoặc báo đăng lại

Gom cùng Story. Không cần quyết định nguồn nào sao chép nguồn nào.

### Bài follow-up

Gom cùng Story nếu nó trực tiếp cập nhật hậu quả, phản ứng hoặc trạng thái của event ban đầu. Nếu bài chuyển sang một quyết định/event mới thì tách.

### Một article nói về nhiều event

Chọn event chiếm phần lớn title/excerpt/nội dung. Ghi `multi_event_article` trong cột uncertainty reason.

### Không đủ thông tin

Đọc URL/full article nếu có. Nếu vẫn không chắc, đưa article vào một Story provisional và đặt confidence `low`; không đoán chắc chắn.

### Singleton

Một Story chỉ có một article là hợp lệ. Không ép article vào cluster khác để tránh singleton.

### Hai nguồn mâu thuẫn

Vẫn có thể là cùng Story. Mâu thuẫn nội dung không đồng nghĩa khác event.

## 6. Sheet gán nhãn clustering

Mỗi annotator tạo một bản copy riêng và chỉ sửa ba cột cuối:

| Cột | Ai điền | Ý nghĩa |
|---|---|---|
| `article_id` | Hệ thống chuẩn bị | UUIDv7 cố định |
| `title` | Hệ thống chuẩn bị | Tiêu đề RSS |
| `source` | Hệ thống chuẩn bị | Tên báo |
| `published_at` | Hệ thống chuẩn bị | Thời gian xuất bản |
| `excerpt` | Hệ thống chuẩn bị | RSS excerpt/text ngắn |
| `url` | Hệ thống chuẩn bị | Link để mở khi cần |
| `annotator_story_label` | Annotator | Label cục bộ như `A001`, `A002` |
| `confidence` | Annotator | `high`, `medium`, hoặc `low` |
| `uncertainty_reason` | Annotator | Để trống nếu chắc; nếu không thì ghi lý do ngắn |

Hai annotator không cần dùng cùng tên label. Ví dụ người 1 gọi cluster `A003`, người 2 gọi cluster `B012` vẫn so sánh được bằng việc các cặp article có nằm cùng nhóm hay không.

## 7. Quy trình làm việc

### Bước 1 — Training chung, không tính metric

- Cả nhóm cùng làm 10–15 article mẫu.
- Đọc và thảo luận từng edge case.
- Chỉnh lại cách hiểu guide nếu có điểm mơ hồ.
- Loại batch training khỏi evaluation chính.

### Bước 2 — Calibration độc lập

- Hai annotator tự làm cùng một batch 20 article, không trao đổi.
- Người phụ trách kỹ thuật so disagreement nhưng chưa cho xem algorithm output.
- Cả nhóm reconcile batch này để xác nhận quy tắc đã hiểu giống nhau.

### Bước 3 — Main annotation độc lập

- Hai annotator làm cùng 100–200 article thật.
- Không trao đổi label cho tới khi cả hai submit.
- Có thể dừng ở 100 article nếu deadline căng; ưu tiên chất lượng hơn số lượng.

### Bước 4 — Reconciliation

- Tool tạo danh sách các cặp/article bị bất đồng.
- Hai annotator giải thích ngắn dựa trên rule, không dựa trên “cảm giác”.
- Nếu vẫn bất đồng, người phụ trách kỹ thuật làm adjudicator.
- Ghi `gold_story_id` và `resolution_reason` vào gold sheet riêng; không sửa đè raw annotation.

### Bước 5 — Freeze dataset

- Lưu raw sheets của từng annotator, gold sheet, source snapshot timestamp và dataset version.
- Không đổi gold label sau khi xem kết quả thuật toán, trừ khi phát hiện lỗi dữ liệu rõ ràng; mọi thay đổi phải có changelog.

## 8. Quality checklist cho clustering annotation

Trước khi submit, annotator kiểm tra:

- Không có dòng thiếu `annotator_story_label`.
- Chỉ dùng `high`, `medium`, `low` cho confidence.
- Mọi `low` confidence đều có uncertainty reason.
- Không gom theo source tier hoặc category đơn thuần.
- Singleton được giữ nguyên khi không có bài cùng event.
- Không trao đổi label với annotator còn lại.

## 9. Review AI brief

### Đơn vị review là claim

Không đánh giá summary chỉ bằng “nghe ổn”. Tách từng bullet/câu thành claim và kiểm tra evidence IDs mà hệ thống đính kèm.

Mỗi claim được gán một support status:

- `supported`: evidence trực tiếp hỗ trợ toàn bộ claim.
- `partially_supported`: chỉ một phần claim được hỗ trợ hoặc diễn đạt mạnh hơn evidence.
- `unsupported`: không tìm thấy evidence hỗ trợ.
- `not_a_claim`: heading/transition không đưa ra thông tin kiểm chứng được.

### Sheet review AI

| Cột | Ý nghĩa |
|---|---|
| `story_id` | Story đang review |
| `summary_version` | Version output để không review nhầm |
| `claim_index` | Số thứ tự claim |
| `claim_text` | Nội dung claim |
| `evidence_ids` | Evidence hệ thống dẫn |
| `support_status` | Một trong bốn status trên |
| `attribution_correct` | `yes/no/not_applicable` |
| `new_name_number_date` | Có tên, số hoặc ngày mới không có trong evidence? |
| `needs_edit` | `yes/no` |
| `reviewer_note` | Giải thích ngắn nếu có lỗi |

### Quy tắc review

- Chỉ dùng evidence packet của StoryLens; không chấm dựa trên kiến thức cá nhân.
- Con số, ngày tháng, tên riêng và trích dẫn phải có evidence rõ ràng.
- “Các nguồn đều nói…” chỉ hợp lệ khi evidence thực sự có nhiều nguồn phù hợp.
- “Nguồn A đưa tin…” phải đúng attribution.
- Không yêu cầu reviewer kết luận nguồn nào đúng khi hai báo mâu thuẫn.
- `unsupported` hoặc sai attribution phải làm output bị reject/fallback, không chỉ ghi chú rồi publish.

## 10. Khối lượng đề xuất

- 10–15 article training chung.
- 20 article calibration độc lập.
- 100–200 article main annotation độc lập.
- 15–20 Story review AI, ưu tiên mỗi Story có nhiều nguồn.

Ước tính ban đầu: khoảng 2–4 giờ cho mỗi annotator tùy số bài phải mở full article. Chia thành nhiều phiên ngắn để tránh label ẩu vì mệt.

## 11. Tin nhắn giao việc có thể gửi nguyên văn

> Mỗi người sẽ nhận một bản copy cùng dataset RSS. Trước tiên đọc toàn bộ guide, sau đó làm batch calibration 20 bài. Khi vào batch chính, hai người không trao đổi label và không xem kết quả AI/algorithm. Với mỗi article, gán một Story label cục bộ, confidence và lý do nếu không chắc. Cùng chủ đề chưa chắc cùng sự kiện; singleton được phép. Sau khi cả hai submit, nhóm mới review các điểm bất đồng và tạo gold sheet. Phần AI cũng chấm từng claim dựa đúng evidence IDs, không dựa vào cảm giác hoặc tự tìm nguồn để quyết định bên nào đúng.

## 12. Deliverables phải nộp lại

Mỗi annotator nộp:

1. Raw clustering annotation sheet của chính mình.
2. AI claim-review sheet của chính mình.
3. Danh sách những rule/edge case còn khó hiểu.

Cả nhóm tạo thêm:

1. Reconciled gold clustering sheet.
2. Dataset version/changelog.
3. Metric report: pairwise precision/recall/F1, supported-claim rate, validation pass/fallback rate và admin edit/reject rate.
