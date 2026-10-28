# Google Docs Durable Source V1

## Phạm vi

PR 2 bổ sung luồng `Preview -> Confirm -> Re-authorize -> Re-read -> Snapshot check -> Persist` cho Weekly Planning. Confirm chỉ tạo một Weekly Plan mới cho kỳ kế tiếp. Hệ thống không attach/replace nguồn của plan đã tồn tại, không tạo Entry/Task, không map tên tab sang nhân viên và không gọi AI.

## API

`POST /api/weekly-plans/google-docs/import`

Middleware: `auth:sanctum`, `active_kps_membership`, `weekly_plans.manage_draft` với organization scope và throttle Google Docs hiện hữu.

Request allowlist:

```json
{
  "document_url": "https://docs.google.com/document/d/.../edit",
  "expected_snapshot_sha256": "64-lowercase-hex",
  "request_id": "uuid"
}
```

Server không nhận `organization_id`, `user_id`, `document_id`, passages hoặc assignee. Organization luôn lấy từ active membership. Backend tự tách document reference từ URL cố định, kiểm tra direct parent/MIME trước và sau khi đọc, rồi tự dựng passages.

Success trả `201` với `WeeklyPlanResource`. Retry cùng `request_id` và cùng payload trả cùng plan, không đọc Google lần nữa. Dùng lại `request_id` cho payload khác trả `409 GOOGLE_DOCS_REQUEST_CONFLICT`.

Idempotent replay chỉ trả durable plan đã lưu khi actor vẫn có active KPS membership, vẫn có `weekly_plans.manage_draft`, cùng organization, và request ID/payload khớp. Replay đi qua scoped plan authorization của response, không gọi Google và không ghi thêm dữ liệu. Việc approved folder bị disable hoặc thay thế chỉ chặn import mới; nó không tự động thu hồi quyền xem/replay plan durable đã lưu. Permission bị thu hồi, membership inactive hoặc cross-organization luôn fail-closed trước khi trả plan.

Các lỗi fail-closed chính:

- snapshot khác preview: `409 GOOGLE_DOCS_SNAPSHOT_CHANGED`, không ghi DB;
- plan cùng organization/week đã tồn tại: `409 WEEKLY_PLAN_ALREADY_EXISTS`, không attach/replace;
- folder authorization thay đổi trong lúc đọc: `409 GOOGLE_DOCS_AUTHORIZATION_CHANGED`;
- snapshot/provenance không hợp lệ: `422 GOOGLE_DOCS_INVALID_SNAPSHOT`;
- provider/folder errors giữ safe mapping của Preview V1.

## Persistence và bảo vệ dữ liệu

`weekly_plans.source_type` phân biệt `UPLOADED_FILE` và `GOOGLE_DOCS`. Dữ liệu cũ nhận default `UPLOADED_FILE`.

`weekly_plan_google_docs_sources` lưu một snapshot source duy nhất trên mỗi plan:

- document reference, title, revision và tab manifest dùng Laravel encrypted casts;
- không lưu raw Google URL hoặc provider response;
- document/folder hashes phục vụ đối chiếu nhưng không thể dùng để gọi Google;
- approved folder được tham chiếu bằng registry row và fingerprint tại thời điểm capture;
- `request_id` + organization là idempotency boundary;
- snapshot, counts, captured/verified/stale timestamps được lưu rõ ràng.

Normalized passage text vẫn nằm trong `weekly_plan_source_passages` giống DOCX hiện hữu. Đây là dữ liệu nội bộ, được bảo vệ bằng Sanctum/RBAC/organization scope, database access controls và encryption-at-rest của nền tảng, nhưng **không được application-encrypt trong PR 2** vì AI/source-apply hiện cần query/deserialize trực tiếp và thay đổi đó sẽ tác động lớn tới DOCX/AI Gateway. Backup vì vậy chứa passage text; quyền backup phải giới hạn cho operator được phê duyệt.

Một phần passage metadata phục vụ provenance (bao gồm tab title, source group/context đã allowlist) cũng đang lưu plaintext cùng passage. Chỉ Google document ID, document title, revision ID và tab manifest được application-encrypt. Staging chỉ được dùng tài liệu/dữ liệu giả; Production cần phê duyệt riêng về phân loại dữ liệu, retention, backup access và deletion trước khi bật nguồn Google Docs.

Không có API xóa Weekly Plan/source trong V1. Nếu sau này bổ sung xóa, source/passages/events hiện cascade theo plan và cần retention approval riêng. Thay `APP_KEY` mà không có kế hoạch re-encrypt sẽ làm mất khả năng đọc metadata đã mã hóa; phải backup key và có key-rotation runbook trước khi đổi.

Authorized readers là người có `weekly_plans.view` trong organization của plan; người confirm cần `weekly_plans.manage_draft`. Resource không trả document ID, folder ID, URL, request hash hoặc ciphertext.

## Transaction và concurrency

Google Drive/Docs calls hoàn tất trước khi mở transaction. Trong transaction hệ thống:

1. đọc lại active membership, organization và `weekly_plans.manage_draft` trực tiếp từ database, không dùng authorization cache;
2. kiểm tra idempotency row dưới lock;
3. khóa active folder registry row và xác nhận vẫn trùng pre/post authorization;
4. kiểm tra organization/week;
5. tạo plan, encrypted source, passages và audit events;
6. commit toàn bộ hoặc rollback toàn bộ.

Unique constraints `weekly_plans_org_week_unique`, `weekly_google_sources_plan_unique` và `weekly_google_sources_org_request_unique` là lớp bảo vệ race cuối cùng. Query exception chỉ được chuyển thành idempotent response/409 khi row tương ứng thực sự tồn tại; lỗi DB khác được giữ nguyên.

## Migration 16 — TiDB runbook

Migration: `2026_10_27_000000_add_google_docs_source_to_weekly_plans.php`.

Migration này gồm hai DDL độc lập trên TiDB: thêm `weekly_plans.source_type`, sau đó tạo bảng source. DDL không được coi là transaction nguyên tử. Migration cố ý fail-closed nếu phát hiện column hoặc table đã tồn tại; nó không tự sửa partial schema.

Preflight bắt buộc:

1. operator xác minh đúng instance Staging, database `kps_internal`, TLS, staging migrator và backup/snapshot có thể restore;
2. source commit đã review, working tree sạch, không có config cache sai environment;
3. migration ledger có 15 migration `Ran`, migration 16 `Pending`;
4. `weekly_plans.source_type` và `weekly_plan_google_docs_sources` đều chưa tồn tại;
5. không có DDL job/migration runner khác;
6. operator phê duyệt database write riêng.

Chạy duy nhất:

```bash
php artisan migrate --database=mysql --path=database/migrations/2026_10_27_000000_add_google_docs_source_to_weekly_plans.php --force --no-interaction
```

Không chạy bootstrap, seeder, `migrate:fresh`, reset, refresh hoặc tự retry.

Post-check:

- ledger 16/16, đúng một row migration 16;
- existing plans đều `source_type=UPLOADED_FILE`;
- source table đủ columns, hai unique indexes, organization/document index và bốn foreign keys;
- FK plan `CASCADE`; organization/folder/actor `RESTRICT`;
- bảng source có 0 row ngay sau migration;
- không có thay đổi business data ngoài default column.

Rollback chỉ chạy khi source table rỗng **và** không có `weekly_plans.source_type=GOOGLE_DOCS`. Migration từ chối cả source row lẫn orphan Google plan để tránh xóa metadata/schema rồi để lại plan mồ côi về ngữ nghĩa. Nếu lỗi sau DDL đầu tiên, dừng ngay. Thu thập read-only ledger/columns/tables/indexes/FKs/DDL jobs; không drop column/table, không sửa ledger và không retry. Chuẩn bị forward-fix riêng sau review hoặc restore snapshot theo quyết định operator.

## AI extraction boundary

Tại thời điểm PR 2, `source_document.status=EXTRACTED` chỉ có nghĩa passages đã được trích xuất từ Google Docs và AI task extraction còn bị chặn. Phiên bản hiện tại đã bổ sung lát cắt tiếp theo: AI chỉ nhận passages thuộc tab `VERIFIED`, mapping HR là nguồn assignee duy nhất, `NOT_APPLICABLE` bị loại, và Apply All được kiểm tra lại nguyên tử phía server. AI extraction của nguồn DOCX không thay đổi.

## Source-key semantics — quyết định cần có trước PR 3

- Một passage văn bản có thể chứa nhiều đầu việc về mặt nội dung; Reader không khẳng định quan hệ một-passage-một-task.
- Database pivot cho phép một passage được nhiều entries tham chiếu, vì primary key chỉ chặn trùng cặp `(entry_id, passage_id)`.
- `weekly_plan_source_passages` chặn trùng `source_key` trong cùng plan/source snapshot.
- Validation hiện tại `tasks.*.source_keys.* = distinct` và `entries.*.source_passage_ids.* = distinct` có thể áp dụng distinct toàn wildcard, nên cùng provenance xuất hiện ở hai task/entry sẽ bị từ chối trước DB.

PR 2 không đổi semantics này. Trước PR 3 cần phê duyệt việc chuyển distinct thành “unique trong từng task/entry” và tách chống duplicate task sang fingerprint nghiệp vụ riêng (normalized title/content/result/assignee/date), thay vì dùng provenance uniqueness như duplicate-task control.

## Giới hạn còn lại

- Không attach hoặc replace source của draft hiện hữu, kể cả draft rỗng.
- Không refresh snapshot hoặc đánh dấu stale tự động.
- Không Google AI extraction/batching/queue.
- Không tự xác minh employee identity; `identity_verified` luôn `false`.
