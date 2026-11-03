# Chuẩn bị tối ưu báo cáo ngày V3 — GPT-6 Luna

Ngày: 2026-11-03. Trạng thái: bước form/API đã triển khai trong PR #84; xem DAILY_REPORT_V3_FORM.md. Các phần AI Luna và xử lý ngày/tuần dưới đây vẫn là kế hoạch. Chưa chạy migration trên môi trường dùng chung hoặc thay đổi cấu hình triển khai.
Nền đã rà soát: main `758c0db4153177045e344cd424088c1d0aeb21ae`; staging `9dafc5fda7f6200a189206574626a34150b15194` (cùng mã ứng dụng).

## Quyết định đã chốt và phạm vi

- Người dùng đã chọn `gpt-6-luna` cho xử lý AI báo cáo ngày.
- Năm mục: Công việc đã hoàn thành; Kết quả đạt được; Công việc đang thực hiện; Kế hoạch ngày mai; Công việc có vấn đề / cần hỗ trợ.
- Kết quả gắn với từng công việc, không bắt nhân viên chép lại cùng nội dung ở nhiều ô.
- Tối ưu báo cáo ngày trước; báo cáo tuần dùng Sol như hiện tại trong giai đoạn này.
- Module Tích hợp MXH, truy cập/xác minh URL và báo cáo tháng thuộc giai đoạn sau.
- Các thiết kế trường, cấu hình, bảng dữ liệu dưới đây là đề xuất kỹ thuật để triển khai, không phải mô tả chức năng đã có.

## Hiện trạng đã kiểm tra

- `DailyReportItem` có content/result/status/note; ba trạng thái COMPLETED, IN_PROGRESS, BLOCKED.
- `ReportItemsRequest` chấp nhận danh sách 1–50 đầu việc; chưa có trường kế hoạch, minh chứng hoặc chỉ số định lượng riêng.
- `NewReport.tsx` có quick/detailed; `useWorkReportDraft` lưu bản nháp cục bộ sau 900 ms, có khôi phục và xử lý xung đột.
- Schema bản nháp hiện là V1. Phải có bộ chuyển đổi, không đơn giản đổi prefix khiến bản nháp cũ biến mất.
- `WorkReportService::update` hiện xóa/tạo lại đầu việc. ID đầu việc không ổn định trong lúc sửa báo cáo; chỉ tạo nguồn AI bất biến sau khóa.
- Giờ khóa do `REPORT_CUTOFF_TIME` quyết định (mặc định trong code 23:00, không kết luận môi trường thật đang dùng giá trị này); múi giờ qua `ReportClock`, lịch làm việc Thứ Hai–Thứ Bảy.
- AI ngày V1 nằm ở `AiDailyReportService`, `DailyReportContextBuilder`, `GenerateAiDailyReport`, `OpenAiDailyReportProvider`.
- V1 là phân tích một ngày theo người yêu cầu, dùng cấu hình chung `kps_ai`; kết quả gồm overview/highlights/attention/recommendations, chưa phải hồ sơ công việc đầy đủ từng nhân viên.
- V1 có job timeout 100 giây / tries=2, output provider 1800 token và kiểm tra độ dài nội dung. Không sao chép nguyên các giới hạn này sang luồng mới.
- V1 không có lịch tự tổng hợp ngày trong `backend/routes/console.php`. Kết quả V1 không được đổi nhãn thành dữ liệu chuẩn hóa V3.

## Bước 1 — Form và dữ liệu ngày

### Cách nhập và hiển thị

1. Công việc đã hoàn thành: giữ đầu việc COMPLETED, nhập nhanh hoặc chi tiết.
2. Kết quả đạt được: nằm trong từng đầu việc; có thể có kết quả bước đầu ở việc đang làm. Khối kết quả chung hiển thị lại từ đầu việc, không lưu một bản văn sao chép thứ hai.
3. Công việc đang thực hiện: giữ IN_PROGRESS, thể hiện phần đã làm và phần còn lại.
4. Kế hoạch ngày mai: danh sách riêng, thể hiện rõ ngày dự kiến; không tự coi là nhiệm vụ được giao hay công việc đã hoàn thành. Ngày nghỉ phải hiển thị rõ thay vì âm thầm đổi ngày.
5. Công việc có vấn đề: ghi vướng mắc/ảnh hưởng/hỗ trợ cần thiết tại đầu việc; có thể xảy ra ở việc đang làm. BLOCKED chỉ dùng khi thực sự bị chặn, không ép mọi vướng mắc thành BLOCKED.

Không bắt điền đủ năm mục. Không tạo đầu việc giả “Không có” để vượt qua validation. Thông tin định lượng và minh chứng là tùy chọn; không buộc mọi loại công việc phải có số lượng hoặc URL.

### Dữ liệu đề xuất và tương thích

- Bổ sung kế hoạch có cấu trúc ở cấp báo cáo.
- Bổ sung danh sách minh chứng ở cấp đầu việc: URL, nền tảng nếu xác định được, trạng thái mặc định “Chưa xác minh”.
- Bổ sung thông tin vấn đề riêng, không tự biến mọi ghi chú cũ thành một vướng mắc.
- Cho phép chỉ số kết quả tùy chọn: tên chỉ số, giá trị, đơn vị, loại số liệu (phát sinh/lũy kế/chưa rõ). Một đầu việc có thể có nhiều chỉ số, ví dụ ảnh và video.
- Chốt schema/validation chính xác trong bước triển khai; ưu tiên migration bổ sung nullable/giá trị mặc định trống, không đổi enum WorkStatus, không viết lại dữ liệu lịch sử.
- API phiên bản cũ bỏ qua trường mới phải giữ dữ liệu mới đã có; phân biệt “không gửi trường” và “gửi danh sách rỗng để xóa”. Cập nhật toàn bộ báo cáo trong một transaction.
- Nếu V3 hỗ trợ chỉ có kế hoạch/vấn đề, mở validation có điều kiện theo ít nhất một nội dung có ý nghĩa; không tính kế hoạch vào số công việc thực hiện.
- ID đầu việc có thể đổi trong bản nháp: minh chứng/metrics/issue phải đi theo payload của chính đầu việc; không tạo quan hệ tới ID chưa ổn định hoặc làm mất dữ liệu khi xóa/tạo lại.
- Báo cáo cũ hiển thị các mục mới là chưa cung cấp; không yêu cầu nhập bù và không suy ra rằng nhân viên không có kế hoạch.
- Link chỉ được kiểm tra định dạng/hiển thị an toàn ở bước này. Không gọi URL, không tải video, không gắn nhãn “Đã xác minh”.
- Bảo toàn quick/detailed, nội dung chưa nhấn Thêm, autosave, khôi phục, 409, lỗi mạng và bản nháp bị khóa.
- Nâng schema bản nháp có bộ đọc/chuyển V1; chỉ thay bản cũ sau khi lưu V3 thành công. Không tự ghi đè dữ liệu server hoặc bỏ bản nháp khi gặp phiên bản chưa biết.

### Nhóm mã dự kiến

- Frontend: `src/pages/Reports/NewReport.tsx`, `src/components/reports/`, `src/types/reports.ts`, `src/services/reportService.ts`, `workReportDraftStorage.ts`, `useWorkReportDraft.ts`, detail/history và từ điển tiếng Việt thực tế.
- Backend: model/resource, `ReportItemsRequest`, `ReportController`, `WorkReportService`, migration bổ sung.
- Tuân theo AGENTS.md; dùng primitive và i18n hiện có, không thêm package.
- Soát cả nguồn cho AI ngày/tuần khi thêm trường: kế hoạch/vướng mắc phải giữ đúng loại; không làm thay đổi nội dung nguồn đã chốt của lượt AI cũ.

## Bước 2 — Chuẩn hóa AI ngày bằng Luna

### Hợp đồng kết quả

- Mỗi phần xử lý gắn với báo cáo đã khóa của một nhân viên/ngày, có source hash, cấu hình/model/prompt/schema và thời điểm chốt.
- Lưu đầy đủ năm nhóm, nguồn report/item/date cho từng dữ kiện, các vấn đề chưa rõ và số liệu cần xác nhận.
- Server giữ bản kê công việc đầy đủ; AI chuẩn hóa/nhận xét, không được bỏ mất đầu việc chỉ vì không đưa vào văn bản nhận xét.
- Kế hoạch là dự kiến; kết quả tự báo cáo chưa đồng nghĩa nghiệm thu. Không tự chấm thái độ, năng lực hoặc đạt KPI.
- Số lượng trích từ văn bản chỉ là quan sát có căn cứ; hệ thống kiểm tra nguồn và cộng bằng code. Không cộng trùng, không cộng số lũy kế hoặc kế hoạch vào thành tích.
- Không ngầm chạy Sol khi Luna lỗi; nếu bổ sung cơ chế chuyển model phải có cấu hình, giới hạn chi phí và provenance riêng.

### Cấu hình đề xuất, tách khỏi AI tuần

Tạo namespace cấu hình riêng cho luồng ngày mới. Tên ENV dự kiến:
- `DAILY_REPORT_AI_ENABLED`
- `DAILY_REPORT_AI_DRIVER` (mặc định fake trong phát triển/kiểm thử)
- `DAILY_REPORT_AI_MODEL=gpt-6-luna`
- `DAILY_REPORT_AI_QUEUE_CONNECTION`
- `DAILY_REPORT_AI_OUTPUT_TOKENS` (đề xuất khởi điểm 6000/phần; kiểm chứng bằng fixture trước khi chốt)
- `DAILY_REPORT_AI_MAX_RUN_BUDGET_USD`
- `DAILY_REPORT_AI_MONTHLY_BUDGET_USD`
- `DAILY_REPORT_AI_INPUT_USD_PER_MILLION_TOKENS`
- `DAILY_REPORT_AI_OUTPUT_USD_PER_MILLION_TOKENS`

Không đổi `KPS_AI_MODEL` chung để chuyển riêng báo cáo ngày và không đổi `WORK_REPORT_AI_MODEL`. Dùng cùng cơ chế giữ bí mật API key và sổ usage hiện có; không reset ngân sách khi retry. Kiểm tra lại đơn giá model tại thời điểm triển khai, không lấy cấu hình ước tính của model cũ làm hóa đơn thực tế.

### Chốt nguồn, hàng đợi và quyền

- Chỉ tạo phần xử lý sau mốc khóa thật từ ReportClock; không gọi AI sau mỗi lần autosave.
- Phần xử lý có khóa chống chạy trùng theo tổ chức/báo cáo/phiên bản nguồn/cấu hình; kết quả bất biến, nguồn hoặc cấu hình đổi thì tạo phiên bản mới.
- Chuẩn bị nơi lưu source snapshot, input/config hash, kết quả, trạng thái, attempt/lease và provenance; migration cho luồng này tách khỏi migration form.
- Job ngắn theo từng nhân viên, có lease, retry phần lỗi và phục hồi job treo; kết quả gọi provider không rõ phải hiển thị rõ và không tự trả phí lặp vô hạn.
- Hợp nhất việc chạy theo yêu cầu và chạy cuối ngày qua cùng hàng đợi/khóa, tránh hai đường tạo trùng.
- Nếu tự chạy toàn tổ chức: cần danh tính hệ thống có quyền được xác định rõ; không giả lập tài khoản OWNER và không tái sử dụng kết quả tổng tổ chức cho người chỉ được xem phòng ban.
- Kiểm tra lại RBAC khi xử lý và khi đọc cache, kể cả lịch sử; minimization/redaction tương đương luồng hiện tại.
- Tự động cuối ngày cần scheduler và worker hoạt động thực sự. Render Free có thể ngủ; không cam kết tự chạy đúng giờ khi chưa có cấu hình vận hành đáp ứng. Giữ nút chạy thủ công/khôi phục ngày thiếu qua cùng cơ chế chống trùng.

## Ranh giới với bước tối ưu tuần sau đó

- Trong giai đoạn này giữ model và luồng tuần hiện tại.
- Sau nghiệm thu ngày, bổ sung adapter để tuần dùng dữ liệu ngày có cấu trúc và nguồn gốc; không chỉ nối các đoạn overview.
- Chỉ tái sử dụng phần ngày cùng hợp đồng/model/provenance được hỗ trợ; không đổi nhãn lịch sử V1.
- Ngày thiếu hoặc lỗi phải hiển thị phạm vi còn thiếu; không ghi “hoàn tất” cho cả kỳ nếu nguồn chưa đủ.
- Báo cáo tháng và API MXH sẽ có thiết kế/triển khai riêng.

## Tiêu chí nghiệm thu trước khi đưa vào staging

- Tạo/sửa/xem đủ năm mục; báo cáo cũ đọc được và không mất dữ liệu khi client cũ lưu.
- Quick/detailed và nháp V1/V3 không mất nội dung; reload, nhiều tab, đổi ngày, mất mạng, 409 và khóa báo cáo.
- Gửi thiếu dữ liệu thực tế nhưng chỉ có kế hoạch không tạo thành tích giả; người không phải tác giả không sửa được báo cáo.
- Một việc có ảnh + video giữ hai chỉ số riêng; 10+30=40 cho số phát sinh, số lũy kế/không rõ không cộng bừa.
- Hai URL cùng bài không tự trở thành hai video; hai video trên ba kênh không tự thành sáu nội dung sản xuất. Chưa tích hợp MXH luôn là chưa xác minh.
- Khóa trùng giữa chạy tay và scheduler; retry chỉ phần lỗi; timeout/lease, quyền bị thu hồi, source revision và không log secret/payload.
- Hợp đồng Luna được kiểm thử bằng fixture tiếng Việt đa dạng, so sánh với nguồn; kiểm tra không bỏ đầu việc, trộn kế hoạch, bịa số lượng hay nguồn.
- Kiểm thử fake không gọi API trả phí. Thử Luna thật trên staging là bước nghiệm thu riêng sau cấu hình.
- Kiểm tra ảnh hưởng nguồn và thống kê tuần, form kế hoạch tuần và các AI feature dùng Gateway chung.
- Test frontend/backend phù hợp, lint/build/container khi mã tương ứng thay đổi; browser thực tế cho nhập/nháp/detail.
- Kế hoạch này chỉ có tài liệu, không chứng minh các tiêu chí trên đã đạt.

## Thứ tự triển khai

1. Form/schema/API và chuyển bản nháp; PR vào staging, nghiệm thu trước.
2. Hợp đồng nguồn/ngày + Luna cấu hình riêng + queue/recovery/scheduler; nghiệm thu dữ liệu giả rồi staging.
3. Adapter báo cáo tuần sử dụng kết quả ngày có nguồn, nghiệm thu đầy đủ trước production.
4. Sau đó mới phát triển module Tích hợp và báo cáo tháng.

Không chạy migration, gọi model trả phí hoặc deploy từ PR chuẩn bị này. Migration thực tế phải lấy thứ tự theo ledger/schema tại thời điểm triển khai; không đặt số M mới dựa trên giả định.

## Tài liệu model đã tham khảo

- https://developers.openai.com/api/docs/models/gpt-6-luna
- https://developers.openai.com/api/docs/guides/model-selection
- https://developers.openai.com/api/docs/pricing

Giá Standard tham khảo ngày 2026-11-03: Luna 0,10 USD/triệu token đầu vào và 0,50 USD/triệu token đầu ra. Giá, quyền truy cập và usage thực tế cần kiểm chứng tại thời điểm bật provider.
