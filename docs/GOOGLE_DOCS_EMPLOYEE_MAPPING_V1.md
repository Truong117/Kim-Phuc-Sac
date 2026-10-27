# Google Docs Employee Mapping V1

## Mục tiêu và ranh giới

Employee Mapping V1 cho phép người có `weekly_plans.manage_draft` xác minh một tab của nguồn Google Docs với một thành viên KPS đang hoạt động trong đúng tổ chức. Mapping chỉ là dữ liệu xác minh để HR tham khảo; thao tác này không tạo Weekly Plan Entry, Task, assignee, review, approval hoặc publication.

Google Docs Reader tiếp tục trả `identity_verified=false`. Danh tính được quản lý trong registry riêng, không sửa metadata nguồn và không cho AI xác minh.

## Identity và dữ liệu lưu trữ

Migration `2026_10_27_010000_create_weekly_plan_google_docs_employee_mapping_tables.php` tạo:

- `weekly_plan_google_docs_tab_mappings`: unique theo `weekly_plan_id + tab_id_sha256`; lưu organization/document hash để tra cứu suggestion, hash tiêu đề chuẩn hóa, trạng thái, nhân viên, người/thời điểm xác minh và version optimistic locking. Mỗi plan sở hữu decision riêng, nên plan khác không thể thay đổi lịch sử của plan đã xác minh.
- `weekly_plan_google_docs_tab_mapping_events`: lịch sử append-only cho mỗi thay đổi, gồm plan thực hiện thao tác, actor, trạng thái/nhân viên trước-sau, hash bí danh và mapping version.

Google document ID, Google tab ID và tab title không được lưu plaintext trong registry này. Document/tab identity dùng SHA-256; title alias cũng chỉ lưu SHA-256. Tab ID/title hiện tại chỉ lấy từ `tab_manifest` đã được xác minh và mã hóa của durable Google Docs source.

Migration fail-closed nếu migration 16 (`weekly_plans.source_type` và `weekly_plan_google_docs_sources`) chưa hoàn chỉnh hoặc nếu phát hiện một trong hai bảng mapping đã tồn tại; migration không tự sửa partial DDL. Rollback bị từ chối khi mapping hoặc audit event đã có dữ liệu.

## API

Hai route dùng Sanctum, active KPS membership và permission `weekly_plans.manage_draft` với organization scope:

```text
GET /api/weekly-plans/{id}/google-docs/employee-mappings
PUT /api/weekly-plans/{id}/google-docs/employee-mappings
```

Request cập nhật:

```json
{
  "tab_id": "tab-id-from-safe-manifest",
  "status": "VERIFIED",
  "employee_id": 123,
  "expected_version": 0
}
```

`organization_id`, `document_id`, URL và `identity_verified` bị cấm. `employee_id` chỉ được nhận khi status là `VERIFIED`. Các status có thể lưu là `VERIFIED`, `NEEDS_REVIEW`, `NOT_APPLICABLE`; `UNVERIFIED` là trạng thái dẫn xuất khi chưa có record.

Response không chứa Google document ID, credential hoặc provider payload. Response gồm safe tab manifest, active employees, exact mapping, suggestion và opaque `actions.can_update`.

## Authorization và consistency

- Organization luôn được resolve từ active/default membership và scoped plan query.
- Update kiểm tra lại permission từ database ngay trong transaction bằng current locking reads; không dùng membership/permission cache của request.
- Plan phải là Google Docs durable source và ở đúng trạng thái `DRAFT`. Service recheck trạng thái này trong transaction; `CHANGES_REQUESTED`, `SUBMITTED`, `APPROVED` và `PUBLISHED` đều bị khóa.
- Employee phải có active/default membership trong đúng organization, theo cùng validator dùng khi tạo Weekly Plan Entry.
- Organization row lock tuần tự hóa updates; `expected_version` dùng strict optimistic locking. Mọi stale version đều trả conflict, kể cả khi payload giống decision hiện tại. Cùng version và cùng decision là no-op không tăng version hoặc tạo audit event.
- Thứ tự lock của mapping write là: organization → actor active/default membership và grant → weekly plan → tab mapping/version → target employee active/default membership → mapping/audit write. `UserManagementService` cũng khóa organization trước membership khi đổi role hoặc trạng thái, nên deactivation và mapping write dùng cùng chiều lock. Không thay đổi isolation level toàn cục.
- Version được kiểm tra sau khi mapping row đã khóa và trước eligibility của target employee. Vì vậy request hợp lệ về authorization nhưng stale luôn trả `409`; chỉ request dùng current version mới có thể trả `422` khi employee không còn eligible.
- Mapping đã xác nhận chỉ là nguồn lịch sử để tạo suggestion. Exact suggestion dùng đúng organization + document hash + tab hash và không phụ thuộc tab title; alias suggestion dùng title hash đã được HR xác nhận trong plan trước.
- Plan mới luôn bắt đầu `UNVERIFIED`; exact/alias suggestion không tự chuyển plan sang VERIFIED. HR phải chủ động lưu decision cho từng plan.
- Nếu lịch sử exact hoặc alias có nhiều employee khác nhau, employee không còn eligible, tên nhân viên trùng hoặc alias không duy nhất thì không trả suggestion.
- Plan đã khóa giữ nguyên employee decision đã xác minh kể cả khi membership sau đó bị vô hiệu hóa hoặc chuyển tổ chức; thay đổi eligibility chỉ chuyển mapping của plan đang DRAFT sang `NEEDS_REVIEW`.
- Suggestion không được lưu tự động; HR phải chọn và bấm lưu.
- Frontend giữ draft/dirty/baseVersion state riêng theo `tab_id`. Save một tab không reset draft chưa lưu của tab khác. Sau conflict, tab bị khóa ngay trong trạng thái chờ refresh; nếu refresh thất bại thì lựa chọn vẫn được giữ và chỉ có thể retry refresh. Chỉ sau khi nhận snapshot không regressive, HR mới có thể dùng dữ liệu server hoặc xác nhận lại lựa chọn của mình.

## Triển khai database

Trong lượt phát triển này migration chỉ được kiểm thử bằng SQLite in-memory, không chạy trên TiDB. Khi triển khai staging phải chạy incremental migration riêng bằng migration operator sau identity/TLS/ledger/partial-DDL preflight. Không chạy lại staging bootstrap và không chạy seeder vì tính năng tái sử dụng permission hiện có.
