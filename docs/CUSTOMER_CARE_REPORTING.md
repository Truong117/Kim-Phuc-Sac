# Báo cáo CSKH theo từng khách

## Phạm vi

Form mới chỉ dành cho đơn vị **khách hàng**, không suy đoán theo tiêu đề, phòng ban hoặc nội dung do AI đọc. Công việc video/ảnh/bài viết/sản phẩm và đơn vị lượt chăm sóc giữ form và cách đếm hiện hành. Chỉ tiêu 80 khách trong tháng phải được cấu hình bằng đơn vị khách hàng và ngày bắt đầu/hạn của kỳ tương ứng.

Nhập số khách (1–20) rồi cập nhật danh sách để tạo các dòng. Mỗi dòng chọn hồ sơ có sẵn hoặc thêm khách tại chỗ; tên và mã khách không phải cùng một trường. Hồ sơ mới có tên, kênh liên hệ và số điện thoại **hoặc** link hồ sơ, thông tin liên hệ được phép bỏ trống. Mã nội bộ do máy chủ cấp. Một khách có 1–10 việc hỗ trợ, kết quả riêng, tối đa 3 link và 3 tệp minh chứng. Có thể điền nội dung chung rồi sửa riêng. Giảm số dòng đã có dữ liệu cần xác nhận.

**Áp dụng vào báo cáo** chỉ cập nhật bản xem trước. **Lưu báo cáo ngày** mới tạo hồ sơ khách, liên hệ, lịch sử chăm sóc và tiến độ trong cùng transaction. Phần công việc nhập thủ công và kế hoạch ngày mai giữ nguyên. Không có lần gọi AI mới cho tính năng này. Nội dung hoạt động được máy chủ đưa vào kết quả báo cáo, không đưa số điện thoại/link hồ sơ vào đoạn văn gửi AI.

## Định danh, số lượng và phân quyền

- Theo từng công việc/kỳ: một `care_customer_id` có tối đa một bản ghi tiến độ. Hai khách A/B được chăm sóc ngày 11 và ngày 14 vẫn là 2/80 khách (2,5%), kèm 4 lượt khách xuất hiện trong lịch sử ngày. Thêm nhiều việc hoặc kênh cho cùng khách không tạo thêm khách. Các hoạt động và số khách trong ngày tách biệt với số khách mới cho chỉ tiêu.
- Sang công việc của kỳ mới, có thể dùng lại hồ sơ cũ và tính một lần cho mục tiêu kỳ mới. Báo cáo bù giữ quy tắc hiện hành: không tự thay đổi tiến độ hiện tại; dùng bước đối soát báo cáo cũ để ghi nhận lịch sử vào mục tiêu.
- Máy chủ tự dựng outputs từ hồ sơ đã chọn, không tin số lượng hay mã do client tự gửi. Tên giống nhau không tự gộp. Số điện thoại Việt Nam dạng `0…`, `84…`, `+84…` được chuẩn hóa; link hồ sơ loại thông tin theo dõi và chuẩn hóa alias Facebook. Định dạng hợp lệ không chứng minh đó là danh tính thật hoặc đã chăm sóc.
- Trùng số điện thoại/link có gợi ý chọn hồ sơ đang được phép xem; tạo mới với định danh đã tồn tại bị từ chối, không ghi một phần. Nếu hồ sơ ở ngoài phạm vi, không tiết lộ tên/liên hệ; cần quản lý đối chiếu quyền. Chưa có công cụ gộp hai hồ sơ không có thông tin liên hệ.
- Nhân viên dùng khách do mình tạo hoặc khách đã gắn với công việc trong phạm vi quyền xem; directory vẫn yêu cầu công việc đang được giao và quyền cập nhật. Không dùng dữ liệu khách Spa preview làm nguồn CRM thật.
- Dữ liệu mã khách nhập tay từ M40 không tự chuyển hay gộp. Trong form mới, chọn rõ mã đối soát cũ tương ứng để gắn hồ sơ, giữ nguyên mã, ngày ghi nhận đầu tiên và số lượng đã có. Không suy đoán hai dòng trùng tên là cùng người. Công cụ đối soát cũ vẫn hoạt động.

## Minh chứng và lưu trữ

Link/ảnh/tệp là tùy chọn với CSKH; không bắt nhân viên sao chép link hội thoại cho từng lần hỗ trợ. Đây vẫn là số liệu nhân viên tự báo cáo, không có tự động xác minh nội dung chat.

Tệp riêng tư JPG/PNG/WebP/PDF, tối đa 1 MiB/tệp, 3 tệp/khách và 10 MiB/người/ngày theo múi giờ báo cáo. Lưu base64 trong cột `mediumText` của DB để không mất khi Render Free redeploy/sleep; không ghi vào filesystem tạm. Tệp này làm tăng dung lượng DB; tệp lớn dùng link. Endpoint tải xuống kiểm tra quyền xem công việc, tác giả tệp hoặc quyền xem báo cáo chứa tệp; trả attachment/octet-stream, nosniff, no-store. Không có URL công khai.

Upload tệp xảy ra trước khi lưu báo cáo để có ID minh chứng. Tệp chưa gắn báo cáo hết hiệu lực sau 24 giờ, được dọn khi cùng người dùng upload lần sau nếu quá 48 giờ. Tệp đã gắn không tự xóa. Hủy form không tạo hồ sơ khách hay tăng tiến độ; tệp tạm có thể còn cho đến bước dọn. Chưa có công cụ quản trị dung lượng/gộp khách trong phạm vi PR này.

## Triển khai và nghiệm thu

Cần **M41 `2026_11_13_020000_create_customer_care_reporting`** sau M39/M40, trước khi dùng form mới. Không thêm ENV, không đổi prompt/contract của các lượt phân tích đã lưu, không chạy migration tự động. Rollback migration sẽ từ chối khi có dữ liệu CSKH; chỉ rollback code nếu đã phát sinh dữ liệu.

1. Trên staging, tạo công việc 80 khách/tháng và công việc 5 video cho nhân viên thử.
2. CSKH: nhập 2 khách, ghi hai việc khác nhau, dùng số điện thoại cho một khách và link hồ sơ cho khách còn lại. Áp dụng rồi lưu. Kiểm tra 2/80 và nội dung báo cáo.
3. Ngày sau chọn lại hai hồ sơ, ghi việc khác: tiến độ vẫn 2/80, lịch sử đủ ngày. Chọn khách trùng hai dòng phải báo lỗi. Thêm việc vào cùng dòng không tăng khách.
4. Tìm lại bằng +84 thay vì số 0 ban đầu, thử tạo trùng liên hệ. Không phát sinh hồ sơ/tiến độ trùng. Khách trùng tên nhưng định danh khác không bị gộp.
5. Thử giảm số dòng có dữ liệu, hủy giảm; thử tải lại trang khi còn bản nháp. Thử lưu với revision cũ: nội dung được giữ để sửa.
6. Kiểm tra tệp hợp lệ, quá 1 MiB, định dạng không hỗ trợ và tải bằng người không có quyền. Tệp đã lưu vẫn đọc được sau deploy.
7. Video: hoàn thiện ABC/DEF là 2 video; báo lại cùng sản phẩm không tăng thêm. Không xuất hiện form khách hàng.
8. Kiểm tra liên kết mã đối soát cũ: không tăng số lượng lần nữa, không đổi báo cáo khóa hay bản AI đã lưu.
