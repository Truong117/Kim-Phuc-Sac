# Giao diện phân tích ngày và kỳ

## Điều hướng

- **Theo ngày** sử dụng nguyên màn hình phân tích kỳ 1 ngày: chọn ngày/phòng ban, tạo hoặc mở bản đã lưu, tải lại, thử lại phần lỗi và tạo phiên bản mới theo quyền hiện có.
- **Theo kỳ / báo cáo tuần** còn lựa chọn 7 và 30 ngày. Chuyển tab giữ ngày kết thúc và phòng ban, mở dữ liệu hiện tại của chế độ mới.
- Cả hai dùng chung bốn tab kết quả: Toàn công ty, Theo nhân viên, Lịch sử phân tích, Báo cáo nguồn. Giữ nguyên bố cục đọc tập trung, nhóm công việc, số liệu, nhận xét, đề xuất và căn cứ.
- Lịch sử phân tích vẫn là lịch sử chung. Mở một bản đã lưu sẽ chuyển về đúng chế độ ngày/kỳ và đúng bộ lọc của bản đó. Đọc lịch sử không tạo yêu cầu AI mới.
- Ngày mặc định là hôm trước theo múi giờ Việt Nam, giống màn hình phân tích theo kỳ hiện tại. Người dùng có thể chọn ngày khác bằng lịch sẵn có.

## Nguồn ngày

Màn hình cũ xử lý bản tổng hợp ngày của từng nhân viên được đưa vào mục thu gọn **Báo cáo nguồn → Xem và xử lý bản tổng hợp ngày làm nguồn**. Chỉ tải chức năng này khi mở mục đó. Giữ các nút tạo bản còn thiếu, thử lại phần lỗi và phân trang.

Các bản nguồn ngày được xử lý theo phạm vi quyền của tài khoản, như trước đây; bộ lọc phòng ban của bản phân tích phía trên không đổi phạm vi của nút “Tổng hợp toàn công ty” trong công cụ nguồn. Số lượng nguồn và nhãn phạm vi vẫn hiển thị tại công cụ trước khi thực hiện. Chọn ngày nguồn trong kỳ đang xem, xử lý xong bấm “Tải lại” ở phần phân tích để kiểm tra độ phủ nguồn mới.

Phân trang/đổi ngày trong công cụ nguồn dùng `source_date` và `source_page`, giữ nguyên ngày, phòng ban và ID bản phân tích phía trên. Đọc hoặc xử lý nguồn không ghi đè kết quả phân tích đã lưu. Cơ chế từ bản ngày sang tuần vẫn tuân theo `WORK_REPORT_AI_USE_DAILY_DOCUMENTS` hiện có.

## Liên kết tương thích

| Liên kết | Kết quả |
| --- | --- |
| `?days=1&end=YYYY-MM-DD` | Theo ngày, dùng API phân tích 1 ngày |
| `?analysis=ID&days=1&end=YYYY-MM-DD` | Theo ngày, mở đúng bản đã lưu |
| `?view=daily&date=YYYY-MM-DD` | Theo ngày, giữ ngày từ liên kết cũ |
| `?view=daily&date=YYYY-MM-DD&page=N` | Theo ngày, mở công cụ nguồn ngày tại trang N; hỗ trợ email cá nhân cũ |
| `?view=daily&date=YYYY-MM-DD&page=N&source=daily` | Liên kết email cá nhân mới, mở công cụ nguồn ngày |
| `?days=7` hoặc `?days=30` | Theo kỳ, giữ nguyên engine/kết quả |
| `?analysis=ID` thiếu hoặc sai kỳ/ngày | Lấy kỳ/ngày/phòng ban từ bản đã lưu, chuẩn hóa URL bằng replace |

Không đổi API tạo/retry/regenerate phân tích, prompt/schema, hàng đợi, lịch tự động, mail tự động, ngân sách hay quyền dữ liệu. Không có migration hoặc ENV mới.

## Kiểm tra

- Kiểm tra điều hướng với link ngày/kỳ, email cũ, ID/ngày không hợp lệ và phiên bản lịch sử.
- Kiểm tra trình duyệt cho bốn tab ngày, lựa chọn 7/30 ngày, Back/Forward, link bản đã lưu, lịch sử khác kỳ, request tạo đúng 1 ngày và công cụ thử lại nguồn.
- Giữ kiểm tra đọc tập trung trên desktop/điện thoại và các test nguồn ngày hiện hữu.
