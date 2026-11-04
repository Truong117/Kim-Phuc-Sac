# Khôi phục AI tổng hợp báo cáo ngày sau lỗi

## Thay đổi xử lý nguồn

- Provider v4 trả mã báo cáo, mã đầu việc và tên trường nguồn; hệ thống tự lấy trích dẫn chính xác.
- Kiểm tra riêng bản dữ liệu đã che trước khi gọi AI và bản gốc lưu trong hệ thống.
- Kết quả vẫn phải đủ đầu việc, không lặp nguồn, đúng trạng thái và có số liệu thật. Không dùng đối sánh gần đúng để chấp nhận số hoặc trích dẫn do AI viết lại.
- Định dạng kết quả đã lưu vẫn là daily-output-v3. Các bản hoàn tất hợp lệ tiếp tục dùng được; không cần chạy lại chỉ vì thay provider contract.
- Log daily_document_validation_rejected chỉ ghi document_id, unit_id, usage_record_id và stage; không ghi nội dung nhân viên, phản hồi AI hoặc khóa API.

## Thử trên staging

1. Sau khi CI đạt và người phụ trách merge/deploy, mở một bản lỗi dùng cấu hình cũ.
2. Chọn **Tạo lại bằng bản sửa**. Hộp thoại nêu rõ chi phí có thể phát sinh và việc dùng báo cáo đã khóa hiện tại.
3. Xác nhận tạo bản mới. Hệ thống giữ nguyên bản cũ, số lượt và lỗi cũ; không reset lịch sử.
4. Theo dõi trạng thái. Bản mới tối đa 2 lượt, vẫn dùng kiểm tra quyền và hạn mức hiện có.
5. Đối chiếu nhóm công việc, số liệu và trích dẫn. Kiểm tra báo cáo hoàn tất khác không bị chạy lại.
6. Bấm Tổng hợp toàn công ty để xác nhận bản có cùng nguồn được dùng lại, không tự tạo vòng thử mới.

Không cần migration hoặc đổi biến môi trường cho thay đổi này. Chỉ đưa lên production theo staging → main sau nghiệm thu; không bật luồng tuần cùng lúc.

## Giới hạn và chẩn đoán

- Tạo lại chỉ có ích khi cấu hình xử lý đã đổi. Bản lỗi hiện tại hết lượt không được tự cấp thêm lượt vô hạn.
- Bản mới tính lại các phần theo cấu hình hiện tại, không nhập chung phần kết quả của hai hợp đồng khác nhau. Lịch sử kết quả từng bản vẫn giữ nguyên.
- Kết quả AI vẫn có thể sai hoặc vi phạm schema/nguồn. Khi đó hệ thống tiếp tục từ chối thay vì bỏ qua kiểm tra.
- Nếu lỗi tái diễn, mở chi tiết lỗi và đối chiếu stage trong log: bind_redacted, validate_redacted, bind_original, validate_original. Không cần chia sẻ dữ liệu nhạy cảm hoặc API key.
- Không suy ra trích dẫn cụ thể nào bị sai chỉ từ HTTP 200 hoặc mã AI_INVALID_EVIDENCE cũ.
