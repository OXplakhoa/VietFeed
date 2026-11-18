# Hướng dẫn demo kiểm duyệt bình luận (Gate 9a + 9b)

Luồng: người dùng báo cáo bình luận, quản trị viên xem hộp thư báo cáo, xử lý (cảnh cáo / cấm, có thể ẩn bình luận) hoặc bác bỏ,
người bị xử lý kháng cáo, quản trị viên duyệt kháng cáo. Không có bản ghi kiểm duyệt nào bị xóa cứng.

## Chuẩn bị dữ liệu demo

Chỉ chạy trên môi trường dev bạn sẵn sàng thêm dữ liệu mẫu (seeder idempotent, không đụng `SourceSeeder`):

```bash
php artisan migrate
php artisan moderation:audit schema          # Gate 9b: bảng Cassandra moderation_events_by_case
php artisan db:seed --class=ModerationDemoSeeder
```

Gate 9b: báo cáo, biện pháp xử lý và vụ việc (mỗi bình luận một vụ) nằm trên MongoDB; hàng đợi duyệt ưu tiên trên Redis;
lịch sử quyết định trên Cassandra (ghi bất đồng bộ qua Redis Stream). Để lịch sử hiện lên, chạy bộ xử lý trong một terminal:
`php artisan moderation:audit consume --limit=100` (chạy lại sau mỗi bước, hoặc lặp bằng `watch`).

Gate 9.5 slice 5: ứng dụng không còn SQL. Lệnh `moderation:import-sql` và cảnh báo "bản ghi SQL chưa được chuyển" trên
`/admin/processing` đã bị gỡ; dữ liệu SQL cũ chỉ chuyển được bằng phiên bản tại tag `pre-sql-removal`
(xem `docs/database/nosql-local.md`, mục Gate 9.5 slice 5). Khi khôi phục hàng đợi: `php artisan moderation:rebuild-queue`.
Bộ xử lý lịch sử (`moderation:audit consume`) và `moderation:repair` hiện chạy thủ công (Gate 10 sẽ tự động hóa).

Tất cả tài khoản dùng mật khẩu `password`:

| Vai trò | Email | Tên hiển thị |
|---|---|---|
| Quản trị viên | `mod-admin@vietfeed.test` | Quản trị kiểm duyệt |
| Người bình luận (sẽ bị xử lý) | `mod-author@vietfeed.test` | Người bình luận demo |
| Người báo cáo 1 | `mod-reporter@vietfeed.test` | Người báo cáo một |
| Người báo cáo 2 | `mod-reporter2@vietfeed.test` | Người báo cáo hai |

Seeder tạo bài viết `/articles/bai-viet-demo-kiem-duyet` với một bình luận spam và hai báo cáo đang chờ duyệt.
Để demo từ đầu (người dùng tự báo cáo), đăng nhập `mod-reporter@vietfeed.test` và báo cáo bình luận trên bài viết trước khi chạy seeder
cho các bước còn lại.

## Các bước

1. **Báo cáo (người dùng).** Đăng nhập người báo cáo, mở bài viết, bấm `⋮` cạnh bình luận, chọn **Báo cáo**, chọn lý do
   (Spam, Quấy rối, ...), nhập mô tả (không bắt buộc, tối đa 2000 ký tự), **Gửi báo cáo**. Thử báo cáo lần hai để thấy thông báo
   "Bạn đã báo cáo bình luận này rồi.". Tác giả, quản trị viên, người chưa xác minh email hoặc đang bị cấm tạm thời/vĩnh viễn
   không báo cáo được.
2. **Hộp thư báo cáo (admin).** Đăng nhập quản trị viên, vào **Báo cáo** (`/admin/reports`). Mỗi dòng là một bình luận
   (gộp theo bình luận, cột *Số báo cáo*), mới nhất ở trên. Lọc theo trạng thái / lý do, tìm theo tên người báo cáo,
   nội dung bình luận hoặc mô tả.
3. **Trang chi tiết.** Bấm **Chi tiết**: nội dung bình luận tại thời điểm báo cáo, trạng thái hiện tại (Đang hiển thị /
   Đang bị ẩn / Bình luận đã bị xóa), tất cả báo cáo về bình luận đó, lịch sử xử lý của tác giả, liên kết bài viết.
4. **Xử lý.** Cột phải *Biện pháp xử lý*: chọn loại (Cảnh cáo, Cấm bình luận, Cấm tạm thời, Cấm vĩnh viễn), thời hạn,
   lý do, tùy chọn **Ẩn bình luận bị báo cáo**, bấm **Áp dụng**. Tất cả báo cáo đang chờ của bình luận chuyển sang *Đã xử lý*.
   Hoặc dùng *Đóng báo cáo không xử phạt*: **Bác bỏ** hoặc **Báo cáo sai**, kèm ghi chú.
5. **Bình luận bị ẩn.** Mở bài viết bằng tài khoản khác: bình luận biến mất. Tác giả và admin vẫn thấy, kèm nhãn
   "Bình luận đang bị ẩn".
6. **Người bị xử lý.** Đăng nhập `mod-author@vietfeed.test`: trang **Hồ sơ** hiển thị biện pháp đang hiệu lực (loại, lý do, hạn).
   - Cấm bình luận: không bình luận được, vẫn lưu/đề xuất bài.
   - Cấm tạm thời: đọc được, nhưng không bình luận, lưu bài, đề xuất, báo cáo; vẫn xem hồ sơ và kháng cáo.
   - Cấm vĩnh viễn: chuyển hướng tới `/banned` (có form kháng cáo và đăng xuất).
7. **Kháng cáo.** Nhập nội dung 10-2000 ký tự, **Gửi kháng cáo** (mỗi biện pháp một lần).
8. **Duyệt kháng cáo (admin).** `/admin/sanctions?status=pending_appeal` → **Chi tiết** → ghi chú → **Chấp nhận** hoặc **Từ chối**.
   Chấp nhận: biện pháp bị thu hồi, bình luận do biện pháp đó ẩn được hiện lại, báo cáo vẫn là *Đã xử lý*. Từ chối: không đổi gì.
9. **Thu hồi / xử lý không qua báo cáo.** Trang chi tiết biện pháp có nút **Thu hồi** (bản ghi vẫn nằm trong lịch sử,
   kèm ai/khi nào/ghi chú). Thu hồi cũng hiện lại các bình luận do biện pháp đó ẩn. Trang **Sửa người dùng** có form áp dụng biện pháp không cần báo cáo.
10. **Hàng đợi ưu tiên (9b).** Trên `/admin/reports` chọn **Hàng đợi ưu tiên**: các vụ đang chờ xếp theo số người báo cáo,
    mức độ nghiêm trọng (thù địch / quấy rối / tình dục > thông tin sai > spam / khác), rồi cũ nhất trước. Huy hiệu
    "N vụ chờ duyệt" và "N kháng cáo" đọc bộ đếm Redis. Xóa khóa Redis (chỉ trên DB thử nghiệm) hoặc Redis ngừng hoạt động:
    trang vẫn đúng thứ tự (đọc trực tiếp MongoDB, có ghi chú) và hàng đợi tự dựng lại; dựng lại thủ công:
    `php artisan moderation:rebuild-queue`.
11. **Lịch sử quyết định (9b).** Trang chi tiết báo cáo (và trang biện pháp xử lý) có khối **Lịch sử quyết định** đọc từ Cassandra:
    Báo cáo mới → Áp dụng biện pháp xử lý → Ẩn bình luận → Gửi kháng cáo → Chấp nhận kháng cáo → Thu hồi → Hiện lại bình luận,
    kèm người thực hiện, thời gian, phiên bản. Khi Cassandra chưa ghi xong hoặc đang ngừng: "đang chờ đồng bộ (audit pending)";
    mọi thao tác kiểm duyệt vẫn thành công. Sau khi Cassandra hoạt động lại: **Xử lý Story** (`/admin/processing`) → mục
    *Kiểm duyệt · lịch sử quyết định* → **Thử lại** các mục DLQ, rồi chạy consume.
12. **Kiểm tra trực tiếp trên từng CSDL.**
    - `mongosh`: `db.getSiblingDB('vietfeed').moderation_cases.findOne({comment_id: '<comment-uuid>'})` (trạng thái, `version`, `decisions`, `events`).
    - `redis-cli` (DB NoSQL đã cấu hình): `ZREVRANGE <prefix>moderation:queue:v1 0 -1 WITHSCORES`.
    - `cqlsh`: `SELECT occurred_at, type, actor_id, case_version, note FROM vietfeed.moderation_events_by_case WHERE case_id = <case-uuid>;`
      (`case_id` lấy từ `moderation_cases._id`; biện pháp không qua báo cáo dùng chính id của biện pháp).
13. **Xóa bình luận.** Xóa bình luận (tác giả hoặc admin) không làm mất báo cáo; trang chi tiết hiện "Bình luận đã bị xóa"
    cùng nội dung đã lưu.

Chạy bộ kiểm thử tương ứng: `vendor/bin/phpunit --filter Moderation` (gồm `ModerationNosqlTest` của Gate 9b; chỉ dùng Mongo
`vietfeed_test`, Redis DB15, Cassandra `vietfeed_test`).
