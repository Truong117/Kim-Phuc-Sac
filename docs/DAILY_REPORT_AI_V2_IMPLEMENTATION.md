# AI ngày → tổng hợp tuần

## Luồng đã triển khai

1. Báo cáo nguồn đã khóa được chụp bất biến vào `daily_report_ai_documents`; các phần xử lý nằm trong `daily_report_ai_units`. Nguồn và kết quả được mã hóa bằng APP_KEY. Bản gốc không bị sửa/xóa.
2. Daily output V3 có `work_groups` (nhóm công việc, diễn biến/kết quả/tồn đọng, tham chiếu nguồn), quan sát số lượng và nhận xét/đề xuất riêng. Mỗi đầu việc phải thuộc đúng một nhóm. Phần lỗi không được coi là đã tổng hợp; toàn bộ nguồn vẫn xem được.
3. Luna `gpt-6-luna` dùng Responses API/strict JSON schema, reasoning low, `store=false`. Provider không có công cụ mở URL. Không gửi danh tính/ngày nộp/URL minh chứng; che dữ liệu nhạy cảm trong nội dung theo quy tắc đang dùng cho tuần. Output kiểm tra với cả nguồn đã che và nguồn gốc; quote qua vùng đã che không khớp sẽ bị từ chối, không đoán lại.
4. Worker chạy queue riêng, lease token chống ghi đè, timeout 270s, lease 420s, retry_after 480s. Một lần HTTP tối đa 120s, không tự retry HTTP mơ hồ. Phần lỗi chỉ được thử lại theo thao tác có quyền, tối đa 2 lần/unit; phần đạt dùng lại.
5. Dispatcher quét 7 ngày gần nhất, chỉ báo cáo đã khóa theo ReportClock Việt Nam, kể cả nộp bù. Dùng một tài khoản được cấu hình rõ và quyền hiện tại (giao phạm vi ai.insights.view và reports.view); không tự chọn OWNER hay mở rộng scope. Source/config hash chống document/job trùng. Không tự retry lỗi đã xảy ra.
6. Khi bật adapter tuần, snapshot tuần đóng băng document_id/source_hash/schema cùng nhóm công việc và số liệu ngày. Chỉ đọc bản hoàn tất, đúng nguồn hiện tại và schema. Bản fake không được đưa vào lượt tuần trả phí. Ngày thiếu/lỗi/đã đổi nguồn chặn tạo lượt tuần, không âm thầm dùng dữ liệu thô thay thế.
7. AI tuần nhận nhóm công việc ngày và số liệu đã kiểm tra, không nhận lại toàn bộ nội dung/ghi chú gốc để trích xuất số lượng. Backend giữ nguồn gốc trong snapshot riêng để kiểm tra bằng chứng và đối chiếu UI. Các đầu việc vẫn phải được phủ đủ khi AI gom nhóm tuần. Chỉ sau danh sách nhóm mới nhận xét và đề xuất. Tổng số lượng dùng phép tính trên quan sát ngày, không cộng lại tổng ngày hay kế hoạch.
8. Tổng hợp công ty đọc danh sách nhóm tuần của nhân viên và nhận xét; adapter không đưa lại toàn bộ bản kê thô vào bước này. Chia batch theo ranh giới nhóm/nhận định; không tự cắt nội dung.

Nhóm chủ đề là cách trình bày, không chứng minh các dòng là cùng một nhiệm vụ hoặc sản lượng độc lập. Lũy kế/chưa rõ giữ tổng null; sản xuất và đăng tải phải khác metric. Các kiểm tra nguồn không chứng minh AI hiểu đúng ngữ nghĩa; vẫn cần nghiệm thu mẫu thật.

## Schema, phiên bản và lịch sử

Migration mới duy nhất của cả PR vẫn là M27: `2026_11_03_030000_create_daily_report_ai_documents.php`. Không thêm migration sau M27 cho bước nối tuần. Trên nền 26 bước, áp dụng M27 trước khi bật tính năng. Down từ chối nếu có lịch sử.

Bản ngày V2 và lượt tuần dùng prompt/schema cũ vẫn đọc được, không được relabel hoặc tự đổi kết quả. UI cho tạo bản ngày mới khi contract/driver đổi. Lượt tuần cũ cần tạo lượt mới để dùng contract `work-report-v2.5` / schema `work-report-v2.3`; không tiếp tục job cũ bằng chỉ dẫn mới.

## Cấu hình local/fake

Dùng DB local cô lập, giữ APP_KEY. Sau M27:

```dotenv
DAILY_REPORT_AI_ENABLED=true
DAILY_REPORT_AI_DRIVER=fake
DAILY_REPORT_AI_QUEUE_CONNECTION=daily_report_ai
WORK_REPORT_AI_USE_DAILY_DOCUMENTS=true
```

Từ backend:

```sh
php artisan config:clear
php artisan queue:work daily_report_ai --queue=ai-daily-reports --timeout=270 --tries=1 --sleep=1
```

Vào `/ai/insights?view=daily`, tạo bản ngày; khi hoàn tất các ngày trong kỳ, sang Theo kỳ tạo lượt tuần. Luồng tuần vẫn cần worker `ai-work-reports` hiện có nếu không dùng sync local. Fake chỉ kiểm tra luồng/phủ nguồn, gom chính xác cùng nội dung; không giả lập nhận xét hay khả năng gom ngữ nghĩa của Luna/Sol.

Tự động quét là một tùy chọn riêng, mặc định false:

```dotenv
DAILY_REPORT_AI_AUTO_DISPATCH=true
DAILY_REPORT_AI_DISPATCH_USER_ID=<id người dùng có quyền phù hợp trong DB môi trường này>
```

```sh
php artisan daily-report-ai:dispatch
php artisan daily-report-ai:recover
php artisan schedule:list
```

Lệnh dispatch chạy lại idempotent; số requested là số report đã yêu cầu/mở cache, không phải số paid call. Kết quả lỗi từng report được đếm, CLI trả thất bại nếu có lỗi cấu hình/kích thước. Thu hồi quyền tài khoản dispatch phải chặn xử lý.

Ứng dụng đã đăng ký lịch 5 phút; môi trường triển khai PHẢI có Laravel scheduler thực sự chạy (`schedule:run` mỗi phút hoặc `schedule:work`) và queue worker độc lập. Không có scheduler/worker thì code không tự chạy chỉ vì web đã deploy. PR không tạo/chỉnh dịch vụ Render hay bật ENV.

## Cấu hình Luna (chỉ bật sau nghiệm thu staging)

```dotenv
DAILY_REPORT_AI_DRIVER=openai
DAILY_REPORT_AI_MONTHLY_BUDGET_USD=<trần dùng chung đã duyệt>
DAILY_REPORT_AI_MAX_DOCUMENT_BUDGET_USD=1
DAILY_REPORT_AI_INPUT_USD_PER_MILLION_TOKENS=<giá đầu vào đã xác minh>
DAILY_REPORT_AI_OUTPUT_USD_PER_MILLION_TOKENS=<giá đầu ra đã xác minh>
DAILY_REPORT_AI_OUTPUT_TOKENS=16000
```

Dùng OPENAI_API_KEY/OPENAI_BASE_URL đang được quản lý trong môi trường; khóa không lưu trong snapshot/job/log. Không dùng khóa thật trong test. Output default 16000 token, điều chỉnh 1000–32000; không có trần nội dung 3000 ký tự. Nếu API trả incomplete, phần bị lỗi rõ, không coi JSON dở dang là hoàn tất.

**Ngân sách:** Gateway hiện có một ledger theo environment/provider/tháng, không phải mỗi feature một ngân sách độc lập. AI ngày dùng ledger này; cap hiệu lực không lớn hơn cap ngày và các cap dương KPS_AI/WORK_REPORT_AI hiện có. Nếu không có cap tổng dương, không bật paid. Không reset ledger hoặc nhân đôi allowance. Trần ngày nhỏ hơn tổng sẽ chặn AI ngày khi tổng chi chung đã vượt trần đó; đây KHÔNG phải quota chi phí riêng của ngày. Tuần có thể dùng phần còn lại theo cấu hình hiện hữu. Nếu cần quota feature độc lập phải thêm thiết kế ledger riêng.

Trước tạo document, kiểm tra dự phòng tối đa cho toàn bộ parts × số lần xử lý cho phép × giá/max token; vượt trần document thì từ chối trước job/API. Gateway tiếp tục giữ reserve chi phí tháng cả khi outcome mơ hồ. API usage thật ghi trong ai_usage_records feature daily_report_document; không tự suy ra phí đo được từ dự phòng.

Giá/model đã đối chiếu tài liệu chính thức 2026-11-03: https://developers.openai.com/api/docs/models/gpt-6-luna (Standard: input $0.10/output $0.50 mỗi triệu token tại thời điểm kiểm tra). Không hardcode giá này; operator xác nhận giá áp dụng trước bật.

## Phạm vi nghiệm thu

- Ngày thiếu là thiếu bản AI hợp lệ của **báo cáo đã khóa hiện có**, không suy luận nhân viên vắng/chưa nộp từ roster hoặc ngày nghỉ.
- Nguồn đổi sau snapshot tuần không làm đổi lịch sử/job đã xếp; tạo lượt mới để nhận bản ngày mới. Tạo lại từ bản nguồn cũ vẫn giữ nguồn cũ.
- Báo cáo chỉ có kế hoạch không được biến thành kết quả đạt được.
- Một nhóm ngày quá lớn bị chặn rõ để tổng hợp lại nhóm nhỏ hơn; không âm thầm mất dữ liệu.
- Chưa có tổng hợp tháng, tích hợp MXH, xác minh link, hoặc tự tạo báo cáo tuần theo lịch.
- Chưa chạy gọi Luna/Sol trả phí hay nghiệm thu browser trên staging trong phiên này. HTTP tests dùng provider mô phỏng; không chứng nhận chất lượng ngôn ngữ/ngữ nghĩa của model thật.
- Không merge/deploy/đổi ENV hay migrate staging/production trong phiên triển khai này.

## Kiểm tra trong phiên triển khai

- PHPUnit toàn bộ: 853 tests / 8023 assertions đạt; sau tối ưu redaction/order nguồn chạy lại nhóm kiểm thử ngày/tuần liên quan.
- Frontend Node: 268 tests đạt; TypeScript/Vite build đạt.
- ESLint: 0 lỗi, 4 cảnh báo fast-refresh có sẵn. Build còn cảnh báo bundle lớn có sẵn.
- Test HTTP mô phỏng kiểm tra Luna/strict schema/store=false/output 16000, cache không gọi lại, incomplete không hoàn tất, cap không nâng ngân sách chung, và chặn trước dispatch khi quá dự phòng document.
- Test luồng kiểm tra 10+30=40, bản tuần bất biến sau nguồn đổi, chặn ngày stale/thiếu và fake-day trong paid-week, quét báo cáo khóa/nộp bù idempotent, phủ đủ nhóm và thứ tự nhóm trước nhận xét ở UI.

## Worker/scheduler trong container hiện hữu (bổ sung sau PR #89)

`start-service.sh` có thêm ba vai trò độc lập: `daily-worker`, `daily-recovery`, `daily-scheduler`. Mặc định `DAILY_REPORT_AI_WORKER_MODE=off`, không tự kích hoạt AI ngày khi deploy. Giữ nguyên các mode worker/recovery của tuần.

Để nghiệm thu trên dịch vụ staging hiện hữu, cấu hình **sau khi mã mới đã deploy**:

```dotenv
DAILY_REPORT_AI_ENABLED=true
DAILY_REPORT_AI_DRIVER=fake
DAILY_REPORT_AI_QUEUE_CONNECTION=daily_report_ai
DAILY_REPORT_AI_WORKER_MODE=embedded
DAILY_REPORT_AI_AUTO_DISPATCH=false
WORK_REPORT_AI_USE_DAILY_DOCUMENTS=false
```

Để nghiệm thu toàn luồng fake trên staging cô lập, đặt thêm `WORK_REPORT_AI_DRIVER=fake` và `WORK_REPORT_AI_USE_DAILY_DOCUMENTS=true`; không đưa bản ngày fake vào tuần openai. Khi nghiệm thu Luna thật, giữ driver tuần đã xác minh và chỉ bật adapter sau khi đủ bản ngày openai.

Lúc này nút thủ công có thể tạo job, consumer riêng xử lý queue `ai-daily-reports`, vòng phục hồi kiểm tra mỗi 60 giây. Nếu báo cáo tuần đang dùng driver openai thì chỉ bật adapter sau khi đã có bản ngày openai hoàn tất; fake-day không được cấp vào paid-week.

Khi đã xác minh principal đúng môi trường/phạm vi và muốn thử tự động, đặt `DAILY_REPORT_AI_AUTO_DISPATCH=true` cùng `DAILY_REPORT_AI_DISPATCH_USER_ID` đã xác minh. Dispatcher chạy mỗi 300 giây trong tiến trình riêng, không chiếm worker và không xử lý request web. Không chạy thêm Laravel scheduler hoặc cron cho cùng dispatcher khi dùng mode embedded; chọn một cơ chế để tránh quét trùng. Atomic source/config cache vẫn chống document/job trùng giữa các tiến trình.

Boot delay mặc định 60 giây (`DAILY_REPORT_AI_BOOT_DELAY_SECONDS`) để chuyển giao container; trong thời gian này UI có thể đang chờ job. Consumer tự khởi động lại nếu thoát. Khi dừng, PID 1 chuyển SIGTERM tới các tiến trình con và đợi job trước khi dừng web. Nếu nền tảng SIGKILL trước khi job xong, cơ chế lease/recovery hiện hữu vẫn áp dụng; không hứa thời gian drain dài hơn cấu hình nền tảng.

`DAILY_REPORT_AI_WORKER_MODE=external` để dịch vụ worker/scheduler riêng quản lý vòng đời. Khi off/external hoặc feature disabled, container web không chạy các tiến trình AI ngày. Không đổi plan, tạo dịch vụ có phí hoặc thêm secret qua mã này.

**Giới hạn Render Free đã kiểm tra 2026-11-03:** web service ngủ sau thời gian không có inbound traffic; tiến trình nhúng không phải một dịch vụ luôn chạy. Nghiệm thu trong phiên đang dùng được; không cam kết tổng hợp đúng giờ ban đêm khi web đang ngủ. Catchup sẽ quét nguồn đủ điều kiện khi dịch vụ hoạt động lại. Muốn tự động liên tục cần compute luôn chạy hoặc hạ tầng scheduler/worker riêng được lựa chọn và duyệt chi phí. Tham khảo https://render.com/docs/free.

Lệnh dispatch ghi mã lỗi an toàn khi principal/quyền/DB chưa sẵn sàng; không đưa raw exception/connection vào output của scheduler. Bộ kiểm thử shell dùng tiến trình con giả xác minh cả hai consumer tự restart và drain độc lập, chế độ manual không dispatch tự động, off/external/disabled không sinh tiến trình ngày. Không phải kiểm thử gọi provider thật trên Render.
