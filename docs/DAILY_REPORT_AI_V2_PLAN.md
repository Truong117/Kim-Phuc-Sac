> Tiến độ hiện tại: xem [DAILY_REPORT_AI_V2_IMPLEMENTATION.md](DAILY_REPORT_AI_V2_IMPLEMENTATION.md). Phần trạng thái chuẩn bị bên dưới là lịch sử thiết kế, không phải trạng thái mã hiện tại.

# AI báo cáo ngày V2 — chuẩn bị triển khai Luna

Ngày 03/11/2026 (Asia/Ho_Chi_Minh). Đây là thiết kế để triển khai, chưa phải tính năng đã bật.

## Nền và quyết định hiện tại

- Staging `1223bd14fae930fd95680165fae0aea481306b90`, main `8a79b3093cf02d0248d034e17bc50c3a4bcddbd9` cùng tree `c840601adedc0e7f8228184e818133c0157e148f`; đã bao gồm form V3 và migration tool. Branch mới lấy staging làm nền.
- Người vận hành đã chạy M25/M26 và deploy hai backend. Ảnh StatusOnly của tool xác nhận mỗi môi trường 26 applied / 0 pending, đúng account và TLS. Đây là bằng chứng người vận hành cung cấp, không phải kết nối DB trực tiếp từ phiên chuẩn bị.
- Model người dùng đã chốt: **6 Luna**, ID cấu hình dự kiến `gpt-6-luna`. Xác minh quyền truy cập API và hợp đồng structured output trên staging trước khi bật thật. Không suy ra quyền truy cập API từ gói ChatGPT/Codex; không tự chuyển sang model khác.
- Bốn nhóm form đã nghiệm thu: hoàn thành (kết quả bắt buộc từng việc), đang làm, kế hoạch ngày mai (bắt buộc; mỗi dòng một việc), vấn đề/hỗ trợ. Giữ nguyên form và API hiện tại.
- Mục tiêu của sếp: **kê đủ công việc và kết quả từng người trước, rồi nhận xét/đề xuất có căn cứ**. Số liệu tổng hợp phải kiểm chứng được về report/item/ngày.
- Tài liệu này là kế hoạch bước AI sau form, thay cho các giả định cũ trong DAILY_REPORT_V3_PREPARATION.md về schema bản nháp, ID đầu việc, năm khối form, kết quả/kế hoạch tùy chọn và tình trạng migration chưa áp dụng.

## Phát hiện từ mã hiện có

| Phần | Hiện trạng | Hướng xử lý |
| --- | --- | --- |
| AI ngày V1 | AiDailyReportService + GenerateAiDailyReport, gom theo người yêu cầu/ngày; 1800 output token, job timeout 100 giây, tries=2 | Giữ lịch sử V1; tạo hợp đồng ngày V2 theo báo cáo/nhân viên |
| Chốt báo cáo | locked_at là mốc thời gian; khóa bình thường không nhất thiết có event khi đồng hồ qua hạn | Quét báo cáo thực sự đã khóa theo ReportClock, có catch-up |
| Gateway | Có sổ usage/reservation; cấu hình mặc định chung; daily hiện thuộc hard cap 10000 token | Inject cấu hình ngày riêng; policy tường minh theo feature, kiểm thử trước khi đổi giới hạn |
| AI tuần | Có source inventory, quantity observations, cộng số bằng code, chia phần/batch, lease/recovery | Tái dùng quy tắc đúng; giữ đường tuần hiện tại trong giai đoạn đầu |
| Queue | Có work_report_ai với retry_after riêng; default database hiện 90 giây | Tách queue và execution policy ngày, đồng bộ timeout/lease/retry_after |
| Scheduler | Chỉ có recover AI work report, chưa có tự tổng hợp ngày | Bổ sung dispatch/catch-up/recover khi feature được bật |
| Giao diện | /ai/insights đang mở WorkReportInsights; trang AiDailyInsights V1 tồn tại nhưng không được route đó sử dụng | Bổ sung chế độ Ngày trong module Phân tích AI đang dùng; không coi trang V1 là UI mới đã có |

## Luồng đã chọn để phát triển

1. Nhân viên nhập và lưu báo cáo như hiện tại; autosave không phát sinh lượt AI.
2. Sau mốc khóa thực tế, dispatcher quét báo cáo đủ điều kiện. Đề xuất chạy mỗi 5 phút, theo ReportClock và cấu hình lịch làm việc hiện có; không cố định thêm một giờ khóa khác.
3. Server lưu bản nguồn bất biến theo **từng báo cáo/nhân viên/ngày**, gồm danh sách toàn bộ đầu việc, kết quả, kế hoạch, vấn đề và minh chứng chưa xác minh. Cấu hình/prompt/schema/model và hash đi cùng phiên bản nguồn.
4. Worker Luna chuẩn hóa dữ kiện/chỉ số và tạo nhận xét. Kết quả AI không thay thế bản kê đầy đủ lấy trực tiếp từ nguồn.
5. Server kiểm tra cấu trúc, nguồn trích dẫn, số lượng/đơn vị; cộng số bằng code. Lưu kết quả để mở lại mà không gọi AI.
6. Giao diện ngày hiển thị từng người, công việc/kết quả, số liệu có căn cứ, việc đang làm, kế hoạch và vấn đề; sau đó mới đến nhận xét/đề xuất.
7. Giai đoạn kế tiếp: tuần dùng các tài liệu ngày V2 cùng nguồn trích dẫn; lập danh sách công việc tuần từng người trước khi Sol nhận xét và tổng hợp. Tháng và module Tích hợp MXH triển khai sau.

### Nộp bù và chốt tuần

- Nguồn theo report_date (ngày làm việc), không theo created_at. is_makeup và thời gian nộp thật vẫn hiển thị.
- Nộp bù được khóa ngay: dispatcher có thể xử lý sau khi transaction lưu thành công; lần quét tiếp theo vẫn phải nhặt được nếu event/dispatch bị mất.
- Ngày còn trong hạn nộp bù có thể chưa đủ nguồn. Hiển thị số báo cáo được dùng và thời điểm chốt, không gán “đủ mọi nhân viên” chỉ dựa vào danh sách đã nộp.
- Hạn nộp bù hiện tại tối đa 3 ngày, trong tuần Thứ Hai–Thứ Bảy, chốt hết Thứ Bảy theo ReportClock. Không mở lại tuần trước.
- Báo cáo ngày bổ sung tạo tài liệu nguồn mới; snapshot tuần đã tạo giữ nguyên. Khi người dùng tạo phiên bản tuần mới, chốt lại tập nguồn ngày và ghi provenance; không âm thầm chạy lại lượt trả phí cũ.
- Việc xác định “nhân viên chưa nộp” phải dùng chính sách thành viên/lịch làm việc thích hợp; không suy ra từ vắng dữ liệu, không tự kết luận nghỉ hoặc bỏ việc.

## Hợp đồng dữ liệu và số lượng

Dự kiến thêm hai bảng riêng (tên/schema chốt trong PR triển khai):

- `daily_report_ai_documents`: organization/report/employee/date, source/config hash, schema/prompt/model versions, snapshot đã tối thiểu hóa, status, kết quả chuẩn hóa/nhận xét, timestamps và liên kết usage. Unique theo tổ chức + report + source_hash + config_hash; không theo người bấm để tránh nhiều quản lý trả phí lại cùng nguồn hợp lệ.
- `daily_report_ai_units`: document + part key, input bất biến, status, attempt, lease token/deadline, output, error code và usage reference. Unique theo document/part; cập nhật kết quả chỉ khi lease token còn hợp lệ.

Dữ liệu mỗi đầu việc giữ source report_id/item_id/date, trạng thái tự báo cáo, nguyên văn kết quả; phần AI bổ sung:

- Mô tả công việc chuẩn hóa và trích dẫn nguồn; một đầu việc có thể mang nhiều chỉ số.
- Quan sát số lượng: metric, value, unit, basis (phát sinh/lũy kế/chưa rõ), activity (sản xuất/đăng tải/bán/khác), quote và source refs. Phân biệt nền tảng/kênh khi nguồn có nói; không đoán.
- Danh sách cần xác nhận khi đơn vị, phạm vi hoặc ý nghĩa số chưa rõ. Không cộng phần chưa rõ thành tổng chắc chắn.
- Nhận xét dựa trên các dữ kiện đã tổng hợp; đề xuất gắn với nguồn/vấn đề. Không chấm thái độ/năng lực, không tự tạo KPI hay nhiệm vụ giao.

Quy tắc bắt buộc:

- 10 sp + 30 sp phát sinh cùng chỉ số/đơn vị = **40 sp**.
- 2 ảnh + 1 video; 3 ảnh + 2 video = **5 ảnh, 3 video**, không cộng ảnh và video thành một chỉ số sản lượng.
- “Lũy kế 10, hôm sau lũy kế 30” không thành 40; chỉ nêu mốc cuối 30 khi cùng phạm vi được xác định, nếu chưa rõ thì yêu cầu xác nhận.
- “Dự kiến bán 30 sp ngày mai” không cộng vào bán đã đạt. Số lượng việc đang làm không tự chuyển thành số đã hoàn thành.
- “Hoàn thành đăng 2 video” có thể tính 2 lượt/video đăng theo ngữ nghĩa nguồn, không suy ra đã sản xuất 2 video mới. Cùng video đăng TikTok/Facebook/Zalo không tự thành 3 video sản xuất; có thể là các lượt đăng riêng nếu nguồn xác định rõ.
- Không dùng chỉ nội dung giống nhau để kết luận cùng một việc hay trùng kết quả. Chỉ bỏ bản sao chắc chắn theo nguồn, phần còn nghi ngờ phải giữ và đánh dấu tránh cộng tự động.
- URL chỉ là minh chứng nhân viên cung cấp, **chưa xác minh**. Không truy cập link, tải video hoặc khẳng định bài còn tồn tại; dữ liệu MXH/growth thuộc module Tích hợp sau này.
- Số liệu cộng theo người/chỉ số/đơn vị/phạm vi phù hợp. Dùng decimal hoặc số nguyên theo đơn vị để tránh sai số tiền/sản lượng; giữ nguyên tiền tệ, không tự đổi đơn vị.

## Worker, output và chi phí

- Queue bền vững chạy ngoài vòng đời request web; không dùng sync/afterResponse cho provider. Trình duyệt chỉ gửi yêu cầu ngắn rồi poll trạng thái.
- Một báo cáo lớn chia thành phần xác định theo đầu việc, không cắt chuỗi hoặc bỏ đầu việc. Lưu các phần đạt, retry đúng phần lỗi, ghép bằng code khi có thể; không tạo thêm summary trả phí nếu chỉ cần nối dữ kiện đã có.
- Output là cấu hình riêng theo phần. Mốc thử ban đầu **6000 token/phần** là đề xuất để benchmark, không phải trần 3000 ký tự cho toàn báo cáo. Vẫn phải có giới hạn kỹ thuật/chi phí hữu hạn.
- Khi response thiếu do hết output: đánh dấu lỗi cụ thể; chia nhỏ phần hoặc tăng giới hạn trong ngân sách trước lần gọi mới. Không lưu output thiếu thành completed, không âm thầm cắt nhận xét.
- Chính sách đề xuất để tái dùng sau kiểm thử: HTTP timeout tối đa 120s, job timeout 270s, lease 420s, retry_after 480s. Phải kiểm tra mọi tầng retry thực tế để lease/retry_after luôn lớn hơn toàn bộ thời gian job có thể chạy.
- Duplicate delivery, click và scheduler cùng dùng khóa unique/claim. Lease hết hạn sau khi đã gửi provider nhưng chưa biết kết quả chuyển sang outcome_unknown; không tự gửi lại vô hạn. Hiển thị rõ retry có thể phát sinh phí.
- Tái dùng Gateway usage/reservation và idempotency; cấu hình budget riêng theo feature/ngày, không reset phí đã dùng khi retry. Không đổi model/budget của AI tuần hoặc AI tính năng khác.
- Bộ bật đề xuất: DAILY_REPORT_AI_ENABLED=false, DAILY_REPORT_AI_DRIVER=fake; DAILY_REPORT_AI_MODEL=gpt-6-luna; queue, input/output, attempts, giới hạn từng lượt và tháng cấu hình riêng trong `daily_report_ai.php`. Tên ENV còn lại chốt khi có config thật.
- Không sao chép đơn giá cũ vào dự toán. Kiểm tra giá/quyền truy cập tại lúc thử thật; đo input/output/cost theo report và theo ngày trước khi chọn ngân sách.
- Không tự gọi trả phí toàn bộ lịch sử. Auto bắt đầu từ ngày cấu hình bật; catch-up giới hạn rõ, chạy lịch sử theo yêu cầu có phạm vi và dự toán.
- Worker/scheduler phải được kiểm tra vận hành độc lập trên hạ tầng hiện có trước khi cam kết tự chạy cuối ngày. Cấu hình Render và chi phí hạ tầng là bước triển khai riêng; tính năng thủ công/khôi phục dùng cùng queue và khóa.

## Phân quyền và bảo mật

- Tái dùng membership, reports.view và ai.insights.view; kiểm tra lại scope khi tạo, xử lý, đọc cache và mở trích dẫn. Mọi truy vấn/kết quả đều ràng buộc organization.
- Tài liệu ngày chỉ chứa dữ liệu của một báo cáo; chia sẻ cache chỉ khi hợp đồng nguồn và quyền xem tương đương. Không để người chỉ có quyền phòng ban đọc summary của toàn công ty, kể cả qua history hoặc ID đoán được.
- Dispatcher tự động cần danh tính hệ thống được cấp quyền cụ thể theo tổ chức. Không giả mạo OWNER. Gateway hiện yêu cầu requestedByUserId; phải xác định cơ chế danh tính trước khi bật unattended provider.
- Giảm PII: gửi ID nhân viên, không gửi email/điện thoại không cần thiết; map tên trên server khi hiển thị. Nội dung báo cáo/URL luôn là dữ liệu không tin cậy, không phải chỉ dẫn cho model.
- Log chỉ trạng thái, IDs, hash, latency, usage/error codes; không log API key, raw payload hay chi tiết nội dung nhân viên.

## Các PR triển khai tiếp theo

1. **Nguồn và kết quả ngày**: migration bổ sung, snapshot/coverage, schema/validator/fixture/fake provider, số liệu có trích dẫn, API Ngày và UI từng nhân viên. Giữ tính năng tắt mặc định.
2. **Luna và vận hành**: config riêng + Gateway policy, queue/claim/recover/catch-up, retry/output handling, scheduler, kiểm thử restart/timeout; thử Luna có phạm vi trên staging và đo chi phí.
3. **Tuần từ ngày**: adapter dùng tài liệu ngày V2, chốt source revision, kiểm tra coverage và độ đầy đủ; giữ lựa chọn luồng tuần cũ rõ ràng khi ngày chưa đủ. Không fallback âm thầm hoặc gắn nhãn hoàn tất cho kỳ thiếu nguồn.

Migration mới sẽ đánh số theo source/ledger thật tại lúc viết; không gán trước M27 dựa trên giả định. Dùng migration tool hiện có, staging trước production. Các PR này cần kiểm thử trước merge, không viết thẳng vào main.

## Ma trận nghiệm thu

- Fixture tiếng Việt: 40 sp; 5 ảnh/3 video; lũy kế; số thập phân; đơn vị khác nhau; kế hoạch; số trong URL; cùng nội dung nhiều ngày; đăng lại/cross-post; chỉ có kế hoạch; legacy thiếu result.
- Mỗi item nguồn xuất hiện trong bản kê dù AI không nhận xét item đó; mọi nhận xét/chỉ số gắn đúng nguồn. Nguồn cũ vẫn mở được theo quyền hiện tại.
- Nộp bù tạo phần ngày đúng report_date; thứ Bảy sát 00:00 Chủ Nhật; ngày thiếu; phiên bản tuần cũ giữ nguyên snapshot.
- Mất quyền/chuyển phòng/khác tổ chức không đọc được dữ liệu ngoài scope. Chạy tay + lịch + duplicate queue không nhân đôi paid call.
- Worker chết trước/sau provider; stale lease; delivery lại; output incomplete; invalid JSON/evidence; budget thiếu; 429/5xx; retry chỉ phần lỗi và phí cũ giữ nguyên.
- Fixture/fake không gọi API trả phí. Test số liệu, trạng thái và bảo toàn nguồn trước; sau đó browser Ngày và regression form/ngày/tuần, lint/build/backend CI theo phạm vi thay đổi.
- Thử thật staging theo tập nhỏ đã chọn; kiểm tra nội dung/usage/worker độc lập. Production chỉ bật sau nghiệm thu và xác minh hạ tầng.

## Trạng thái của PR chuẩn bị

Chỉ thêm tài liệu thiết kế và tạo nhánh feature. Chưa có schema mới, provider mới, paid call, migration, cấu hình runtime hay deploy từ PR này. Không cần người vận hành chạy lệnh DB cho bước chuẩn bị.
