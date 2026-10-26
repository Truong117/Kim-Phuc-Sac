# Google Docs organization-folder authorization

## Trạng thái

Organization-folder registry, audit trail và scoped preview endpoint đã được triển khai trong mã nguồn local. Migration mới chưa được chạy trên Staging/Production, permission chưa được seed trên database từ xa và chưa có Google credential thật. Không bật route trên môi trường vận hành trước khi hoàn thành các bước kích hoạt cuối tài liệu.

## Ranh giới bảo mật

Google Docs URL, document ID hoặc quyền Viewer của Service Account không phải là KPS authorization. KPS luôn:

1. xác thực Sanctum;
2. yêu cầu active KPS membership;
3. kiểm tra permission và organization-wide scope;
4. tự lấy organization từ membership, không nhận `organization_id` từ client;
5. đối chiếu document với folder đã duyệt của organization bằng Drive metadata trước và sau khi đọc.

HR chỉ cần dán canonical URL `https://docs.google.com/document/d/{id}/...`. Backend không gọi host trong input mà chỉ tách ID đã validate rồi gọi fixed Google APIs.

## Schema registry và audit

Migration thứ 15:

`2026_10_26_000000_create_organization_google_docs_folder_tables.php`

### `organization_google_docs_folders`

| Column | Ý nghĩa |
| --- | --- |
| `id` | Primary key nội bộ |
| `organization_id` | Unique FK; tối đa một registry record cho mỗi organization |
| `google_folder_id` | Unique Google Drive folder ID; không phải credential và được lưu trực tiếp |
| `is_active` | Cờ bật/tắt fail-closed |
| `approved_by_user_id` | Actor phê duyệt gần nhất |
| `approved_at` | Thời điểm phê duyệt/thay thế gần nhất |
| timestamps | Thời điểm tạo/cập nhật registry |

`organization_id` và `google_folder_id` đều unique. Các FK dùng `ON DELETE RESTRICT`.

### `organization_google_docs_folder_events`

Audit append-only ở application surface, không có update/delete API. Event lưu:

- `FOLDER_APPROVED`;
- `FOLDER_REPLACED`;
- `FOLDER_DISABLED`;
- folder record, organization, actor, folder ID trước/sau và timestamp.

Event và registry dùng FK `ON DELETE RESTRICT` để không vô tình mất audit khi xóa organization/user/folder record. V1 không có API xóa registry.

## Permission và route

| Route | Permission | Mục đích |
| --- | --- | --- |
| `POST /api/weekly-plans/google-docs/preview` | `weekly_plans.manage_draft` với `ORGANIZATION`/`ALL` | HR xem metadata preview read-only |
| `PUT /api/weekly-plans/google-docs/folder` | `weekly_plans.google_docs.configure` với `ORGANIZATION`/`ALL` | Approve/replace folder |
| `DELETE /api/weekly-plans/google-docs/folder` | `weekly_plans.google_docs.configure` với `ORGANIZATION`/`ALL` | Disable folder, không xóa record/audit |

`WeeklyPlanningPermissionSeeder` cấp permission cấu hình mới chỉ cho `ADMIN` với scope `ALL`. Seeder dùng `syncWithoutDetaching`, không cấp quyền này cho HR_MANAGER hoặc OWNER. Backend kiểm tra permission, không hard-code role trong controller/service.

Khi approve hoặc replace, backend gọi Drive `files.get` bằng transport read-only trước transaction và chỉ ghi registry/audit nếu response trả đúng requested ID với MIME `application/vnd.google-apps.folder`. Credential thiếu, provider lỗi, ID không tồn tại hoặc sai MIME đều fail-closed và không tạo thay đổi database.

## Authorization flow của preview

1. Route yêu cầu `auth:sanctum`, `active_kps_membership`, `weekly_plans.manage_draft` và named throttle.
2. Form Request chỉ nhận `document_url`; `organization_id` và `document_id` bị cấm.
3. Service tự resolve active membership và kiểm tra lại scope defense-in-depth.
4. Registry phải tồn tại và active cho đúng organization.
5. Drive `files.get` dùng fixed endpoint, `drive.metadata.readonly`, `fields=id,mimeType,parents` và giới hạn response.
6. Metadata phải có đúng requested ID, MIME `application/vnd.google-apps.document` và đúng một direct parent là approved folder. Shortcut, nested folder, missing/multiple parent đều bị từ chối.
7. Docs `documents.get` dùng fixed endpoint, `documents.readonly`, `includeTabsContent=true`, TLS, timeout và response size cap.
8. Drive metadata được kiểm tra lại. Nếu document bị di chuyển hoặc identity thay đổi, preview bị hủy với HTTP 409.
9. Response chỉ trả title, revision/snapshot identity, tab hierarchy/order, passage/character counts và source-group metadata có `identity_verified=false`.

Response không trả raw provider payload, passage text, document ID, folder ID, employee mapping hoặc assignee.

## Error mapping an toàn

- ngoài scope, sai MIME, shortcut, nested/missing folder: 404 generic;
- document bị di chuyển giữa hai lần kiểm tra: 409;
- provider rate limit: 429;
- credential/provider permission lỗi: 502/503 generic;
- malformed provider response: 502;
- tài liệu vượt giới hạn: 422;
- request vượt throttle user+organization: 429.

Provider body, prompt/document content, credential và token không được log hay đưa vào exception công khai.

## Triển khai migration

Migration này là incremental migration thứ 15. Database Staging hiện có migration ledger riêng; không chạy lại initial bootstrap hoặc 14 migration cũ. Trước khi áp dụng cần:

1. xác minh đúng TiDB `KPS-Staging`, TLS/hostname, staging username prefix và `kps_migrator`;
2. chạy `migrate:status` bằng environment tạm, xác nhận 14 migration cũ `Ran`, chỉ migration 15 `Pending`;
3. kiểm tra hai bảng chưa tồn tại và có snapshot/backup gần nhất;
4. chạy đúng một migration bằng `--path`, không tự retry khi partial DDL;
5. hậu kiểm tables, unique indexes, FK `RESTRICT`, ledger 15/15 và zero unexpected business-data changes;
6. chạy riêng `WeeklyPlanningPermissionSeeder` theo runbook đã duyệt, rồi xác minh chỉ ADMIN nhận permission cấu hình mới.

Không chạy `StagingBootstrapPlan` Execute trên database đã bootstrap. Việc thêm migration vào manifest chỉ giữ cho bootstrap database rỗng trong tương lai có fingerprint đầy đủ.

### Partial DDL và schema đã tồn tại

Migration chỉ tự tạo schema khi **cả hai bảng đều chưa tồn tại**. Nếu một hoặc cả hai bảng đã tồn tại trong lúc ledger vẫn pending, migration luôn dừng bằng lỗi rõ ràng. Migration không tự suy đoán rằng schema hiện hữu là đầy đủ và không thêm, xóa hoặc sửa cột/constraint tự động; toàn bộ cấu trúc phải được kiểm tra read-only và xử lý qua một forward-fix được review riêng.

| Trạng thái read-only preflight | Hành động |
| --- | --- |
| Cả hai bảng chưa tồn tại | Có thể chạy migration sau khi các preflight khác PASS. |
| Cả hai bảng tồn tại và cấu trúc có vẻ đầy đủ nhưng ledger còn pending | Dừng và đối chiếu nguyên nhân; migration không tự ghi ledger cho schema đã tồn tại. Chuẩn bị recovery/forward-fix được review riêng. |
| Chỉ một bảng tồn tại | Xem là partial DDL; dừng, không retry và không DROP. |
| Bảng tồn tại nhưng thiếu cột/index/FK | Xem là schema không hợp lệ; dừng, không sửa ledger và không tự chạy `ALTER`/`DROP`. |

Khi partial DDL xảy ra:

1. giữ nguyên hiện trạng và thu thập `migrate:status`, `SHOW CREATE TABLE`, `INFORMATION_SCHEMA.STATISTICS`, `KEY_COLUMN_USAGE` và `REFERENTIAL_CONSTRAINTS` bằng truy vấn read-only;
2. xác nhận migration ledger chưa ghi migration 15 là `Ran`;
3. đối chiếu snapshot/backup và xác định DDL statement cuối cùng đã hoàn thành;
4. chuẩn bị forward-fix riêng, được review và phê duyệt cho đúng thành phần thiếu; không sửa migration ledger bằng tay và không xóa bảng có dữ liệu;
5. sau forward-fix, chạy lại toàn bộ schema verification rồi mới tiếp tục deployment.

### Thứ tự permission seeder

1. `AuthorizationSeeder` phải chạy trước để tạo/cập nhật base roles và permissions.
2. `WeeklyPlanningPermissionSeeder` phải chạy sau để bổ sung các extension permissions theo cách additive/idempotent.
3. Không chạy riêng `AuthorizationSeeder` trên môi trường đã kích hoạt Weekly Planning/Google Docs nếu không chạy lại targeted seeders ngay trong cùng runbook đã được duyệt: base seeder dùng permission sync và có thể thu hồi extension grants.
4. Sau seed, xác minh HR_MANAGER và OWNER giữ nguyên ma trận hiện tại, còn chỉ ADMIN có `weekly_plans.google_docs.configure`.

## Các bước trước khi bật Google thật

1. Tạo Google Cloud project/Service Account riêng cho Staging.
2. Enable Docs API và Drive API; chỉ dùng scopes read-only.
3. Share đúng folder synthetic Staging làm Viewer cho Service Account; không dùng dữ liệu nhân sự thật.
4. Mount JSON bằng Render Secret File ngoài repository và cấu hình `GOOGLE_SERVICE_ACCOUNT_CREDENTIALS_PATH`.
5. Chạy migration 15 và permission seeder theo quy trình kiểm soát ở trên.
6. ADMIN cấu hình folder ID bằng internal endpoint; xác minh event audit.
7. Smoke-test một Google Doc giả trực tiếp trong folder, một nested doc và một document ngoài folder.
8. Theo dõi 404/409/429/5xx và throttle nhưng không bật raw provider logging.

Chưa có UI quản trị folder trong giai đoạn này.
