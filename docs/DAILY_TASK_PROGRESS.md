# Cập nhật công việc được giao trong báo cáo ngày

## Hành vi

- Màn báo cáo ngày/báo cáo bù hiển thị công việc được giao cho chính người đang đăng nhập, trong phạm vi quyền hiện tại. Công việc từ kế hoạch tuần đã xuất bản dùng cùng nguồn `tasks` với công việc giao trực tiếp.
- `Cập nhật tiến độ` mở ô nhập ngay bên dưới công việc. `Áp dụng vào báo cáo` thêm một bản xem trước có liên kết nguồn vào đúng nhóm báo cáo; phần nhập tay giữ nguyên. Sửa cập nhật thay thế bản xem trước đó, không thêm bản trùng.
- Chỉ nút lưu báo cáo ngày mới ghi báo cáo, trạng thái công việc, sản phẩm và lịch sử trong cùng giao dịch. Cập nhật chưa áp dụng phải áp dụng hoặc hủy trước khi lưu; bản nháp cục bộ giữ cả nội dung inline chưa áp dụng.
- Nhóm `Hoàn thành phần việc hôm nay` độc lập với trạng thái toàn bộ công việc. Mặc định trạng thái toàn bộ là `Giữ nguyên`; cần chọn rõ để chuyển toàn bộ công việc sang hoàn thành.
- Mục tiêu số lượng/đơn vị là tùy chọn khi giao hoặc sửa công việc. Thanh tiến độ màu nâu tính theo sản phẩm đã hoàn thiện / mục tiêu. Không có mục tiêu thì không hiển thị phần trăm. Vượt mục tiêu vẫn giữ số thực tế, thanh giới hạn 100%. Đây là tự báo cáo, chưa phải nghiệm thu.

## Định danh và số lượng

Mỗi sản phẩm có ID ổn định trong công việc. Tên được chuẩn hóa để hạn chế tạo trùng; chọn sản phẩm có sẵn để chỉnh sửa hoặc tiếp tục công việc. Sản phẩm được tính mới đúng lần hoàn thiện đầu tiên, kể cả báo cáo lại vào ngày sau. Sửa tên theo ID không tạo sản phẩm mới. Bỏ một dòng khỏi bản báo cáo không xóa sản phẩm hay hoàn tác lịch sử công việc đã ghi nhận.

Ví dụ mục tiêu 5 video: hôm nay hoàn thiện ABC, DEF => 2/5 (40%); ngày sau báo lại ABC, DEF => không thêm 2 video. Hoàn thiện thêm GHI => 3/5 (60%). Chỉnh sửa lại ABC sử dụng ID đã có. Nếu bỏ dấu hoàn thiện, số đang hoàn thiện giảm nhưng hoàn thiện lại không được tính là một sản phẩm mới nữa.

Gợi ý tên video đọc danh sách được liệt kê rõ trong nội dung, ví dụ `Đã hoàn thành video “ABC” và video “DEF”`. Không gọi AI riêng. Người dùng kiểm tra danh sách trước khi áp dụng. Các câu có kế hoạch, phủ định, đang làm, lũy kế hoặc nhắc lại không tự đếm. Có thể thêm sản phẩm thủ công khi tên không rõ.

Kết quả báo cáo lưu thêm đoạn số lượng do hệ thống tính, gắn với sản phẩm và đầu việc. Nguồn AI ngày/tuần giữ đoạn này làm trích dẫn nguyên văn và dùng số tăng thêm đã tính; số AI đoán không ghi đè dữ liệu có định danh. Báo cáo nhập tay không liên kết vẫn nhận diện danh sách video rõ; tên trùng qua nhiều ngày được đưa về cần làm rõ, không tự cộng chắc chắn. Không suy đoán hai tên gần giống là một sản phẩm.

Cấu trúc tổng hợp, nhận xét, đề xuất và giao diện đọc AI hiện tại giữ nguyên. Bản phân tích đã lưu không bị tự viết lại. Muốn cập nhật kết quả cũ phải tạo phân tích mới theo thao tác có sẵn.

## Báo cáo bù, xung đột và quyền

Báo cáo bù chỉ giữ lịch sử ngày cũ và ảnh chụp công việc; không đổi trạng thái/số lượng hiện tại. Số lượng lịch sử không xác định được là mới được đánh dấu cần làm rõ khi cộng tổng.

Lưu kiểm tra lại người được giao, tổ chức, quyền và revision dưới khóa giao dịch. Khi công việc đổi trong lúc nhập, lưu bị từ chối với hướng dẫn tải lại công việc, kiểm tra rồi áp dụng lại; nội dung nhập giữ nguyên. Một báo cáo chỉ có một liên kết cho mỗi công việc. ID sản phẩm phải thuộc đúng công việc/đơn vị, không được dùng hai lần trong một cập nhật.

Ảnh chụp tên/yêu cầu công việc không thay đổi khi công việc gốc đổi tên hoặc chuyển giao. Lịch sử ở chi tiết công việc chỉ trả nguồn báo cáo người xem được phép đọc.

## Triển khai

Cần migration `2026_11_12_010000_add_daily_task_progress` (M39 trên baseline hiện tại). Không thêm biến môi trường, queue hoặc lượt gọi AI riêng.

1. Merge PR vào staging sau khi kiểm tra; deploy backend và chạy migration bằng quy trình migration hiện có của dự án; deploy frontend tương ứng.
2. Nghiệm thu với công việc mục tiêu 5 video, cập nhật ABC/DEF, lưu, tải lại, sửa, báo lại ngày sau và kiểm tra 40% không thành 80%. Kiểm tra cả báo cáo nhập tay, báo cáo bù và tài khoản không được giao công việc.
3. Sau nghiệm thu mới đưa staging vào main và triển khai production theo cùng quy trình. PR này không tự merge, deploy, chạy migration hoặc gửi mail thật.

Migration chỉ thêm dữ liệu; báo cáo và công việc cũ giữ nguyên. Nếu chưa migrate, danh sách cập nhật trong báo cáo ẩn và nhập báo cáo tay vẫn hoạt động. Form giao việc có mục tiêu yêu cầu schema mới. Rollback migration có chốt bảo vệ, từ chối xóa khi đã có mục tiêu, liên kết hoặc lịch sử; khi cần rollback sau sử dụng hãy rollback mã ứng dụng và giữ dữ liệu.

## Kiểm tra

Backend bao gồm giao dịch, phân quyền, revision, sản phẩm trùng/ID ngoại, báo cáo bù, lịch sử, migration và đường dữ liệu từ báo cáo sang worker AI ngày rồi nguồn tuần/trích dẫn. Kiểm tra frontend bao gồm phân tích danh sách tên, preview số lượng, bản nháp, nhập tay, áp dụng/sửa/lưu, phản hồi lỗi, mobile và dark mode. Provider và API trong kiểm tra được giả lập; không phát sinh phí AI hoặc mail thật.

## Bổ sung CSKH và đối soát dữ liệu cũ (M40)

Cần thêm migration `2026_11_13_010000_add_task_progress_reconciliation` sau M39. M39 giữ nguyên, nên môi trường đã chạy M39 chỉ cần chạy phần còn thiếu theo quy trình hiện có. Không thêm ENV hoặc lượt gọi AI.

- Mục tiêu có thêm `khách hàng` và `lượt chăm sóc`. Với khách hàng, một mã khách chỉ tính một lần trong cùng công việc; cùng khách qua Facebook/Zalo và nhiều ngày phải sử dụng cùng mã. Tên hiển thị trùng nhau được phép khi mã khác nhau. Hệ thống không tự đoán hai tên/hồ sơ là một người và không tự tạo hồ sơ CRM. Với lượt chăm sóc, mỗi lượt có mã riêng; gửi lại cùng mã không cộng thêm.
- Kỳ tính theo ngày bắt đầu/hạn của công việc; mục tiêu 80 khách cho tháng 11 cần công việc riêng cho tháng 11, số lượng 80 và đơn vị khách hàng. Mô tả cũ “80 khách/tháng” không tự biến thành mục tiêu có cấu trúc; chỉnh sửa mục tiêu trước hoặc sau đối soát. Kỳ sau tạo công việc mới, không tái sử dụng mã công việc để đặt lại tổng.
- Chi tiết công việc có `Đối soát báo cáo cũ`. Chọn khoảng ngày trong kỳ, xem các đầu việc hoàn thành từ báo cáo đã khóa của người được giao, chọn nguồn đúng công việc và kiểm tra danh sách. Video được gợi ý theo danh sách tên rõ ràng; số tổng không rõ tên/mã cần bổ sung danh sách thực tế. Không tự tạo 5 khách giả từ câu “đã chăm sóc 5 khách”.
- Nhập ghi chú căn cứ và xác nhận rõ trước khi ghi nhận. Có thể chọn nhiều nguồn qua phân trang, tối đa 20 nguồn và 200 đối tượng/lần. Hai báo cáo nhắc cùng video/mã khách chỉ làm tăng tiến độ một lần. Gửi lại cùng thao tác không cộng lại, kể cả sau khi kết quả mạng bị mất.
- Giữ ngày nguồn cho kết quả cũ, lưu người/thời điểm xác nhận và nguồn để xem lại theo quyền. Không thay đổi nội dung, khóa hoặc thời gian báo cáo gốc; không tự tạo/chạy lại bản AI ngày/tuần đã lưu. Đây là đối soát tiến độ, không phải ghi bổ sung kết quả vào báo cáo hôm nay. Nếu cần xem lại AI cũ, dùng luồng tạo phân tích mới; việc đối soát không tự sửa các con số đã tự khai trong báo cáo cũ.
- Đối soát không đổi trạng thái toàn bộ công việc và không ghi đè trạng thái hiện tại của đối tượng đã có. Nó có thể bổ sung ngày hoàn thành sớm hơn khi tìm được nguồn cũ hơn. Một dòng nguồn đã xác nhận không bị ghi đè bằng danh sách khác; cần xem lịch sử thay vì thử nhập lại khác dữ kiện.
- Cả xem nguồn và xác nhận đều kiểm tra lại quyền công việc, quyền báo cáo, tổ chức, người được giao, kỳ và hash nguồn. Đổi quyền/đổi người giao/đổi nguồn/revision sẽ bị chặn hoặc yêu cầu đối soát lại; không ghi dở một phần.
