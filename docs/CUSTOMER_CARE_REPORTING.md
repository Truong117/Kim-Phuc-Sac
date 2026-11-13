# Báo cáo CSKH theo đầu việc và nhiều nội dung hỗ trợ

## Phạm vi

Form mới chỉ dành cho đơn vị **khách hàng**, không suy đoán theo tiêu đề, phòng ban hoặc nội dung do AI đọc. Công việc video/ảnh/bài viết/sản phẩm và đơn vị lượt chăm sóc giữ form và cách đếm hiện hành. Chỉ tiêu 80 khách trong tháng phải được cấu hình bằng đơn vị khách hàng và ngày bắt đầu/hạn của kỳ tương ứng.

Mỗi cập nhật có 1–20 đầu việc báo cáo độc lập. Mỗi đầu việc chọn 1–10 nội dung hỗ trợ bằng ô chọn nhiều, chọn 1–20 khách, nhập kết quả chung (tối đa 1.000 ký tự), tối đa 3 link và 3 tệp minh chứng. Có ghi chú riêng cho từng khách, liên kết mã đối soát cũ và bổ sung liên hệ khi cần. Toàn bộ cập nhật tối đa 50 khách duy nhất; kết quả tổng hợp vẫn giới hạn 5.000 ký tự như báo cáo hiện hành.

Thêm đầu việc không tự gán nội dung/khách của đầu việc khác. Một nội dung có thể được chọn ở nhiều đầu việc. Khách trùng giữa các đầu việc chỉ tăng một lần vào tiến độ. Ví dụ đầu việc 1 chọn Hướng dẫn chăm sóc da + Theo dõi, khách A/B; đầu việc 2 chọn Tư vấn, khách B/C: có 2 đầu việc và 3 khách duy nhất. Giữ nguyên từng kết quả, ghi chú và minh chứng theo đầu việc.

Giao diện dùng header nâu/be, hai cột nội dung hỗ trợ và khách hàng trên máy tính; trên điện thoại xếp thành một cột. Kết quả chung nằm dưới hai cột, ghi chú riêng và minh chứng thu gọn. Có thể thu gọn từng công việc mà vẫn giữ ô đang nhập, kể cả form tạo khách chưa hoàn tất. Khi áp dụng, công việc có dữ liệu không hợp lệ tự mở và nhận focus. Đây chỉ là thay đổi giao diện, không bổ sung migration, ENV hay thay đổi cấu trúc dữ liệu của M42.

Nội dung hỗ trợ có danh mục dùng chung trong tổ chức; tìm/chọn nhiều hoặc tạo ngay trong ô chọn. Nội dung mới chỉ được lưu khi lưu báo cáo, chuẩn hóa Unicode/khoảng trắng/hoa thường để tái sử dụng tên đã có. Không gộp hai đầu việc vì chọn cùng nội dung. GET danh mục chỉ đọc, có phân trang; không tạo dữ liệu qua GET. Chỉ nhân viên có quyền cập nhật công việc đang được giao được truy cập.

Trong mỗi đầu việc, chọn khách đã có hoặc thêm khách tại chỗ; hồ sơ mới gồm tên, nguồn/kênh liên hệ (danh sách chọn rõ ràng, có Khác), số điện thoại **hoặc** link hồ sơ không bắt buộc. Mã nội bộ do máy chủ cấp. Hồ sơ mới trong bản nháp có thể dùng lại ở đầu việc khác bằng cùng mã yêu cầu, chỉ tạo một hồ sơ khi lưu. Giảm/bỏ đầu việc đã nhập cần xác nhận.

**Áp dụng vào báo cáo** chỉ cập nhật bản xem trước. **Lưu báo cáo ngày** mới tạo hồ sơ khách, liên hệ, lịch sử chăm sóc và tiến độ trong cùng transaction. Phần công việc nhập thủ công và kế hoạch ngày mai giữ nguyên. Không có lần gọi AI mới cho tính năng này. Nội dung hoạt động được máy chủ đưa vào kết quả báo cáo, không đưa số điện thoại/link hồ sơ vào đoạn văn gửi AI.

## Định danh, số lượng và phân quyền

- Theo từng công việc/kỳ: một `care_customer_id` có tối đa một bản ghi tiến độ. Hai khách A/B được chăm sóc ngày 11 và ngày 14 vẫn là 2/80 khách (2,5%), kèm 4 lượt khách xuất hiện trong lịch sử ngày. Thêm nhiều việc hoặc kênh cho cùng khách không tạo thêm khách. Các hoạt động và số khách trong ngày tách biệt với số khách mới cho chỉ tiêu.
- Sang công việc của kỳ mới, có thể dùng lại hồ sơ cũ và tính một lần cho mục tiêu kỳ mới. Báo cáo bù giữ quy tắc hiện hành: không tự thay đổi tiến độ hiện tại; dùng bước đối soát báo cáo cũ để ghi nhận lịch sử vào mục tiêu.
- Máy chủ tự dựng outputs từ hồ sơ đã chọn, không tin số lượng hay mã do client tự gửi. Tên giống nhau không tự gộp. Số điện thoại Việt Nam dạng `0…`, `84…`, `+84…` được chuẩn hóa; link hồ sơ loại thông tin theo dõi và chuẩn hóa alias Facebook. Định dạng hợp lệ không chứng minh đó là danh tính thật hoặc đã chăm sóc.
- Trùng số điện thoại/link có gợi ý chọn hồ sơ đang được phép xem; tạo mới với định danh đã tồn tại bị từ chối, không ghi một phần. Nếu hồ sơ ở ngoài phạm vi, không tiết lộ tên/liên hệ; cần quản lý đối chiếu quyền. Chưa có công cụ gộp hai hồ sơ không có thông tin liên hệ.
- Nhân viên dùng khách do mình tạo hoặc khách đã gắn với công việc trong phạm vi quyền xem; directory vẫn yêu cầu công việc đang được giao và quyền cập nhật. Không dùng dữ liệu khách Spa preview làm nguồn CRM thật.
- Dữ liệu mã khách nhập tay từ M40 không tự chuyển hay gộp. Trong form mới, chọn rõ mã đối soát cũ tương ứng để gắn hồ sơ, giữ nguyên mã, ngày ghi nhận đầu tiên và số lượng đã có. Không suy đoán hai dòng trùng tên là cùng người. Công cụ đối soát cũ vẫn hoạt động.

## Minh chứng và lưu trữ

Link/ảnh/tệp chung cho đầu việc là tùy chọn với CSKH; không bắt nhân viên sao chép link hội thoại cho từng lần hỗ trợ. Đây vẫn là số liệu nhân viên tự báo cáo, không có tự động xác minh nội dung chat.

Tệp riêng tư JPG/PNG/WebP/PDF, tối đa 1 MiB/tệp, 3 tệp/đầu việc và 10 MiB/người/ngày theo múi giờ báo cáo. Lưu base64 trong cột `mediumText` của DB để không mất khi Render Free redeploy/sleep; không ghi vào filesystem tạm. Tệp này làm tăng dung lượng DB; tệp lớn dùng link. Endpoint tải xuống kiểm tra quyền xem công việc, tác giả tệp hoặc quyền xem báo cáo chứa tệp; trả attachment/octet-stream, nosniff, no-store. Không có URL công khai.

Upload tệp xảy ra trước khi lưu báo cáo để có ID minh chứng. Tệp chưa gắn báo cáo hết hiệu lực sau 24 giờ, được dọn khi cùng người dùng upload lần sau nếu quá 48 giờ. Tệp đã gắn không tự xóa. Hủy form không tạo hồ sơ khách hay tăng tiến độ; tệp tạm có thể còn cho đến bước dọn. Chưa có công cụ quản trị dung lượng/gộp khách trong phạm vi PR này.

## Triển khai và nghiệm thu

Cần M41 `2026_11_13_020000_create_customer_care_reporting` sau M39/M40, và **M42 `2026_11_13_030000_create_care_service_catalog`** trước khi dùng form đầu việc mới. Không sửa migration M41 đã áp dụng. M42 chỉ bổ sung danh mục; ranh giới đầu việc lưu trong JSON đầu vào, đồng thời tổng hợp mỗi khách một bản ghi trong ledger M41. Không thêm ENV, không đổi prompt/contract của các lượt phân tích đã lưu, không chạy migration tự động. Rollback migration sẽ từ chối khi có dữ liệu CSKH; chỉ rollback code nếu đã phát sinh dữ liệu.

Bản lưu M41 theo từng khách vẫn đọc/đọc minh chứng được, API vẫn nhận dạng cũ. Khi mở chỉnh sửa, form chuyển từng bản ghi cũ thành đầu việc tương ứng, giữ kết quả từng nội dung và minh chứng. Chỉ chuyển sang cấu trúc mới khi người dùng áp dụng/lưu. Không sửa báo cáo đã khóa hay AI đã lưu. Sau khi đã lưu cấu trúc đầu việc mới, client cũ không được bỏ hoặc hạ cấp dữ liệu đầu việc.

1. Trên staging, tạo công việc 80 khách/tháng và công việc 5 video cho nhân viên thử.
2. CSKH: tạo 2 đầu việc, đầu việc 1 chọn nhiều nội dung và khách A/B, đầu việc 2 chọn khách B/C. Lưu và kiểm tra đúng 3 khách, 2 đầu việc độc lập, bản ghi hoạt động của B giữ cả hai đầu việc. Thử tạo nội dung mới rồi chọn lại ở đầu việc khác; không tạo trùng tên danh mục.
3. Ngày sau chọn lại A/B/C, ghi việc khác: tiến độ giữ nguyên, lịch sử đủ ngày. Trong một đầu việc không chọn trùng khách/nội dung; giữa các đầu việc được phép dùng lại. Thêm nội dung hỗ trợ không tăng số khách.
4. Tìm lại bằng +84 thay vì số 0 ban đầu, thử tạo trùng liên hệ. Không phát sinh hồ sơ/tiến độ trùng. Khách trùng tên nhưng định danh khác không bị gộp.
5. Thử bỏ đầu việc có dữ liệu, hủy bỏ; thử tải lại trang khi còn bản nháp. Thử lưu với revision cũ: nội dung được giữ để sửa.
6. Kiểm tra tệp hợp lệ, quá 1 MiB, định dạng không hỗ trợ và tải bằng người không có quyền. Tệp đã lưu vẫn đọc được sau deploy.
7. Video: hoàn thiện ABC/DEF là 2 video; báo lại cùng sản phẩm không tăng thêm. Không xuất hiện form khách hàng.
8. Kiểm tra liên kết mã đối soát cũ: không tăng số lượng lần nữa, không đổi báo cáo khóa hay bản AI đã lưu.
