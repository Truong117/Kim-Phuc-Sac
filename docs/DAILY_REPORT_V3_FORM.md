# Báo cáo ngày V3 — form và lưu dữ liệu

Phạm vi PR #84 bước đầu: giao diện năm mục, API và bản nháp. AI ngày Luna, lịch chạy tự động, adapter tuần/tháng và kết nối MXH chưa được triển khai trong bước này. Không thay đổi model hoặc ngân sách triển khai.

## Hành vi

- Nhập nhanh và chi tiết đều có: (1) Công việc đã hoàn thành; (2) Kết quả đạt được; (3) Công việc đang thực hiện; (4) Kế hoạch ngày mai; (5) Công việc có vấn đề / cần hỗ trợ.
- Kết quả nhập ở từng đầu việc và tự hiển thị tại mục 2, không lưu một bản sao văn bản thứ hai. Vướng mắc có thể gắn với cả việc đang làm; mục 5 tập hợp thêm các vấn đề này. Ghi chú cũ giữ nguyên nhãn, không tự thành vướng mắc.
- Kế hoạch là một trường văn bản tùy chọn tối đa 5.000 ký tự; có thể ghi nhiều dòng và nêu rõ ngày nếu không thực hiện vào ngày mai. Không tự tạo task hoặc ghi nhận thành tích. Báo cáo chỉ có kế hoạch được lưu với 0 đầu việc và trạng thái tổng thể IN_PROGRESS.
- Đường dẫn: tối đa 10 URL HTTP/HTTPS khác nhau mỗi đầu việc, tối đa 2.048 ký tự/link, không kèm tên đăng nhập/mật khẩu. Không truy cập URL hoặc gọi API MXH. Giao diện luôn ghi “Chưa xác minh”.
- Không thêm trường số lượng bắt buộc. Nội dung kết quả vẫn là văn bản; chuẩn hóa/chỉ số AI thuộc bước sau.
- Lưu các đầu việc có ID ổn định trong lúc chỉnh sửa; đổi thứ tự không tạo lại đầu việc đã có. ID không thuộc báo cáo bị từ chối trong transaction.
- Client mới gửi form_version=3 và ID của các đầu việc đã lưu. Trường mới không được gửi trong cập nhật thì giữ nguyên; gửi chuỗi/danh sách rỗng để chủ động xóa.
- Client cũ sửa báo cáo đã có vướng mắc/minh chứng bị trả 409 và yêu cầu tải lại, tránh làm mất hoặc gắn nhầm dữ liệu. Báo cáo cũ chưa có các trường này vẫn sửa bình thường.
- Nháp V2 dùng key riêng, đọc được nháp V1 và giữ bản V1 làm bản sao khi lưu V2. Phiên bản chưa biết không bị xóa/ghi đè. Nội dung chưa nhấn Thêm, kế hoạch, vấn đề, minh chứng đi cùng nháp; bản sao 409/khóa có đủ các trường này.
- AI hiện tại nhận vướng mắc trong phần note của nguồn mới; kế hoạch/minh chứng không được tự cộng vào kết quả hoặc gửi URL để xác minh. Snapshot AI lịch sử không viết lại.

## Migration và thứ tự đưa lên staging

Migration mới (M25 theo nền 24 migration hiện tại):

`backend/database/migrations/2026_11_03_010000_add_daily_report_v3_fields.php`

Bổ sung nullable: daily_reports.next_day_plan (text); daily_report_items.issue (text); daily_report_items.evidence_links (json). Không đổi enum, không backfill hoặc xóa bản ghi cũ.

1. Rà soát đúng SHA và migration mới trong PR. Dùng tài khoản migrator của đúng môi trường và kiểm tra ledger/schema trước khi thực thi; không chạy lại M23/M24.
2. Áp dụng riêng migration này theo quy trình hiện hữu. Không dùng tài khoản runtime để chạy ALTER và không đổi cơ chế bootstrap để bỏ qua kiểm tra.
3. Deploy backend có schema mới trước frontend. Mã form V3 cần schema bổ sung trước khi nhận ghi mới.
4. Nghiệm thu bằng dữ liệu giả trên staging: tạo/sửa, hai chế độ, khôi phục nháp V1/V2, kế hoạch, vấn đề, minh chứng, lỗi lưu và khóa báo cáo.
5. Chỉ sau khi staging đạt mới chuẩn bị phát hành production. Không tự chạy down migration: thao tác đó làm mất dữ liệu mới trong các cột bổ sung; nếu cần lùi ứng dụng phải đánh giá khả năng ghi của client cũ trước.

Manifest bootstrap được nối thêm chính xác tên M25. Các kiểm tra prefix lịch sử M22/M24 vẫn giữ nguyên giới hạn của chúng; fixture AI chấp nhận ledger đã biết 22–25 và kiểm tra cột M25 đúng trạng thái. Chưa bổ sung M25 vào launcher incremental lịch sử M14–M22; không dùng launcher cũ để chạy migration mới.

## Kiểm tra

- Backend: CRUD/ID/reorder, client cũ, kế hoạch không thành thành tích, URL không an toàn/trùng/quá giới hạn, ngày khóa; migration bảo toàn dữ liệu và MySQL DDL compile không kết nối DB; manifest/schema drift.
- Frontend: render thứ tự năm mục trong cả hai chế độ, nhãn minh chứng chưa xác minh, link an toàn, lưu/khôi phục nháp V1/V2 và chống ghi đè phiên bản chưa biết.
- Kịch bản browser: `e2e/daily-report-v3-form.spec.ts`, chỉ dùng môi trường fake cô lập hiện có. Chạy bằng `powershell -NoProfile -ExecutionPolicy Bypass -File e2e/run.ps1 daily-report-v3-form.spec.ts` vào ngày làm việc trong khung giờ mở. Không dùng production .env/API key.
- Kiểm thử browser chưa được ghi nhận PASS trong phiên này; cần chạy kịch bản thật trước nghiệm thu giao diện trên staging.
