# Task Management V1 API

Task Management là miền dữ liệu độc lập với Daily Work Report. Mọi endpoint dưới đây yêu cầu session Sanctum hợp lệ và active default membership của tổ chức KPS.

## Mô hình dữ liệu

`tasks` lưu:

- `organization_id`: ranh giới tenant bắt buộc.
- `creator_id`: người giao việc, do backend lấy từ user đăng nhập.
- `assignee_id`: người phụ trách.
- `department_id`, `location_id`: snapshot đơn vị của người phụ trách tại thời điểm tạo hoặc giao lại.
- `title`, `description`, `priority`, `status`.
- `start_date` tùy chọn, `due_date` bắt buộc.
- `completed_at` được backend đặt khi status chuyển sang `COMPLETED` và xóa khi mở lại.
- `created_at`, `updated_at`.

Priority: `LOW`, `MEDIUM`, `HIGH`, `URGENT`.

Status: `TODO`, `IN_PROGRESS`, `BLOCKED`, `COMPLETED`.

`is_overdue` không được lưu. Giá trị này được tính theo ngày kinh doanh tại `reports.timezone`: `due_date` nhỏ hơn ngày hiện tại và status khác `COMPLETED`.

## Phân quyền và scope

- `tasks.view`: xem danh sách, references và chi tiết trong scope.
- `tasks.manage`: tạo, sửa nội dung, deadline và người phụ trách trong scope.
- `tasks.status.update`: người được giao cập nhật trạng thái của chính công việc đó.
- Người có `tasks.manage` hợp lệ cũng có thể cập nhật status của task trong manage scope.
- `OWN`/`SELF`: task do user tạo hoặc được giao cho user.
- `DEPARTMENT`: đối chiếu department hiện tại của membership với snapshot trên task.
- `LOCATION`: đối chiếu location hiện tại của membership với snapshot trên task.
- `ORGANIZATION`/`ALL`: toàn bộ task trong tổ chức KPS.
- `TEAM` và scope rỗng fail closed vì hệ thống chưa có dữ liệu quan hệ team đáng tin cậy.
- Lookup ngoài scope trả `404` để tránh IDOR và không làm lộ sự tồn tại của task.

Frontend chỉ dùng navigation key `tasks` và các action opaque `can_create`, `can_manage`, `can_update_status`; không nhận permission hoặc role code.

## Endpoints

### `GET /api/tasks`

Query tùy chọn: `search`, `status`, `priority`, `assignee_id`, `department_id`, `location_id`, `due_from`, `due_to`, `overdue`, `page`, `per_page`.

Response chứa collection phân trang, `summary` của toàn bộ scope trước filter và `actions.can_create`.

```json
{
  "data": [
    {
      "id": 17,
      "title": "Chuẩn bị báo giá tháng 11",
      "description": "Tổng hợp danh sách sản phẩm",
      "priority": "HIGH",
      "status": "IN_PROGRESS",
      "start_date": "2026-10-22",
      "due_date": "2026-10-28",
      "is_overdue": false,
      "completed_at": null,
      "creator": { "id": 2, "name": "Quản lý" },
      "assignee": { "id": 8, "name": "Nhân viên" },
      "department": { "id": 3, "name": "Kinh doanh" },
      "location": null,
      "actions": { "can_manage": true, "can_update_status": true },
      "created_at": "2026-10-22T08:00:00Z",
      "updated_at": "2026-10-22T08:00:00Z"
    }
  ],
  "meta": {},
  "summary": {
    "total": 12,
    "todo": 4,
    "in_progress": 5,
    "blocked": 1,
    "completed": 2,
    "overdue": 2
  },
  "actions": { "can_create": true }
}
```

### `GET /api/tasks/references`

Trả các option filter lấy từ task nhìn thấy, danh sách `assignable_employees` đã giới hạn theo manage scope và `actions.can_create`.

### `POST /api/tasks`

Yêu cầu `tasks.manage` và manage scope được hỗ trợ.

```json
{
  "title": "Chuẩn bị báo giá tháng 11",
  "description": "Tổng hợp danh sách sản phẩm",
  "assignee_id": 8,
  "priority": "HIGH",
  "start_date": "2026-10-22",
  "due_date": "2026-10-28"
}
```

Backend bỏ qua mọi giá trị client tự gửi cho organization, creator, department, location, status hoặc completed time. Task mới luôn bắt đầu ở `TODO`. Người được giao nhận một notification `TASK_ASSIGNED`, trừ trường hợp tự giao cho chính mình.

### `GET /api/tasks/{id}`

Trả cùng safe task resource; ngoài view scope trả `404`.

### `PATCH /api/tasks/{id}`

Yêu cầu `tasks.manage`. Payload đầy đủ giống create. Giao lại task cập nhật snapshot department/location và tạo `TASK_REASSIGNED` cho người mới.

### `PATCH /api/tasks/{id}/status`

```json
{ "status": "COMPLETED" }
```

Assignee phải có `tasks.status.update` tại thời điểm request và task phải đồng thời khớp với `DataScope` của permission đó. `TEAM` hoặc scope không được hỗ trợ luôn fail closed, kể cả khi một permission `tasks.view` khác vẫn cho phép nhìn thấy task. Người có `tasks.manage` hợp lệ vẫn được cập nhật status trong manage scope. Endpoint chỉ chấp nhận status nên không thể đổi assignee, deadline hoặc nội dung qua đường dẫn này.

## Triển khai database an toàn

Không chạy seeder tổng `AuthorizationSeeder` một cách mặc định trên database đang vận hành vì seeder đó đồng bộ toàn bộ permission của system roles. Quy trình đề xuất:

1. Backup và xác nhận đúng database/environment.
2. Chạy migration mới bằng tài khoản migration riêng trong maintenance window.
3. Review role hiện hữu, sau đó chạy riêng `TaskManagementPermissionSeeder` để thêm `tasks.status.update` bằng `syncWithoutDetaching`.
4. Smoke-test với OWNER/ADMIN, manager theo department/location, assignee và user TEAM fail-closed.
5. Rollback ứng dụng trước migration nếu có lỗi; chỉ rollback migration khi đã xác nhận không có task thật cần giữ.

Seeder targeted không xóa hoặc thay đổi các grant không liên quan. V1 không tạo dữ liệu mẫu.
