# AI ngày V2 — mốc triển khai 1 (nguồn, hợp đồng, giao diện, fake)

## Đã có trong PR này

- Bổ sung hai bảng documents/units bằng migration `2026_11_03_030000_create_daily_report_ai_documents`. Trên nền đã nghiệm thu 26 migration, đây là bước thứ 27. Giữ nguyên nguồn báo cáo và lịch sử AI V1/tuần; down từ chối nếu đã có lịch sử mới.
- Nguồn bất biến theo từng báo cáo đã khóa: report_date, người viết, bản kê đầy đủ, kết quả, ghi chú/vướng mắc, kế hoạch theo dòng, minh chứng chưa xác minh, cờ bù và thời điểm nộp thật. Nội dung snapshot/input/result mã hóa bằng APP_KEY.
- Mỗi source/config hash dùng lại một document trong cùng tổ chức, kể cả nhiều quản lý bấm. Nguồn thay đổi tạo revision mới; đọc lại lịch sử vẫn kiểm tra quyền hiện tại.
- Chia phần theo đầu việc đầy đủ; dữ liệu quá lớn bị từ chối rõ trước khi ghi document, không tự cắt nguồn. Danh sách ngày phân trang 25 báo cáo, hiển thị tổng số và trang; không ngầm bỏ các báo cáo còn lại.
- Worker fake chạy từng unit trên queue `ai-daily-reports`, claim bằng lease token; duplicate delivery không chạy lại phần đạt, stale worker không ghi đè kết quả. Retry phần lỗi có giới hạn, giữ phần đạt; recover lease hết hạn không gọi provider. Trạng thái partial/failed rõ và bản kê nguồn luôn xem được.
- Hợp đồng output có nguồn item/quote, kiểm tra report/item/đơn vị/số lượng, chỉ chấp nhận kết quả của việc COMPLETED; kế hoạch không được đưa vào kết quả đạt. Kế thừa quy tắc số lũy kế/chưa rõ của tuần; cộng số phát sinh với fixed-point sáu chữ số thập phân. Nhận xét/đề xuất có trích nguồn; số chưa rõ không hiển thị tổng chắc chắn.
- Giao diện tại `/ai/insights?view=daily`: đủ công việc/kết quả từng nhân viên trước nhận xét/đề xuất, kế hoạch riêng, nguồn bù, minh chứng chưa xác minh, tiến độ/retry, nguồn đã đổi. Chuyển về Theo kỳ giữ luồng tuần cũ.
- Vite bỏ theo dõi thư mục vendor/storage PHP khỏi HMR, tránh tràn file watcher khi kiểm thử frontend; không thay pipeline CI/CD hay HTTP proxy.

## Giới hạn của mốc 1

**Chỉ hỗ trợ fake**, không có nhận xét hoặc số lượng AI giả định. Provider fake trả danh sách quan sát/nhận xét trống; UI ghi rõ “chưa gọi AI thật”. Các ví dụ 40 sp và 5 ảnh/3 video kiểm tra hợp đồng/validator bằng fixture, chưa chứng minh model Luna trích xuất đúng văn bản thật.

Model đã chọn cho bước tiếp theo là 6 Luna; PR này chưa nối provider trả phí, chưa tự quét/chạy cuối ngày và chưa chuyển nguồn báo cáo tuần sang ngày. Bộ bật mặc định false; driver ngoài fake bị từ chối trước khi dispatch. Không gọi URL/MXH hoặc backfill lịch sử tự động.

Phát hiện cần giải quyết trước bước Luna: OpenAiGateway hiện dùng một AiBudgetPeriod theo environment/provider/tháng, không tách theo feature. Inject cấu hình giá/limit riêng đơn thuần chưa tạo ngân sách ngày độc lập. Cần thiết kế/tái kiểm thử reservation/quota ngày mà vẫn giữ giới hạn tổng, usage và phí của tuần/lịch sử; không đặt lại ledger chi phí hoặc nhân đôi tổng ngân sách. Danh tính scheduler cũng cần phù hợp Gateway requested_by_user_id và scope thực tế.

## Thử ở local/fake cô lập

Dùng DB local thử nghiệm đã xác nhận, **không lấy .env production/staging**. Giữ APP_KEY nhất quán để đọc nguồn đã mã hóa. Áp dụng migration mới trên DB thử nghiệm theo workflow local hiện có, rồi cấu hình:

```dotenv
DAILY_REPORT_AI_ENABLED=true
DAILY_REPORT_AI_DRIVER=fake
DAILY_REPORT_AI_QUEUE_CONNECTION=daily_report_ai
```

Chạy worker từ thư mục backend:

```sh
php artisan queue:work daily_report_ai --queue=ai-daily-reports --timeout=270 --tries=1 --sleep=1
```

Tạo báo cáo đã khóa bằng fixture local; vào Phân tích AI → Theo ngày → chọn ngày → Tạo/mở tổng hợp. Mở lại hoặc bấm lại không tạo document/job trùng. Không thay giờ khóa production để tạo fixture. Khi thử worker bị gián đoạn, sau lease 420 giây:

```sh
php artisan daily-report-ai:recover
```

Lệnh chỉ đánh dấu unit quá hạn; người có quyền có thể Thử lại phần lỗi (tối đa 2 lần xử lý/part). Không reset attempts để lách giới hạn. `retry_after=480` lớn hơn timeout/lease; worker chạy ngoài request. Mốc fake này chưa cấu hình scheduler.

## API mới

Cùng Sanctum + ai.insights.view + reports.view và ReportScopeService như luồng hiện tại:

- GET `/api/ai/daily-documents?date=YYYY-MM-DD&page=1`: inventory ngày được phép xem, document mới nhất, changed-source và pagination. Khi disabled không query các bảng mới.
- POST `/api/ai/daily-documents/reports/{report}`: chốt nguồn đã khóa, tạo hoặc dùng lại document; 202. Bấm lại không tự retry một document lỗi.
- GET `/api/ai/daily-documents/{document}`: đọc snapshot/result theo quyền hiện tại.
- POST `/api/ai/daily-documents/{document}/retry`: chỉ các part failed còn lượt, không xử lý lại phần completed.

Không có endpoint chấp nhận snapshot/provider/settings/employee ID do client gửi để ghi đè nguồn. Báo cáo chưa khóa và nguồn ngoài scope không dùng được. Người chỉ có thành viên tổ chức khác bị middleware KPS từ chối.

## Kiểm tra và phát hành

Test backend gồm quyền/khóa/disabled, encrypted snapshot, idempotency/cached reads, version nguồn, phần lớn/rollback trước ghi, partial/retry/recovery, stale worker, không cho paid driver, kế hoạch không thành kết quả, số lũy kế và nhiều đơn vị. SSR frontend kiểm tra đủ nguồn khi partial, XSS/URL, nhãn fake/chưa xác minh, trích dẫn và giữ snapshot cũ. Có kịch bản browser `e2e/daily-documents.spec.ts` dùng API giả với fixture E2E có sẵn; chạy thật browser cần môi trường E2E cô lập.

Không chạy migration hoặc bật feature trên môi trường dùng chung trong lúc viết PR. Sau CI và nghiệm thu local mới dùng migration tool với staging, deploy và bật fake; production vẫn tắt cho đến khi nghiệm thu. Chưa cần thêm API key hoặc đổi model/budget đang vận hành.
