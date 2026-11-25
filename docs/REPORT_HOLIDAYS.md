# Ngày nghỉ báo cáo thủ công

## Sử dụng

Mở **Cài đặt → Ngày nghỉ & ngày lễ**. Owner, admin, assistant và HR được thêm, sửa, xóa và bật/tắt lịch nghỉ qua quyền riêng `report_holidays.manage`; `settings.view` mở trang cài đặt. Assistant/HR không được cấp quyền quản lý cài đặt khác. Quyền và phạm vi tổ chức được kiểm tra tại API.

- Chọn một ngày hoặc khoảng ngày (bao gồm ngày đầu và ngày cuối), nhập tên và ghi chú tùy chọn.
- Lịch được áp dụng cho toàn công ty KPS, riêng báo cáo công việc nội bộ. Số ngày tác động chỉ tính các ngày thuộc lịch làm việc đã cấu hình; mặc định thứ Hai–thứ Bảy.
- Không cho lưu lịch đang bật trùng với lịch đang bật khác; bật lại một lịch cũng kiểm tra trùng. Khoảng chỉ gồm ngày nghỉ hằng tuần không được lưu.
- Dùng công tắc để tạm tắt lịch. Nút Xóa yêu cầu xác nhận, đưa lịch ra khỏi danh sách và ngừng áp dụng; bản ghi xóa mềm và lịch sử vẫn được lưu. Mỗi thay đổi được ghi vào `report_holiday_changes` với người thực hiện và dữ liệu trước/sau.
- Không tạo sẵn ngày nghỉ từ dữ liệu mẫu, không lặp hằng năm và không đồng bộ lịch nghỉ toàn quốc.

## Ảnh hưởng tới báo cáo

Ngày nghỉ đang bật không cho tạo/cập nhật báo cáo của chính ngày đó, không tính vào số ngày cần báo cáo hoặc số ngày thiếu trên tổng quan/thống kê tuần. Màn tạo báo cáo và thẻ báo cáo cá nhân hiển thị tên ngày nghỉ, kèm ghi chú nếu có. Báo cáo đã tồn tại và số liệu công việc được giữ nguyên; ô tuần vẫn cho xem báo cáo đó theo quyền hiện có.

**Nghỉ hôm nay vẫn được nộp bù cho ngày làm việc khác.** Chỉ ngày được báo cáo mới bị kiểm tra có thuộc lịch nghỉ hay không. Giới hạn cũ không đổi: ba ngày lịch tiếp theo, trong cùng tuần thứ Hai–thứ Bảy, chốt tuần cuối thứ Bảy theo múi giờ `Asia/Ho_Chi_Minh`. Ngày nghỉ không kéo dài hạn nộp bù.

Lịch có hiệu lực ngay khi lưu/bật/tắt, kể cả đối với thống kê ngày đã qua; giao diện nhắc ảnh hưởng khi sửa lịch quá khứ. Tổng hợp tự động hằng ngày bỏ qua ngày nghỉ, còn mốc tổng hợp tuần thứ Bảy vẫn giữ nguyên dù thứ Bảy là ngày nghỉ. Quy trình bổ sung báo cáo đã khóa có phân quyền và lịch sử riêng được giữ nguyên.

API cập nhật/xóa yêu cầu `expected_version`; chỉnh sửa bằng dữ liệu cũ trả 409 và giao diện tải lại danh sách. Khóa theo tổ chức đồng bộ việc kiểm tra lịch giữa lưu lịch và nộp báo cáo.

## Migration và kiểm tra

Migration mới: `2026_11_25_010000_create_report_holidays_tables` (bước 48 của manifest staging), tạo `report_holidays` và `report_holiday_changes`. Phải áp dụng migration trước khi chạy phiên bản ứng dụng này theo runbook môi trường hiện có. Migration không sửa hay xóa báo cáo cũ. Rollback từ chối xóa bảng nếu đã có lịch nghỉ hoặc lịch sử thay đổi.

Sau migration, chạy riêng seeder bổ sung quyền trên đúng môi trường đã được kiểm tra:

```bash
php artisan db:seed --class=ReportHolidayPermissionSeeder --force
```

Seeder này chạy lặp lại được, chỉ bổ sung quyền lịch nghỉ cho bốn vai trò hiện có và quyền xem cài đặt cho HR; không đồng bộ lại hoặc thu hồi quyền khác, không tạo tài khoản/vai trò mới. Seeder không chạy trong migration/startup. Cài mới qua `AuthorizationSeeder` đã bao gồm bước này.

Các kiểm thử mới bao phủ quyền, tách tổ chức, khoảng ngày/trùng lịch, phiên bản cũ, lưu bền và lịch sử, báo cáo bù/hạn tuần, thống kê ngày/tuần/AI, lịch tự động, giữ báo cáo cũ, migration và UI desktop/mobile. Backend chạy với SQLite in-memory; kiểm thử UI dùng API giả lập, không truy cập production.
