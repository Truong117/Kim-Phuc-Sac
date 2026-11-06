# Mail KPS — kết nối và gửi thử (đợt 1)

Luồng soạn mail nội bộ chính thức đã được bổ sung ở đợt 2, xem
[MAIL_COMPOSE.md](MAIL_COMPOSE.md). Danh sách thử trong tài liệu này chỉ áp dụng
cho các endpoint `/test`, `/tests` và màn Gửi thử kết nối.

## Phạm vi và phân quyền

Mail nằm trong Tích hợp, bên cạnh Drive và MXH. Chỉ OWNER, ADMIN,
EXECUTIVE_ASSISTANT được xem trạng thái, danh sách nhân viên nhận thử, lịch sử
và gửi thư thử. Chỉ OWNER/ADMIN được kết nối, kết nối lại và ngắt Gmail.
Kiểm tra vai trò nằm ở backend, độc lập với quyền `integrations.manage`.
Thành viên phải đang hoạt động trong tổ chức KPS mặc định.

Đợt này gửi từng thư văn bản cố định đến nhân viên đang hoạt động có email nằm
trong danh sách thử. Chưa có mẫu mail, nháp, đính kèm, hộp thư đến, quy tắc tự
động hoặc endpoint kích hoạt từ GitHub Actions. Không đổi Laravel `MAIL_*`
hiện có, không tự thay SMTP cho các chức năng khác.

## Cấu trúc để chuyển VPS/nhà cung cấp

- `MailOutbox` quản lý yêu cầu bền vững, kiểm tra lại người gửi/người nhận,
  chống trùng theo tổ chức + request UUID, trạng thái và thử lại có giới hạn.
- `MailTransport` và `OutboundMail` là hợp đồng gửi chung. Bộ kết nối thực hiện
  `configured()`, `configurationFingerprint()` và `send()`; đăng ký bằng
  `MailTransportRegistry::extend(...)`, chọn bằng `KPS_MAIL_TRANSPORT`.
- `MailOAuthProvider` là hợp đồng cấp/làm mới quyền. Gmail có bộ kết nối riêng
  và cấu hình riêng; thay nhà cung cấp cần triển khai cấu hình/kết nối của nhà
  cung cấp đó nhưng không sửa nghiệp vụ outbox. Test đăng ký `other_api` và gửi
  thành công khi không có cấu hình Gmail.
- API gửi thử xử lý một thư ngay trong request, có giới hạn HTTP timeout. Lệnh
  `php artisan kps-mail:process --limit=10` xử lý cùng outbox trên bất kỳ máy chủ
  nào. Không phụ thuộc filesystem tạm hoặc worker thường trực của Render.
- Sau khi chuyển VPS, có thể gọi lệnh bằng cron hoặc bọc `process(id)` trong
  queue job. Đợt này chưa bật cron hay worker mail tự động.
- Di chuyển database cùng `APP_KEY` để giải mã token/nội dung lịch sử, cấu hình
  HTTPS/callback/OAuth client tại máy mới rồi kết nối lại. Fingerprint buộc kết
  nối lại khi đổi môi trường, provider, client ID, callback, frontend hoặc Gmail
  kỳ vọng; thư cũ đang chờ có revision cũ sẽ không tự gửi qua kết nối mới.

## Cấu hình backend

| Biến | Giá trị / cách điền |
|---|---|
| `KPS_MAIL_ENABLED` | Mặc định `false`; bật sau migration và cấu hình OAuth |
| `KPS_MAIL_SEND_ENABLED` | Mặc định `false`; bật riêng khi sẵn sàng gửi thử |
| `KPS_MAIL_TRANSPORT` | `gmail_api` |
| `KPS_MAIL_FROM_NAME` | `Kim Phục Sắc - Nội bộ` |
| `KPS_MAIL_TEST_RECIPIENTS` | Email nhân viên nhận thử, phân cách dấu phẩy; trống = không ai được nhận |
| `KPS_GMAIL_CLIENT_ID` | Client ID của OAuth Web application tương ứng môi trường |
| `KPS_GMAIL_CLIENT_SECRET` | Client Secret; chỉ lưu trong secret/env backend |
| `KPS_GMAIL_REDIRECT_URI` | `https://<backend>/api/integrations/mail/google/callback` |
| `KPS_GMAIL_EXPECTED_EMAIL` | `kimphucsac@gmail.com` |
| `FRONTEND_URL` | URL frontend HTTPS; cùng cấu hình frontend hiện hữu của ứng dụng |

Chỉ local/testing chấp nhận HTTP ở localhost/127.0.0.1. Callback phải đúng đường
dẫn trên, không query/fragment. Frontend nhận kết quả ở
`/integrations?mail_oauth=connected` hoặc `failed`; không nhận token/code.
Không đưa Client Secret, JSON OAuth hoặc token vào frontend/Git/log.

Google Cloud: bật Gmail API; OAuth client loại Web application; khai báo chính
xác `KPS_GMAIL_REDIRECT_URI` vào Authorized redirect URIs. Luồng kết nối yêu cầu
`openid email https://www.googleapis.com/auth/gmail.send`: hai quyền đầu xác
nhận đúng Gmail công ty qua Google UserInfo, quyền cuối chỉ gửi thư.
Không xin quyền đọc Gmail, không dùng Gmail drafts. Backend từ chối grant
thiếu quyền gửi/refresh token, có thêm quyền Gmail đọc/quản lý hoặc sai tài khoản.
Nếu OAuth External còn Testing, refresh token có quyền Gmail thường hết hạn sau
7 ngày; xử lý trạng thái/xác minh ứng dụng trước vận hành thường xuyên.
Tách OAuth client và danh sách nhận thử của staging/production.

Tài liệu Google:
- https://developers.google.com/identity/protocols/oauth2/web-server
- https://developers.google.com/identity/protocols/oauth2
- https://developers.google.com/workspace/gmail/api/auth/scopes
- https://developers.google.com/identity/openid-connect/openid-connect

## Migration và phát hành

Migration mới (bước 31):
`2026_11_06_010000_create_mail_foundation_tables.php`.
Tạo ba bảng; không sửa dữ liệu nghiệp vụ cũ hoặc seed tài khoản:

| Bảng | Nội dung |
|---|---|
| `mail_connections` | Một kết nối/tổ chức, provider, trạng thái, revision, fingerprint, token mã hóa |
| `mail_oauth_attempts` | Hash state dùng một lần, PKCE verifier mã hóa, người khởi tạo, thời hạn 10 phút |
| `mail_messages` | UUID yêu cầu, người gửi/nhận, snapshot nội dung mã hóa, trạng thái, lần thử, provider ID |

Trước migration: dùng đúng release đã được duyệt, xác minh database/TLS/ledger,
backup và đảm bảo chỉ một người chạy DDL. Theo [quy trình migration hiện có](MIGRATION_TOOL.md),
chạy StatusOnly trước, kiểm tra chính xác danh sách pending rồi mới execute.
Tool generic có thể áp dụng nhiều migration pending; không dùng allowlist cũ
M16–M22 để chạy bước 31. Không chạy migration thật từ môi trường phát triển.

Sau migration: kiểm tra cả ba bảng/cột/index/FK và ledger, không chỉ số dòng.
Foreign keys trỏ organizations/users hiện có; request unique trên
`organization_id, request_key`; dispatch index trên `status, available_at`.
TiDB DDL có thể còn một phần nếu lỗi: kiểm tra schema và ledger trước khi thử
lại, không thêm ledger giả hoặc tự drop bảng. Migration mới đã kiểm thử SQLite;
cần xác minh TiDB thật trên staging.

Mã có thể triển khai với hai cờ tắt trước khi cập nhật DB. Tab báo chưa bật,
không gọi Google và không đọc bảng Mail chưa tồn tại. Sau khi DB sẵn sàng:

1. Cấu hình backend và callback Google, bật `KPS_MAIL_ENABLED=true`, giữ gửi tắt.
2. Owner/Admin kết nối Gmail, xác nhận đúng tài khoản, trạng thái Đã kết nối.
3. Điền email 1–2 nhân viên nhận thử đã thống nhất, bật gửi thử.
4. Xem trước, xác nhận một thư, đối chiếu Gmail đã gửi và hộp thư người nhận.
5. Kiểm tra Trợ lý gửi được nhưng không cấu hình; vai trò khác không thấy Mail
   và API trả 403. Kiểm tra thu hồi vai trò/người nhận ngừng hoạt động.
6. Thử ngắt kết nối: xóa token tại KPS, vô hiệu OAuth pending, hủy thư chưa gửi.

Chưa thực hiện các bước trên trên staging/production trong PR này.

## API và trạng thái gửi

API dưới `/api/integrations/mail` yêu cầu Sanctum + active KPS membership:
`GET /capabilities`, `GET /`, `GET /recipients`, `POST /connect`,
`DELETE /connection`, `POST /test`.
Callback GET `/google/callback` không cần cookie KPS; state bí mật, hash một
lần gắn actor/tổ chức/revision/cấu hình, PKCE và kiểm tra quyền lại trước lưu
ràng buộc lượt cấp quyền. Kết nối chồng nhau chỉ chấp nhận lượt mới nhất.
Connect/disconnect/test có throttle 3 lần/phút/người.

Gửi thử chỉ nhận `recipient_user_id` + `request_key` UUID; backend lấy email.
Không cho override to/email/cc/bcc/tổ chức/provider/nội dung. Lịch sử 20 yêu cầu
gần nhất thuộc tổ chức, không lộ token hoặc exception Google.

| Trạng thái | Ý nghĩa và thao tác |
|---|---|
| `queued` | Chờ xử lý; có thể gọi lại cùng UUID hoặc chạy CLI khi đến hạn |
| `sending` | Đã nhận xử lý; không lấy lại để gửi song song |
| `accepted` | Gmail trả ID tiếp nhận; không phải xác nhận giao/đọc |
| `failed` | Thất bại đã xác định; kiểm tra lỗi trước khi tạo yêu cầu mới |
| `unknown` | Không biết Google đã nhận chưa; kiểm tra Gmail trực tiếp, không tự gửi lại |
| `cancelled` | Bị hủy do ngắt kết nối/người nhận không còn hoạt động |

HTTP timeout, 408, 5xx hoặc phản hồi thành công thiếu ID => unknown. Chỉ 429 rõ
ràng cho phép retry, tối đa 3 lần với available_at lùi theo phút. CLI khôi phục
sending quá 5 phút thành unknown; không tự gửi lại. Render Free đợt này chưa có
bộ kích hoạt định kỳ: queued/sending bị gián đoạn cần người vận hành chạy CLI
khi có quyền truy cập hoặc đối chiếu, chưa cam kết gửi đúng giờ.

Frontend giữ UUID của yêu cầu chưa rõ kết quả theo người soạn/nhân viên trong
sessionStorage của tab, kể cả tải lại trang. Backend chống trùng bền vững.
Đóng tab/xóa storage có thể mất UUID phía trình duyệt: với trạng thái unknown
phải đối chiếu lịch sử và Gmail trước khi tạo yêu cầu mới.

Ngắt kết nối chỉ xóa thông tin tại KPS, không revoke grant chung trên Google
để tránh ảnh hưởng tích hợp Google khác. Có thể thu hồi ở Google Account nếu
muốn; tác vụ đã bắt đầu gửi không thể thu hồi bằng nút ngắt.

## Kiểm thử và giới hạn nghiệm thu

- `php artisan test`: toàn bộ backend; Mail dùng fake HTTP, chặn stray requests.
- `npm test`, `npm run lint`, `npm run build`.
- `npx playwright test --config playwright.report-validation.config.ts`:
  bao gồm mail UI, phân quyền, xem trước/cá nhân hóa, history, giữ UUID qua reload,
  mobile và lỗi người nhận; toàn bộ API trình duyệt được giả lập.
- Kết nối Google, refresh token thật, giao thư thật và migration TiDB thật là các
  bước nghiệm thu staging sau khi cấu hình secret, không được thay bằng kết quả
  test fake.


## Bổ sung chọn nhiều người nhận

Không có migration mới. Dùng lại `mail_messages`; mỗi người nhận có request UUID,
From name và nội dung snapshot riêng. Tên mặc định và chữ ký mới là
`Kim Phục Sắc - Nội bộ`; hai giá trị mặc định cũ với dấu gạch ngắn/dài cũng được
chuẩn hóa khi đọc env, không cần kết nối OAuth lại. Tên tùy chỉnh khác vẫn được giữ.
Lịch sử và snapshot thư đã tạo không bị sửa.

Owner/Admin/EXECUTIVE_ASSISTANT chọn tối đa 50 nhân viên, tìm theo tên/email/phòng
ban hoặc chọn các kết quả đang hiển thị được phép nhận thử. Gửi riêng từng thư,
không dùng To/Cc/Bcc chung. Trợ lý đã có quyền gửi từ bản nền tảng; bản này kiểm
thử lại quyền gửi nhiều người và tiếp tục chặn kết nối/ngắt Gmail đối với Trợ lý.
Danh sách `KPS_MAIL_TEST_RECIPIENTS` vẫn áp dụng vì đây là màn gửi thử.

`POST /api/integrations/mail/tests` nhận `recipients[]`, mỗi phần tử chỉ chứa
`recipient_user_id` và `request_key`. Kiểm tra toàn bộ danh sách trong transaction;
nếu một người không hợp lệ thì không tạo thư mới cho bất kỳ người nào. Endpoint
chỉ lưu outbox, không gọi Google trong transaction.

`POST /api/integrations/mail/tests/{uuid}/process` chỉ cho người đã tạo thư xử lý
thư thuộc tổ chức mình; mỗi request xử lý tối đa một thư, dùng chung nghiệp vụ với
CLI. Hai endpoint giới hạn lần lượt 6 và 60 request/phút/người. Endpoint cũ gửi
một người vẫn giữ nguyên để tương thích. Quyền và trạng thái người nhận được
kiểm tra lại ngay trước gửi; tác vụ sending quá 5 phút được chuyển unknown, không
lặp lại lệnh gửi. Request queued sau 429 tiếp tục giữ thời điểm có thể thử lại.

Frontend lưu toàn bộ UUID trước khi gửi yêu cầu batch, khóa danh sách sau xác
nhận và có nút tiếp tục sau khi tải lại trang. Kết quả từng người hiện khi xử lý.
Thư accepted/failed/cancelled/unknown không tự gửi lại. Khi toàn bộ lượt gửi đã
có kết quả xác định, người dùng có thể chủ động bấm Tạo lượt gửi mới. Khi có
unknown, cần đối chiếu Gmail trước; nút tạo lượt mới không được bật tự động.
Phiên bản này chưa triển khai mẫu mail/soạn nội dung hoặc lịch gửi tự động.
