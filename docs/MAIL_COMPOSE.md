# Mail nội bộ — soạn và gửi (đợt 2)

Mặc định tab Mail mở màn Soạn mail. Owner/Admin/Trợ lý nhập tiêu đề, nội dung
văn bản, chọn tối đa 50 nhân viên, xem trước và xác nhận gửi. Người nhận phải
đang hoạt động trong tổ chức mặc định và có email hợp lệ. Backend chặn các vai
trò khác; chỉ Owner/Admin quản lý kết nối Gmail.

## Triển khai

Không cần migration hoặc biến môi trường mới. Dùng lại payload mã hóa trong
`mail_messages` với `kind=internal`. Giữ cấu hình Gmail/OAuth và hai cờ
`KPS_MAIL_ENABLED`, `KPS_MAIL_SEND_ENABLED` hiện có.

`KPS_MAIL_TEST_RECIPIENTS` chỉ giới hạn màn Gửi thử kết nối; để trống vẫn gửi
được mail nội bộ chính thức. Không nhập email tự do hay gửi sang tổ chức khác.
Mỗi nhân viên nhận một thư riêng, không có To/Cc/Bcc chung. Lời chào/chữ ký
không tự thêm: nội dung gửi đúng bản xem trước. Lịch sử thêm tiêu đề, giữ trạng
thái xanh và giải thích chưa xác nhận đã nhận/đọc; không trả nội dung thư.

## API và chống gửi trùng

- `POST /api/integrations/mail/messages`: `subject` (1–200 ký tự), `body`
  (1–10.000 ký tự), `recipients[]` gồm `recipient_user_id` và `request_key`
  UUID riêng từng người. Giới hạn 6 request/phút/người. Toàn bộ batch được tạo
  trong một transaction trước khi gọi Gmail; một người không hợp lệ sẽ hủy
  toàn bộ thư mới của batch.
- `POST /api/integrations/mail/messages/{uuid}/process`: chỉ người tạo được
  xử lý thư internal trong tổ chức mình. Mỗi request một thư, giới hạn 60
  request/phút/người; dùng cùng bộ xử lý outbox và CLI hiện có.

Từ chối tiêu đề có ký tự điều khiển, nội dung có ký tự điều khiển ngoài tab/
xuống dòng, địa chỉ/header do client chỉ định, HTML và đính kèm. Payload sau
mã hóa phải vừa cột TEXT hiện có (65.535 byte); nếu vượt thì trả lỗi nội dung,
không cắt thư. Văn bản nhiều Unicode có thể đạt giới hạn kích thước trước
10.000 ký tự. Nội dung chỉ được gửi dưới dạng text/plain.

Frontend lưu snapshot nội dung/người nhận/UUID trong session storage theo người
dùng và tổ chức trước khi tạo batch, sau đó khóa nội dung và danh sách. Reload
hoặc mất mạng tiếp tục cùng lượt. UUID cũ không thể dùng cho nội dung, người
nhận, người gửi hoặc loại thư khác. Thư accepted/failed/cancelled/unknown không
tự gửi lại. Thư sending quá 5 phút chuyển unknown khi cập nhật. Chỉ mở lượt mới
khi toàn bộ lượt hiện tại đã accepted/failed/cancelled.

Đóng phiên hoặc xóa dữ liệu trình duyệt có thể mất snapshot; cần đối chiếu
Gmail/lịch sử trước khi soạn lại. Nội dung chưa bấm xác nhận chưa tự lưu nháp.
Chưa có rich text, đính kèm, mẫu thư hoặc lịch gửi báo cáo tự động.

## Kiểm thử staging

1. Gửi một mail đến nhân viên không nằm trong danh sách thử, bằng tài khoản Trợ lý.
2. Đối chiếu From/tiêu đề/nội dung trong Gmail và hộp thư người nhận; mỗi người
   chỉ thấy địa chỉ của mình.
3. Kiểm tra quay lại chỉnh sửa, reload một lượt đang xử lý, lỗi người nhận và
   nội dung; trường lỗi đỏ, được cuộn tới và nhận focus.
4. Kiểm tra mobile light/dark, trạng thái Đã gửi qua Gmail và phần giải thích.

Test tự động giả lập toàn bộ API/Gmail, không gửi mail thật. Kết nối production
đã có không cần cấp lại quyền OAuth vì vẫn dùng cùng scope và cấu hình.
