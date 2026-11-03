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

- Mỗi phần xử lý gắn với báo cáo đã khóa của một nhân viên/ngày, có s…47008 tokens truncated…a. Nội dung giống nhau được xếp cùng nhóm; từng lần báo cáo vẫn giữ riêng ngày, kết quả và trạng thái. Không mặc định các lần lặp là một nhiệm vụ liên tục.",
      "totals": "Tổng số lượng từ báo cáo",
      "partialTotals": "Số tổng hiện chỉ dùng các phần AI đã đọc thành công; chưa đại diện đầy đủ cho kỳ này.",
      "unconfirmed": "Cần xác nhận cách cộng",
      "cumulative": "Có số lũy kế, kế hoạch hoặc số liệu có thể trùng. Chưa cộng thành tổng để tránh sai lệch.",
      "quantitySources": "Xem số liệu và căn cứ",
      "source": "Báo cáo #{report} · Đầu việc #{item}",
      "result": "Kết quả đã báo cáo",
      "note": "Ghi chú",
      "review": "2. Nhận xét và đề xuất dựa trên công việc đã tổng hợp",
      "status": {
        "COMPLETED": "Nhân viên báo đã hoàn thành",
        "IN_PROGRESS": "Đang thực hiện",
        "BLOCKED": "Đang vướng mắc"
      }
    }
  }
}
