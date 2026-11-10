# Tự động tổng hợp báo cáo ngày và tuần

## Hành vi

- Múi giờ theo `REPORT_TIMEZONE` (KPS: `Asia/Ho_Chi_Minh`); giờ chốt lấy từ `REPORT_CUTOFF_TIME`, mặc định 23:00. Chỉ lấy báo cáo đã khóa, theo quyền hiện tại của tài khoản chạy nền.
- Ngày làm việc mặc định thứ Hai–thứ Bảy. Sau giờ chốt: tạo/cập nhật bản AI ngày của từng báo cáo và bản phân tích toàn công ty kỳ 1 ngày. Giữ nguyên cấu trúc phân tích, căn cứ, ngân sách và giao diện hiện tại.
- Tuần: đợi hết hạn nộp bù của thứ Bảy (mặc định Chủ nhật 00:00), rồi tạo bản phân tích kỳ 7 ngày kết thúc thứ Bảy. Kỳ của engine là Chủ nhật trước–thứ Bảy; Chủ nhật không phải ngày làm việc theo cấu hình KPS. Không đổi nghĩa các kỳ lịch sử.
- Từ 08:00 hôm sau, khi backend hoạt động và bản phân tích hoàn tất/khớp nguồn, gửi riêng từng người nhận. Chủ nhật có hai thư: báo cáo ngày thứ Bảy và bản rút gọn báo cáo tuần. Bản tuần đầy đủ vẫn lưu trong hệ thống; email tối đa 5 kết quả, 3 việc tiếp tục, 3 vấn đề, 3 đề xuất, kèm liên kết đúng lần phân tích.
- Backend ngủ: các cycle lưu bền trong DB; lần thức dậy tiếp tục các cycle đến hạn. Mỗi tick khám phá tối đa 14 ngày, xử lý tối đa 14 cycle, gửi tối đa 10 thư. Backlog lớn được xử lý dần, không phụ thuộc mở trang AI hay trình duyệt. Không cam kết thức dậy đúng 08:00.
- `REPORT_AUTOMATION_START_DATE` là ngày báo cáo đầu tiên được tự động xử lý. Chu kỳ tuần đầu tiên có thứ Bảy từ mốc này trở đi và dùng trọn kỳ 7 ngày của engine, có thể chứa ngày trước mốc. Chọn thứ Hai bắt đầu vận hành để phù hợp tuần nghiệp vụ. Không tự phát sinh thư cho các ngày kết thúc trước mốc.
- Báo cáo bù: trước khi bắt đầu phân phối thư, dùng bản AI mới khớp nguồn. Sau khi đã phân phối, tiếp tục cập nhật bản nội bộ trong thời hạn nộp bù; giữ nguyên bản email và không gửi lại thư ngày cũ. Nếu Gmail chưa sẵn sàng và chưa tạo được thư nào, chưa chốt nội dung email. Khi có ít nhất một thư vào outbox, cả đợt người nhận/nội dung được giữ cố định.
- `WORK_REPORT_AI_USE_DAILY_DOCUMENTS=true`: đợi đủ bản ngày đã kiểm tra và khớp nguồn trước khi phân tích công ty/tuần. `false`: giữ chế độ nguồn thô hiện tại, đồng thời vẫn tạo bản AI ngày cho tab ngày. Không tự đổi cờ này trong quá trình bật automation.
- AI lỗi/thiếu phần: không tự gọi retry hay regenerate. Giữ phần đã thành công, chưa gửi thư hoàn tất; người vận hành xử lý trên giao diện hiện tại. Một bản mới do người vận hành tạo, hoàn tất và khớp nguồn/cấu hình có thể được cycle tiếp nhận.
- Thư đã được provider tiếp nhận không được gửi lại. Timeout/5xx hoặc tiến trình gửi bị gián đoạn chuyển `unknown`, yêu cầu kiểm tra thủ công. Chỉ các lỗi được transport xác định có thể thử lại (ví dụ 429) dùng chính outbox cũ, tối đa 3 lần. `accepted` là Gmail tiếp nhận, chưa phải xác nhận thư vào inbox/đã đọc.

## Migration và cấu hình

Tính năng mặc định tắt. Không chạy migration, gọi AI hay gửi mail lúc boot. Cần migration bổ sung **M37**:

`2026_11_10_020000_create_report_automation_tables`

Hai bảng mới: `report_automation_cycles`, `report_automation_deliveries`. Không sửa báo cáo hay lần phân tích cũ. Nội dung thư được mã hóa, giữ liên kết tới bản phân tích đã gửi. Unique keys theo tổ chức/ngày/loại và cycle/người nhận, kèm khóa transaction để ngăn chạy trùng. Không rollback xóa lịch sử khi bảng đã có cycle; tắt cờ khi cần rollback ứng dụng.

Đưa M37 qua quy trình migration đã có (`tools/Migrate-Kps.ps1` / workflow migration), đọc kế hoạch và xác nhận đúng môi trường trước khi apply. Deploy code không thay thế bước migration. Không chạy `migrate:fresh` trên staging/production.

| Biến | Giá trị / yêu cầu |
| --- | --- |
| `REPORT_AUTOMATION_ENABLED` | `true` để tạo và theo dõi chu kỳ |
| `REPORT_AUTOMATION_MAIL_ENABLED` | Ban đầu `false`; sau nghiệm thu mới `true` |
| `REPORT_AUTOMATION_SCHEDULER_MODE` | `embedded` trên Render hiện tại; `external` nếu đã có `schedule:run` mỗi phút; `off` để dừng lịch nền |
| `REPORT_AUTOMATION_ACTOR_USER_ID` | ID tài khoản chạy nền đang hoạt động, có vai trò OWNER/ADMIN/EXECUTIVE_ASSISTANT và quyền `ai.insights.view` + `reports.view` toàn tổ chức |
| `REPORT_AUTOMATION_START_DATE` | `YYYY-MM-DD`; đặt rõ ngày kích hoạt, tránh gửi hàng loạt lịch sử |
| `REPORT_AUTOMATION_RECIPIENT_USER_IDS` | ID admin/trợ lý phân cách dấu phẩy, tối đa 50. OWNER được hỗ trợ như vai trò quản trị. Không tự suy ra địa chỉ hoặc gửi cho mọi nhân viên |

Các tài khoản nhận phải đang hoạt động, cùng tổ chức, có vai trò quản trị/trợ lý và đọc được trọn phạm vi bản phân tích. Kiểm tra lại ngay trước mỗi lần provider gửi. Người nhận sai quyền không cản người nhận hợp lệ. Không dùng ID của tổ chức khác. Tài khoản chạy nền được lưu trên cycle; khi đổi tài khoản cần kiểm tra các cycle chưa hoàn tất, không tự nhận thay danh tính cũ.

Giữ cấu hình AI/queue hiện có, tối thiểu:

```dotenv
WORK_REPORT_AI_ENABLED=true
WORK_REPORT_AI_DRIVER=openai
WORK_REPORT_AI_QUEUE_CONNECTION=work_report_ai
WORK_REPORT_AI_WORKER_MODE=embedded
DAILY_REPORT_AI_ENABLED=true
DAILY_REPORT_AI_DRIVER=openai
DAILY_REPORT_AI_QUEUE_CONNECTION=daily_report_ai
DAILY_REPORT_AI_WORKER_MODE=embedded
```

Mỗi queue phải có consumer riêng; không cần bật dispatcher cũ `DAILY_REPORT_AI_AUTO_DISPATCH` vì automation mới đã cấp việc theo cycle. Nếu dispatcher cũ đang bật, việc cấp bản ngày vẫn dùng cache key chung để tránh trùng.

Giữ nguyên API key, model, giá token và ngân sách đã được duyệt. Tự động hóa không tăng bất kỳ giới hạn chi phí nào. Thiếu ngân sách/giá/quyền API vẫn dừng theo kiểm tra sẵn có. Kỳ quá cũ ngoài `WORK_REPORT_AI_MAX_DAYS_BACK` cần người vận hành xem lại mốc kích hoạt/phạm vi lịch sử; không được tự bỏ nguồn hoặc tăng giới hạn âm thầm.

Mail dùng kết nối Gmail đã có: `KPS_MAIL_ENABLED=true`, `KPS_MAIL_SEND_ENABLED=true`, OAuth còn hiệu lực, `FRONTEND_URL` là origin HTTPS production chính xác. Không cần mật khẩu hay OAuth/token riêng của từng người nhận. Đây là thư nội bộ từ Gmail chung đã kết nối. Scheduler nhúng gọi cả bước xử lý outbox tự động, không cần mở màn hình mail hay thêm dịch vụ cron trả phí.

## Triển khai theo hai bước

1. Merge PR vào **staging** sau review; apply M37 và deploy staging. Đặt tài khoản/mốc/người nhận thử đúng staging; bật generation, **mail vẫn false**. Kiểm tra bản ngày/tuần, báo cáo bù, nguồn thiếu/lỗi và giới hạn chi phí bằng tài khoản thử. Chỉ dùng provider/mail giả trong CI, không gửi thư thật từ test.
2. Sau nghiệm thu, người quản lý promote staging → main, apply M37 trên production, deploy và cấu hình tương ứng production. Bật generation trước, xem trạng thái và kiểm tra bản lưu; sau đó mới bật mail. Chọn mốc để các thư backlog đều là những ngày thực sự muốn gửi. Không tự bật mail production trong PR.

Sau khi đổi ENV cần deploy/restart đúng dịch vụ để cache cấu hình và các tiến trình nhúng nhận giá trị mới. Không cấu hình đồng thời hai lịch embedded/external; khóa DB vẫn chống trùng nhưng gây tốn truy vấn.

## Vận hành

```sh
php artisan report-automation:status
php artisan report-automation:tick
```

`status` chỉ đọc; `tick` có thể tạo việc AI và gửi thư thật khi các cờ đang bật. Dùng `status` để kiểm tra trước. Trong Render embedded, tick chạy lại mỗi 60 giây sau khi tiến trình trước kết thúc, lần đầu chờ 60 giây để web khởi động (`REPORT_AUTOMATION_BOOT_DELAY_SECONDS` có thể điều chỉnh).

Các trạng thái đáng chú ý:

| Trạng thái / mã | Xử lý |
| --- | --- |
| `waiting_daily` / `REPORT_AUTOMATION_DAILY_NOT_READY` | Kiểm tra queue và các bản AI ngày thiếu/lỗi; xử lý phần lỗi trên giao diện hiện tại |
| `partial`, `failed` / `REPORT_AUTOMATION_AI_REQUIRES_REVIEW` | Xem lỗi của lần phân tích được liên kết; không tạo lại hàng loạt khi chưa xác định nguyên nhân |
| `REPORT_AUTOMATION_MAIL_NOT_READY` | Kiểm tra cờ mail, OAuth/kết nối Gmail và người nhận; khi chưa có thư nào vào outbox, nội dung vẫn được cập nhật theo nguồn |
| Delivery `blocked` | Sai vai trò/quyền/email/tổ chức; sửa cấu hình trước khi phân phối |
| Delivery `unknown` | Kiểm tra Gmail đã gửi hay chưa; không tự gửi lại hoặc xóa outbox để lách trạng thái |
| `REPORT_AUTOMATION_CYCLE_REQUIRES_REVIEW` | Kiểm tra migration/cấu hình, phạm vi lịch sử, nguồn quá lớn và lần phân tích; không in nguồn hay bí mật vào log |

Đọc chi tiết delivery bằng DB read-only: `cycle_id`, `recipient_user_id`, `mail_message_id`, `status`, `error_code`; mail đã xếp hàng cũng xuất hiện trong lịch sử mail của tài khoản chạy nền. `mail_analysis_id` là bản đã chốt gửi; `analysis_id` có thể là bản nội bộ mới hơn sau báo cáo bù.

Tạm dừng: `REPORT_AUTOMATION_MAIL_ENABLED=false` dừng cả thư tự động đã queued mà chưa gửi, kể cả khi chạy `kps-mail:process`; không ảnh hưởng thư soạn thủ công. `REPORT_AUTOMATION_ENABLED=false` dừng toàn luồng và giữ nguyên lịch sử. Lần bật lại tiếp tục từ DB, không tạo bản/thư trùng.
