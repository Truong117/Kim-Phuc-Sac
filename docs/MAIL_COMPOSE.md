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
10.000 ký tự. Nội dung người gửi vẫn là văn bản thuần. Thư mới gửi multipart/alternative gồm text/plain nguyên bản và text/html do server tạo, không nhận HTML từ client.

Frontend lưu snapshot nội dung/người nhận/UUID trong session storage theo người
dùng và tổ chức trước khi tạo batch, sau đó khóa nội dung và danh sách. Reload
hoặc mất mạng tiếp tục cùng lượt. UUID cũ không thể dùng cho nội dung, người
nhận, người gửi hoặc loại thư khác. Thư accepted/failed/cancelled/unknown không
tự gửi lại. Thư sending quá 5 phút chuyển unknown khi cập nhật. Chỉ mở lượt mới
khi toàn bộ lượt hiện tại đã accepted/failed/cancelled.

Đóng phiên hoặc xóa dữ liệu trình duyệt có thể mất snapshot; cần đối chiếu
Gmail/lịch sử trước khi soạn lại. Nội dung chưa bấm xác nhận chưa tự lưu nháp.
Chưa có rich text, đính kèm hoặc lịch gửi báo cáo tự động. Các mẫu dựng sẵn
được bổ sung bên dưới.

## Mẫu mail dựng sẵn

Có 5 mẫu: Báo cáo ngày, Báo cáo tuần, Kế hoạch tuần, Kế hoạch tháng, Có vấn đề
cần xử lý. Các mẫu có sẵn trong mã, không cần migration hay env mới. Chưa có
trang quản lý/lưu mẫu tùy chỉnh trong database.

- Báo cáo ngày: chọn ngày bằng lịch KPS, chọn một nhân viên trong danh sách
  báo cáo AI ngày đã hoàn tất. Danh sách phân trang theo API ngày hiện có.
  Giữ nhóm công việc, nhận xét, đề xuất, căn cứ và số liệu còn cần xác nhận.
  Đầu danh sách có **Tổng hợp ngày (AI)**: lấy bản `work-report-analyses` kỳ
  1 ngày, đúng cả ngày bắt đầu/kết thúc, không áp dụng bộ lọc phòng ban và trong
  phạm vi hiện được cấp quyền xem. Dùng bản hoàn tất mới nhất còn khớp nguồn,
  đọc lại bản đó trước khi tự điền. Nội dung gồm đầy đủ phần tổng hợp chung,
  nhận định/giới hạn và hành động cần xem xét; liên kết mở đúng
  bản AI với `days=1`. Báo cáo tổng hợp và báo cáo từng nhân viên là hai lựa
  chọn độc lập; không ghép các nhận xét cá nhân để giả thành kết luận AI chung.
  Nếu thiếu bản tổng hợp hợp lệ, chặn gửi, giải thích cách tạo/cập nhật và có
  liên kết mở Phân tích AI đúng ngày/kỳ 1 ngày. Không tự tạo lượt AI hay gọi API
  tạo/retry. Đổi ngày hoặc tải lại khi đang chọn tổng hợp sẽ giữ chế độ tổng
  hợp; không tự dùng bản cũ hoặc chuyển sang cá nhân. Không cần migration/env.
- Báo cáo tuần: các nguồn được ghi rõ **Tổng hợp tuần (AI)**; chọn bản AI 7 ngày trong tối đa 100 lượt gần nhất thuộc phạm
  vi được phép xem. Lấy toàn bộ các mục trong phần tổng hợp chung của AI;
  kèm liên kết mở đúng bản và kỳ để xem cả chi tiết từng nhân viên. Tiêu đề tự
  điền Báo cáo tổng hợp tuần theo ngày bắt đầu/kết thúc; khi thiếu nguồn, có
  liên kết đến Phân tích AI kỳ 7 ngày để tạo/cập nhật.
- Kế hoạch tuần/tháng và vấn đề: khung có các mục cần điền, không tự điền mục
  tiêu, thời hạn, chỉ tiêu hay dữ liệu giả. Người gửi chỉnh sửa trước khi gửi.

Mail tổng hợp ngày/tuần có đúng bốn phần: Kết quả trong kỳ; Công việc đang
tiếp tục; Vướng mắc cần theo dõi; Việc cần xem xét và quyết định. Phần cần làm
rõ được giữ trong mục vướng mắc. Tên nhân viên lấy từ cặp báo cáo/đầu việc đã
lưu, không đoán người phụ trách. Không lặp nhãn “Ghi nhận từ nguồn”, “Nhận xét
có căn cứ”, “Đề xuất:”; chỉ lược nhãn ở đầu câu, giữ nguyên các giới hạn cụ thể
như chưa xác nhận phê duyệt cuối. Mail ngày cá nhân cũng bỏ nhãn và danh sách
trích dẫn sau từng đoạn, giữ nhóm công việc và số liệu chưa rõ.

Mail chỉ đưa nội dung chính của đề xuất (kèm ưu tiên cao nếu có), không chép
cả danh sách trích dẫn/lý do/kiểm chứng. Liên kết vẫn mở đúng bản AI đầy đủ,
nơi căn cứ được mở rộng bằng “Xem căn cứ” và mỗi đề xuất mở được phần chi tiết.
Thay đổi chỉ ở bản trình bày; dữ liệu AI gốc, bằng chứng và bản gửi đã lưu
không bị viết lại. Không tự tạo lại AI hay gửi lại mail cũ.

Chỉ đọc API AI hiện có, dùng nguyên quyền và phạm vi dữ liệu của các API đó;
không gọi tạo/retry/regenerate, không tốn thêm lượt AI. Tự tải nguồn khi chọn mẫu, đổi ngày/trang hoặc chọn báo cáo AI. Chặn bản đang chạy, partial/failed, nguồn đã thay đổi, bản ngày
cần tạo lại hoặc bản tuần không còn tương thích/chưa xác minh được độ mới.
Bản AI mô phỏng được đánh dấu rõ, không trình bày như AI thật.

Chọn mẫu tự điền tiêu đề/nội dung, giữ nguyên người nhận; không còn bước xem
trước mẫu rồi xác nhận áp dụng. Mẫu báo cáo ngày tự đặt tiêu đề theo ngày và
tự tải danh sách AI. Nếu chỉ có một nguồn hợp lệ, tự điền ngay; nếu có nhiều
nguồn, người dùng chỉ cần chọn nguồn. Mẫu tuần tương tự, tiêu đề cập nhật theo
kỳ của bản AI. Mẫu kế hoạch/vấn đề tự điền khung ngay khi chọn, vẫn cần điền
các thông tin nghiệp vụ còn để trong dấu ngoặc vuông. Có thể chỉnh sửa sau khi
nội dung tự điền; chỉ tự soạn mới cần nhập tiêu đề và nội dung từ đầu.

Đổi ngày/trang/nguồn xóa nội dung AI cũ ngay để tránh gửi nhầm dưới tiêu đề mới.
Khóa xem trước/gửi trong lúc tải, khi chưa chọn nguồn giữa nhiều nguồn hoặc khi
nguồn bị thiếu/lỗi/không có quyền. Không yêu cầu nhập tay để bù cho AI chưa có;
người dùng có thể chọn nguồn khác hoặc chuyển sang tự soạn. Hủy request cũ khi
đổi mẫu/ngày; kết quả cũ không được ghi đè mẫu mới. Nút tải lại chỉ dùng để làm
mới nguồn khi cần, không phải bước bắt buộc.

Vẫn xem trước và xác nhận riêng khi gửi. Nội dung quá dài được cảnh báo, không
tự cắt. Mẫu bị khóa khi đã bắt đầu lượt gửi để giữ nguyên snapshot và mã chống
gửi trùng. Không cần migration, biến môi trường hoặc quyền OAuth mới.


## Bố cục thư thực nhận

Thư nội bộ mới có bố cục `readable_v1`: khung rộng 100%, tối đa 760px,
font Arial 15px, tiêu đề báo cáo và bốn mục phân cấp rõ; gạch đầu dòng có
khoảng cách và phần chủ đề trước dấu “—” được in đậm. Dùng bảng trình bày,
CSS inline; không có ảnh, font hay tài nguyên ngoài. Chỉ chuyển URL HTTPS
hợp lệ thành liên kết. Mọi nội dung người dùng được escape, không thực thi
HTML/script hay mã đánh dấu tùy ý.

`POST /api/integrations/mail/messages/preview` nhận subject/body và layout
`readable_v1`, cùng giới hạn nội dung và RBAC của soạn thư; trả HTML không
cache. Không tạo outbox, gọi Gmail hoặc gọi AI. Màn xem trước dùng iframe
sandbox, không script, có bản văn bản có thể mở; lỗi tải preview sẽ chặn xác
nhận gửi. Trong khi tải/xem trước, nội dung được khóa để tránh xem một bản
và gửi một bản khác. Thư gửi được tạo bằng cùng renderer và cùng văn bản.

Outbox chỉ lưu văn bản gốc cùng tên phiên bản bố cục trong payload mã hóa,
không nhân đôi HTML vào cột TEXT. Giữ renderer phiên bản này cho các thư đã
xếp hàng. Thư cũ không có phiên bản bố cục tiếp tục gửi dạng plain text;
thư đã gửi và mã chống gửi trùng không thay đổi. Không cần migration/env mới;
cần deploy backend và frontend. Không tự gửi thư thử thật.
