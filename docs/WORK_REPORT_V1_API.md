# Work Report V1 — Backend API Contract

Tất cả endpoint bên dưới dùng prefix `/api`, yêu cầu phiên đăng nhập Sanctum và membership KPS mặc định đang hoạt động. Thời gian lưu trong database và các trường `locked_at`, `created_at`, `updated_at`, `read_at` được trả về theo UTC ISO 8601. Ngày nghiệp vụ và cutoff được tính theo `REPORT_TIMEZONE` (mặc định `Asia/Ho_Chi_Minh`).

## Giá trị cố định

- Item status: `COMPLETED`, `IN_PROGRESS`, `BLOCKED`.
- Overall status được backend suy ra: ưu tiên `BLOCKED`, sau đó `IN_PROGRESS`, còn lại `COMPLETED`.
- Notification type: `REPORT_COMMENT`.
- Notification reference type: `DAILY_REPORT`.
- Các trường `actions.can_create`, `actions.can_edit`, `actions.can_comment` là kết quả opaque từ backend; frontend không tự suy quyền từ role hoặc scope.
- `reporting_required` là trạng thái nghiệp vụ an toàn cho biết membership hiện tại có thuộc nhóm thực hiện báo cáo hằng ngày hay không; trường này không tiết lộ role hoặc permission.

## Today

`GET /reports/today`

```json
{
  "data": {
    "business_date": "2026-10-09",
    "cutoff_at": "2026-10-09T18:00:00+07:00",
    "is_window_closed": false,
    "reporting_required": true,
    "report": null,
    "actions": { "can_create": true }
  }
}
```

Nếu đã có báo cáo, `report` dùng cấu trúc detail bên dưới. Đúng thời điểm cutoff, `is_window_closed` là `true`.

OWNER trả `reporting_required: false` và `actions.can_create: false` dù membership có thêm role khác cấp `reports.create`. Đây là exemption nghiệp vụ riêng của Work Report: OWNER vẫn xem danh sách/chi tiết theo scope `ALL` và vẫn bình luận report đã khóa của người khác. ADMIN không được miễn và tiếp tục trả `reporting_required: true`. V1 không có cột `report_required` trong database.

## Reports

### Danh sách

`GET /reports`

Query hợp lệ:

- `from_date`, `to_date`: `YYYY-MM-DD`.
- `user_id`, `department_id`, `location_id`: chỉ thu hẹp scope backend đã cấp.
- `status`: một trong ba item/overall status.
- `search`: tên nhân viên hoặc nội dung/kết quả/ghi chú item.
- `page`; `per_page` từ 1 đến 100, mặc định 20.

Response là Laravel paginated resource (`data`, `links`, `meta`). Mỗi phần tử:

```json
{
  "id": 42,
  "report_date": "2026-10-09",
  "locked_at": "2026-10-09T11:00:00+00:00",
  "is_locked": true,
  "overall_status": "BLOCKED",
  "employee": { "id": 7, "name": "Nguyễn Văn A" },
  "department": { "id": 2, "name": "Kinh doanh" },
  "location": null,
  "counts": {
    "items": 3,
    "completed": 1,
    "in_progress": 1,
    "blocked": 1,
    "comments": 2,
    "unread_comments": 1
  },
  "created_at": "2026-10-09T03:00:00+00:00",
  "updated_at": "2026-10-09T04:00:00+00:00"
}
```

`unread_comments` luôn tính riêng cho người đang xem.

### Tạo báo cáo hôm nay

`POST /reports`

Payload chỉ nhận `items`:

```json
{
  "items": [
    {
      "content": "Liên hệ khách hàng",
      "result": "Đã xác nhận lịch hẹn",
      "status": "COMPLETED",
      "note": null
    }
  ]
}
```

Có từ 1 đến 50 items. Backend tự gán organization, author, snapshot phòng ban/địa điểm, ngày nghiệp vụ, deadline và `sort_order`. Thành công trả `201` với detail resource. Báo cáo trùng ngày hoặc đã đến cutoff trả `409`.

Membership thuộc diện miễn báo cáo bị từ chối bằng `403` an toàn ở cả create và update, kể cả khi một role khác trên cùng membership có quyền `reports.create`.

Các trường dẫn xuất (`organization_id`, `user_id`, `department_id`, `location_id`, `report_date`, `locked_at`) bị từ chối với `422` nếu client gửi lên.

### Chi tiết

`GET /reports/{id}`

Ngoài các trường summary, response `data` có:

```json
{
  "employee": { "id": 7, "name": "Nguyễn Văn A", "email": "a@kps.local" },
  "items": [
    {
      "id": 90,
      "content": "Liên hệ khách hàng",
      "result": "Đã xác nhận lịch hẹn",
      "status": "COMPLETED",
      "note": null,
      "sort_order": 1
    }
  ],
  "comments": [
    {
      "id": 12,
      "author": { "id": 2, "name": "Quản lý" },
      "content": "Đã ghi nhận.",
      "created_at": "2026-10-09T12:00:00+00:00"
    }
  ],
  "comment_count": 1,
  "unread_comment_count": 1,
  "actions": { "can_edit": false, "can_comment": true }
}
```

Report ngoài scope luôn trả `404`.

### Cập nhật

`PATCH /reports/{id}`

Payload giống create. Backend thay toàn bộ items trong một transaction. Chỉ author được cập nhật trước `locked_at`; report của người khác trả `404`, report đã khóa trả `409`.

### References

`GET /reports/references`

```json
{
  "data": {
    "employees": { "visible": true, "options": [{ "id": 7, "name": "Nguyễn Văn A" }] },
    "departments": { "visible": false, "options": [] },
    "locations": { "visible": false, "options": [] }
  }
}
```

Options chỉ được tổng hợp từ các report người xem có quyền thấy; endpoint không phụ thuộc User Management API và không trả permission/scope nội bộ.

## Comments

`POST /reports/{id}/comments`

```json
{ "content": "Vui lòng bổ sung kết quả." }
```

Thành công trả `201` với comment resource. Chỉ người có cả `reports.view` và `reports.comment` trong scope giao nhau mới dùng được, chỉ sau deadline đã lưu, và không được bình luận report của chính mình. Comment là append-only.

## Notifications

### Danh sách

`GET /notifications?page=1&per_page=20`

`per_page` tối đa 50. Response có `data`, `links`, `meta` và `unread_count`. Một phần tử:

```json
{
  "id": 51,
  "type": "REPORT_COMMENT",
  "title": "Báo cáo có bình luận mới",
  "message": "Quản lý đã bình luận về báo cáo ngày 09/10/2026.",
  "actor": { "id": 2, "name": "Quản lý" },
  "reference": { "type": "DAILY_REPORT", "id": 42 },
  "is_read": false,
  "read_at": null,
  "created_at": "2026-10-09T12:00:00+00:00"
}
```

GET không tự đánh dấu đã đọc.

### Đánh dấu đã đọc

- `PATCH /notifications/{id}/read`: trả notification resource; notification của user khác trả `404`.
- `PATCH /notifications/read-all`: trả `{ "data": { "marked_read_count": 3 } }`.
- `PATCH /reports/{id}/notifications/read`: chỉ author của report; trả `{ "data": { "report_id": 42, "marked_read_count": 2 } }`.

Ba thao tác đều idempotent.

## Lỗi dự kiến

- `401`: chưa đăng nhập.
- `403`: thiếu permission hoặc hành động bị cấm (ví dụ tự bình luận).
- `404`: resource không tồn tại, ngoài scope hoặc không thuộc recipient/author hiện tại.
- `409`: trùng báo cáo ngày, đã đến deadline, hoặc thao tác không còn hợp lệ theo thời gian.
- `422`: payload/query validation không hợp lệ.

API không trả role, permission, data scope, membership pivot hoặc auth internals.
