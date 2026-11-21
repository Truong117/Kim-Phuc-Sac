# Sửa báo cáo ngày đã khóa

## Triển khai

- Migration `2026_11_21_000000_create_daily_report_amendments` (M47) tạo bảng nhật ký riêng. Không sửa hoặc backfill báo cáo cũ. Dùng quy trình [Windows Migration Tool](MIGRATION_TOOL.md), xem `-StatusOnly` và kiểm tra commit đích trước. Áp dụng trên staging trước khi nghiệm thu, rồi áp dụng riêng trên production khi đã duyệt phát hành.
- Không chạy lại quy trình bootstrap cho cơ sở dữ liệu đã dùng. Không chạy `migrate:rollback` để quay lui tính năng: khi có bản ghi đính chính, migration không cho xóa bảng. Nếu cần quay lui ứng dụng, giữ bảng và khóa mã hóa để có thể đối soát.
- Sau khi migration và frontend/backend staging chạy đúng commit, nghiệm thu bằng tài khoản và dữ liệu mẫu, không dùng báo cáo thật.

## Nghiệm thu nhanh

1. Admin hoặc Trợ lý mở báo cáo đã khóa của tuần hiện tại, sửa một dòng nhập tay của nhân viên khác và nhập lý do. Báo cáo vẫn đứng tên nhân viên, vẫn khóa, và lịch sử hiển thị người sửa cùng lý do.
2. Admin sửa báo cáo của chính mình trong cùng tuần: không có mục lịch sử công khai mới. Nhật ký nội bộ vẫn lưu người sửa, thời gian và phần trước/sau đã mã hóa.
3. Thêm một dòng nhập tay và đánh dấu xóa một dòng nhập tay khác. Kiểm tra có thể hoàn tác xóa trước khi lưu; sau khi lưu, tải lại để xác nhận dòng mới và kiểm tra nhật ký. Dòng mới ở trạng thái Hoàn thành cần có kết quả và link minh chứng.
4. Nhân viên thường không có quyền sửa; báo cáo tuần trước cũng không sửa được qua luồng này. Không mở khóa kỳ hoặc tạo hộ báo cáo. Xác nhận các mục gắn công việc, CRM hoặc Facebook không bị thay đổi và bản AI đã lưu không bị ghi đè. Thao tác lưu hiệu chỉnh không gọi AI trực tiếp; nếu lịch AI hiện hành được bật, lịch có thể tạo một bản mới từ dữ liệu đã sửa.

Áp dụng trong khung thứ Hai đến hết thứ Bảy, theo giờ Việt Nam và lịch báo cáo ngày của hệ thống. Giao diện hiển thị tối đa 50 lần đính chính gần nhất thuộc lịch sử có thể xem. Nhật ký nội bộ vẫn lưu các lần đính chính theo chính sách lưu trữ hiện hành.

Việc gán vai trò Trợ lý được giới hạn cho Owner/Admin. Phạm vi xem báo cáo hiện hành vẫn áp dụng, và riêng vai trò Owner không tự có quyền đính chính.

Thêm/xóa dòng nhập tay sử dụng lại nhật ký M47, không cần migration mới. Báo cáo có dữ liệu CRM trực tiếp nhưng chưa có định danh liên kết tới từng dòng không hỗ trợ thêm/xóa dòng để tránh nhầm nguồn. Không xóa dòng đã liên kết với tiến độ công việc, chăm sóc khách hàng hoặc nguồn Facebook.
