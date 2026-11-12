# Email báo cáo tuần cá nhân

## Phạm vi

Mỗi nhân viên nhận bản rút gọn của chính mình từ kết quả phân tích tuần toàn công ty đã lưu. Không gọi AI thêm, không gửi kết quả của người khác và không đổi cấu trúc phân tích hiện tại. Link trong thư mở **Báo cáo tuần của tôi** và yêu cầu đăng nhập.

Nhân viên đăng ký tại **Tài khoản → Nhận báo cáo tuần qua email**. Địa chỉ phải đúng định dạng, không phải email cấp tạm như `@kps.local` hoặc miền dành cho thử nghiệm. Đây là kiểm tra điều kiện ban đầu; chỉ liên kết xác nhận mới chứng minh người dùng truy cập được hộp thư. Không giới hạn nhà cung cấp Gmail.

Thư xác nhận gửi tới email tài khoản hiện tại, có hiệu lực 60 phút. Người nhận đăng nhập đúng tài khoản rồi bấm **Xác nhận và nhận báo cáo tuần**; chỉ mở link không kích hoạt đăng ký. Token dùng một lần, được lưu dưới dạng băm; token nằm trong fragment của URL, được gỡ khi giao diện đọc xong. Nội dung thư trong outbox được mã hóa như các thư hiện có.

Người dùng có thể tắt nhận thư. Đổi email sẽ xóa xác nhận và đăng ký cũ, kể cả đổi trở lại email ban đầu. Nhân viên dùng email tạm vẫn đăng nhập, nộp và xem báo cáo bình thường; không bắt buộc đổi email.

Admin/trợ lý xem **Tích hợp → Mail → Email nhận báo cáo tuần cá nhân** để đối chiếu địa chỉ, điều kiện nhận và trạng thái gửi theo tuần. Màn này chỉ đọc, không xác nhận hoặc đăng ký thay người dùng và không hiển thị nội dung báo cáo cá nhân.

## Triển khai

1. Merge vào staging trước, chạy migration **M38** `2026_11_12_000000_create_personal_report_mail_tables` bằng quy trình migration tăng dần hiện có, rồi deploy. Migration thêm bảng đăng ký, bảng lịch sử gửi và hai cột lịch kiểm tra trên `report_automation_cycles`; không sửa nội dung phân tích đã lưu.
2. Giữ `REPORT_PERSONAL_WEEKLY_MAIL_ENABLED=false` trong giai đoạn kiểm tra. Có thể thử thư xác nhận và quản lý đăng ký khi cờ này tắt. Không gửi thư thử tự động tới toàn bộ nhân viên.
3. Kiểm tra kết nối Gmail API hiện có, `KPS_MAIL_ENABLED`, `KPS_MAIL_SEND_ENABLED`, URL frontend HTTPS và quyền gửi mail của `REPORT_AUTOMATION_ACTOR_USER_ID`. Actor phải thuộc cùng tổ chức, có vai trò được tích hợp Mail cho phép. Không thêm toàn bộ nhân viên vào `REPORT_AUTOMATION_RECIPIENT_USER_IDS`: danh sách đó vẫn dành cho thư tổng hợp quản trị.
4. Kiểm tra đăng ký, xác nhận, tắt nhận, đổi email và màn đối chiếu trên staging bằng tài khoản/hộp thư thử đã được cho phép.
5. Sau khi duyệt, áp dụng migration và deploy production, vẫn để cờ gửi cá nhân tắt cho đến khi sẵn sàng. Đặt `REPORT_PERSONAL_WEEKLY_MAIL_START_DATE=YYYY-MM-DD` là **ngày thứ Bảy kết thúc tuần đầu tiên muốn gửi**, rồi mới bật `REPORT_PERSONAL_WEEKLY_MAIL_ENABLED=true`. Giá trị rỗng hoặc không phải thứ Bảy sẽ chặn gửi; không tự suy ra ngày để tránh gửi bù hàng loạt.

Ví dụ nếu chọn tuần kết thúc **14/11/2026**, đặt ngày bắt đầu `2026-11-14`; người dùng cần xác nhận đăng ký trước **08:00 Chủ nhật 15/11/2026**, múi giờ `Asia/Ho_Chi_Minh`.

| Cấu hình | Giá trị / tác dụng |
| --- | --- |
| `REPORT_AUTOMATION_ENABLED` | `true`, dùng lịch tạo báo cáo hiện có |
| `REPORT_PERSONAL_WEEKLY_MAIL_ENABLED` | Mặc định `false`; chỉ điều khiển thư tuần cá nhân |
| `REPORT_PERSONAL_WEEKLY_MAIL_START_DATE` | Bắt buộc khi bật; thứ Bảy đầu tiên được gửi |
| `REPORT_AUTOMATION_MAIL_ENABLED` | Cờ thư tổng hợp admin/trợ lý, độc lập với thư cá nhân |

Không chạy lại migration cũ, không seed lại production. Sau khi đã có đăng ký hoặc lịch sử gửi, M38 từ chối rollback xóa bảng để giữ dữ liệu; muốn dừng thì tắt cờ gửi cá nhân.

## Lịch gửi và xử lý lỗi

- Dùng chu kỳ tuần hiện có: sau khi chốt báo cáo thứ Bảy, thời điểm gửi là 08:00 Chủ nhật. Chỉ gửi khi kết quả riêng của người nhận đã hoàn tất, còn quyền truy cập và nguồn chưa thay đổi; không cần chờ phần tổng hợp công ty hoàn tất nếu phần cá nhân đã đủ.
- Backend đang sleep thì chưa xử lý được. Tick hiện có sẽ bắt kịp khi backend thức dậy; cờ mới không tạo worker luôn chạy hoặc dịch vụ đánh thức backend.
- Chỉ áp dụng từ ngày kích hoạt đã chọn. Người mới đăng ký sau giờ gửi bắt đầu từ tuần sau; không tự gửi lại báo cáo tuần cũ. Nếu bật trễ mà vẫn chọn ngày bắt đầu cũ, các chu kỳ từ ngày đó có thể được xử lý bù cho người đã đăng ký trước hạn.
- Mỗi tổ chức/nhân viên/tuần có một bản ghi gửi duy nhất, dùng request key cố định; reload, chạy tick nhiều lần hoặc tạo phiên bản phân tích mới không tạo thêm thư.
- Ngay trước khi gửi, kiểm tra lại email, đăng ký, tài khoản, quyền, nguồn và phiên bản phân tích. Đổi email, tắt nhận hoặc thu hồi quyền sẽ chặn thư đang chờ. Tắt cờ gửi sẽ tạm dừng thư chưa gửi mà không tiêu hao lượt thử.
- Chỉ retry các lỗi provider cho phép retry theo cơ chế outbox hiện có (ví dụ giới hạn 429). `accepted` nghĩa là Gmail chấp nhận yêu cầu, không chứng minh thư đã tới inbox hoặc được đọc. Trạng thái `unknown` cần kiểm tra thủ công; không tự tạo thư mới hoặc tự gửi lại vì có thể gây trùng.
- Thư xác nhận có cooldown và giới hạn tần suất; không nhận địa chỉ, tiêu đề hay nội dung tùy ý từ client. Thư tuần đang chờ phân tích tiếp tục được kiểm tra theo từng đợt giới hạn.

## Kiểm tra nghiệm thu

1. Email tạm bị bỏ qua; tài khoản và báo cáo vẫn dùng được.
2. Email công khai chưa xác nhận không nhận báo cáo; token sai, hết hạn, dùng lại hoặc của người khác không kích hoạt đăng ký.
3. Xác nhận thành công rồi tắt nhận; đổi email làm mất xác nhận cũ. Không gửi thư đang chờ tới địa chỉ cũ.
4. Mỗi người chỉ nhận bốn nhóm nội dung của mình; link mở đúng tuần và API vẫn lọc theo người đăng nhập.
5. Tick trước 08:00 không gửi, chạy trễ gửi một lần; không tăng số lượt AI. Mail tổng hợp quản trị vẫn theo cờ riêng.
6. Admin/trợ lý đối chiếu trạng thái; nhân viên không truy cập được danh sách email người khác.
