# Báo cáo tuần của tôi

## Phạm vi

`/reports/my-weekly-summary` là giao diện chỉ đọc phần phân tích nhân viên đã lưu trong báo cáo tuần công ty. Không tạo phân tích mới, không gọi nhà cung cấp AI, không gửi email, không sửa lịch tự động. Admin/trợ lý vẫn đọc nội dung tương ứng qua báo cáo công ty theo quyền hiện tại; đây không phải một bản nhận xét bí mật tách khỏi quản lý.

Nhân viên có `reports.view` và thành viên KPS đang hoạt động truy cập qua mục **Báo cáo tuần của tôi** trong nhóm **Công việc**. Không cấp thêm `ai.insights.view`; dùng cùng khóa điều hướng `reports.history` nhưng URL và trạng thái menu độc lập. API luôn giới hạn chính người đăng nhập, kể cả Owner/Admin.

## Nguồn và phiên bản

- Tuần dùng đúng kỳ 7 ngày kết thúc thứ Bảy như luồng tự động hiện hữu (Chủ nhật–thứ Bảy; ngày làm việc vẫn theo cấu hình báo cáo).
- Mặc định hiển thị tuần kết thúc thứ Bảy gần nhất; có thể chọn các thứ Bảy lịch sử hoặc tuần hiện tại. Không tự tạo dữ liệu cho tuần chưa có.
- Ưu tiên `analysis_id` của `report_automation_cycles` cùng tổ chức/kind `weekly`/ngày kết thúc. Sau đó xét các phân tích toàn công ty cùng kỳ, mới nhất trước. Giới hạn tối đa 100 ứng viên mỗi tuần, tải theo lô 10.
- Chỉ dùng phân tích cùng tổ chức, phạm vi toàn tổ chức, không lọc phòng ban, `days=7`, ngày bắt đầu/kết thúc khớp. Kiểm tra lại cả scope/period trong snapshot. Không dùng bản 1/30 ngày hay bản giới hạn cá nhân/phòng ban.
- Chỉ tải các unit `employee:<signed-in-user-id>:*`. Không chờ phần tổng hợp công ty nếu các phần của người này đã hoàn tất.
- Giữ bản cá nhân đã hoàn tất khi ứng viên ưu tiên mới còn chạy/lỗi; thông báo khi đang cập nhật. Phần hoàn tất trong bản chưa đầy đủ vẫn đọc được và ghi rõ trạng thái.
- Nội dung `work_groups`, số liệu, nhận xét và đề xuất dùng lại, không viết hoặc phân tích lại. Bản cũ chỉ đọc khi đầu ra lưu còn hợp lệ; không yêu cầu worker tái hiện prompt cũ.
- Báo cáo bù chỉ xuất hiện khi phiên bản tổng hợp đã bao gồm nguồn đó. So sánh danh sách và thời gian cập nhật báo cáo/đầu việc của chính người xem để cảnh báo nguồn thay đổi; thay đổi của người khác không tạo cảnh báo cá nhân.

## Ranh giới API

`GET /api/reports/my-weekly-summary?end_date=YYYY-MM-DD`

- Sanctum + `active_kps_membership` + `permission:reports.view`; service kiểm tra lại thành viên/quyền.
- Không nhận user ID, tổ chức, phòng ban hoặc analysis ID để chọn người khác. Chỉ ngày kết thúc thứ Bảy hợp lệ, không vượt tuần hiện tại.
- Kiểm tra lại quyền đọc, tổ chức và chủ sở hữu của mọi báo cáo nguồn của người xem trước khi sử dụng snapshot.
- Kiểm tra nội dung/evidence/số liệu của từng unit chỉ với nguồn của người xem; phần sai bị đánh dấu lỗi và không trả nội dung.
- Trả allowlist: kỳ, trạng thái cá nhân, dữ liệu của chính nhân viên, nguồn riêng, thời gian chốt, cờ thay đổi/đang cập nhật/mô phỏng. Không trả snapshot toàn công ty, input/góp ý nội bộ, token/chi phí, lỗi kỹ thuật của người khác hoặc quyền chạy lại.
- `Cache-Control: private, no-store`; frontend không lưu kết quả vào localStorage. Chuyển tuần hủy request/poll trước và bỏ dữ liệu tuần cũ; chỉ poll khi đang chạy/cập nhật. Tải lại là GET.

## Giao diện

Dùng lại các thành phần đọc tập trung hiện hữu: tổng hợp nhóm công việc từng dòng, cần làm rõ, nhận xét, đề xuất hỗ trợ và nguồn thu gọn ở cuối. Không có danh sách chọn nhân viên, thống kê toàn công ty, nút tạo/retry/regenerate hoặc gửi mail.

Trạng thái: `not_analyzed`, `no_data`, `running`, `completed`, `partial`, `failed`. Lỗi tải có nút tải lại; nguồn thay đổi và bản mô phỏng có nhãn rõ. Có chế độ tối và bố cục điện thoại.

## Kiểm tra và phát hành

- Backend: OWN staff không có quyền AI; Owner cũng chỉ thấy bản thân; tham số giả mạo, tenant, quyền bị thu hồi, chủ sở hữu nguồn thay đổi; evidence người khác; phiên bản/tự động/báo cáo bù; trạng thái từng phần; GET không tăng phân tích/unit/mail hoặc enqueue/gọi HTTP.
- Node: ngày kết thúc tuần, lịch sử, năm/tháng, liên kết sai/ngày tương lai.
- Browser: đọc nhóm/nguồn, GET-only, chuyển tuần/Back, polling, trạng thái rỗng/lỗi, retry tải và giao diện mobile/tối. Thử trình duyệt local trước; nếu môi trường không khởi chạy được Chromium, kiểm tra trong CI.
- Không thêm package, migration hoặc biến môi trường. Backend cần baseline M37 hiện có (report automation), đã thuộc staging/main trước PR này.
- PR vào staging, kiểm tra bằng hai tài khoản nhân viên trước khi người dùng quyết định merge/phát hành production. Không tự merge/deploy.
