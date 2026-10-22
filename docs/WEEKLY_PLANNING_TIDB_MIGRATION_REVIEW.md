# Weekly Planning V1 — TiDB Migration Preflight

Tài liệu này là runbook **chỉ đọc** trước khi triển khai Weekly Planning V1. Không dùng các câu lệnh trong tài liệu để tự động sửa schema, thêm bản ghi vào `migrations`, hoặc xóa dữ liệu. Mọi DDL khắc phục phải được viết thành migration riêng, rehearsal trên staging và được phê duyệt trước.

## 1. Thứ tự migration bắt buộc

Laravel phải áp dụng đúng thứ tự:

1. `2026_10_22_000000_create_tasks_table`
2. `2026_10_22_010000_create_weekly_planning_tables`
3. `2026_10_22_010001_add_weekly_plan_source_to_tasks_table`

Trạng thái đã xác nhận trước đợt review này: migration Task đã chạy ở batch 5; hai migration Weekly Planning chưa chạy. Không chạy `010001` trước `010000`, vì khóa ngoại `tasks.weekly_plan_entry_id` phụ thuộc `weekly_plan_entries.id`.

Rollback đúng thứ tự ngược lại:

1. `010001`: bỏ foreign key, bỏ unique index, rồi bỏ cột nguồn trên `tasks`.
2. `010000`: bỏ sáu bảng Weekly Planning theo dependency order ngược.

Không rollback riêng `010000` khi foreign key từ `tasks` vẫn còn.

### Kết luận review schema `010000`

- Sáu bảng được tạo parent-first và bị gỡ child-first; không phát hiện dependency đảo chiều.
- Mọi khóa chính dùng Laravel `id()` và mọi khóa ngoại dùng `foreignId()`/`unsignedBigInteger`, tương ứng `BIGINT UNSIGNED` trên MySQL/TiDB.
- Unique `(organization_id, week_start)` và `(weekly_plan_id, assignee_id)` có tên tường minh, ngắn hơn giới hạn identifier của MySQL/TiDB.
- Các actor lịch sử (`submitted_by`, `approved_by`, `published_by`, `reviewed_by`, `decided_by`, `actor_id`) dùng `SET NULL`; chủ thể bắt buộc dùng `RESTRICT`; dữ liệu con của plan/entry dùng `CASCADE` theo workflow hiện tại.
- Source Task là nullable, unique và `RESTRICT`: nhiều Task cũ có thể cùng mang `NULL`, nhưng một Weekly Plan Entry chỉ phát hành tối đa một Task và entry đã phát hành không thể bị xóa.
- Schema cho phép lưu đồng thời `weekly_plan_id` và `weekly_plan_entry_id` trên comment/suggestion nhưng không có composite FK buộc entry thuộc đúng plan. Service hiện tại luôn scope entry qua plan; đây là giới hạn integrity cần tiếp tục được test ở tầng ứng dụng, không phải thay đổi migration trong đợt hardening này.
- Không có API/service xóa toàn bộ Weekly Plan. Các lệnh xóa hiện tại chỉ áp dụng cho entry/review/suggestion trong workflow bản nháp. Vì vậy `weekly_plan_events ON DELETE CASCADE` chưa tạo nguy cơ audit retention thực tế. Nếu bổ sung xóa plan sau này, retention policy và FK của event phải được phê duyệt lại trước khi mở endpoint.

## 2. Preflight TiDB chỉ đọc

Chạy các truy vấn sau bằng tài khoản chỉ đọc hoặc trong phiên kiểm tra metadata. Lưu toàn bộ kết quả vào biên bản triển khai.

### Phiên bản và foreign key enforcement

```sql
SELECT VERSION() AS mysql_compatible_version;
SELECT TIDB_VERSION() AS tidb_version;
SHOW VARIABLES LIKE 'foreign_key_checks';
```

Điều kiện bắt buộc: phiên bản TiDB đang vận hành phải hỗ trợ foreign key; `foreign_key_checks` phải là `ON` trong phiên triển khai. Nếu hàm `TIDB_VERSION()` không khả dụng do quyền, dừng và xác minh phiên bản trong TiDB Cloud Console. Không tiếp tục nếu foreign key bị tắt hoặc DDL foreign key bị đánh dấu không hợp lệ.

### Đối chiếu migration ledger

```sql
SELECT migration, batch
FROM migrations
WHERE migration IN (
  '2026_10_22_000000_create_tasks_table',
  '2026_10_22_010000_create_weekly_planning_tables',
  '2026_10_22_010001_add_weekly_plan_source_to_tasks_table'
)
ORDER BY migration;
```

Kỳ vọng trước lần triển khai đầu tiên: có `000000` ở batch 5; không có `010000` và `010001`. Nếu ledger khác trạng thái vật lý của schema, dừng; không chèn hoặc xóa thủ công bản ghi `migrations`.

### Kiểm tra bảng và cột hiện có

```sql
SELECT table_name
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN (
    'tasks',
    'weekly_plans',
    'weekly_plan_entries',
    'weekly_plan_employee_reviews',
    'weekly_plan_comments',
    'weekly_plan_suggestions',
    'weekly_plan_events'
  )
ORDER BY table_name;

SELECT table_name, column_name, column_type, is_nullable, column_default
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND (
    (table_name = 'tasks' AND column_name IN ('id', 'weekly_plan_entry_id'))
    OR (table_name = 'weekly_plan_entries' AND column_name = 'id')
  )
ORDER BY table_name, ordinal_position;

SHOW CREATE TABLE tasks;
```

Kỳ vọng trước migration: `tasks` tồn tại; sáu bảng Weekly Planning và `tasks.weekly_plan_entry_id` chưa tồn tại. Các Task hiện tại không cần backfill; cột mới là nullable và mọi bản ghi hiện hữu phải nhận `NULL`.

### Kiểm tra index và foreign key nguồn trên Task

```sql
SELECT index_name, non_unique, seq_in_index, column_name
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'tasks'
  AND (index_name = 'tasks_weekly_plan_entry_unique' OR column_name = 'weekly_plan_entry_id')
ORDER BY index_name, seq_in_index;

SELECT constraint_name, table_name, column_name,
       referenced_table_name, referenced_column_name
FROM information_schema.key_column_usage
WHERE table_schema = DATABASE()
  AND table_name = 'tasks'
  AND (constraint_name = 'tasks_weekly_plan_entry_id_foreign'
       OR column_name = 'weekly_plan_entry_id');

SELECT constraint_name, delete_rule, update_rule
FROM information_schema.referential_constraints
WHERE constraint_schema = DATABASE()
  AND table_name = 'tasks'
  AND constraint_name = 'tasks_weekly_plan_entry_id_foreign';
```

Kỳ vọng trước migration: không có các metadata trên. Kỳ vọng sau migration: unique index có `non_unique = 0`; foreign key trỏ tới `weekly_plan_entries(id)` và `delete_rule = RESTRICT`.

### Kiểm tra kiểu khóa ngoại của các bảng cha

```sql
SELECT table_name, column_name, column_type, is_nullable
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND column_name = 'id'
  AND table_name IN ('organizations', 'users', 'departments', 'locations', 'tasks')
ORDER BY table_name;
```

Tất cả `id` cha phải tương thích với Laravel `foreignId`/`unsignedBigInteger` (`BIGINT UNSIGNED`). Dừng nếu phát hiện signed/unsigned hoặc kiểu dữ liệu không khớp.

### Kiểm tra partition và DDL đang chạy

```sql
SELECT table_name, partition_name
FROM information_schema.partitions
WHERE table_schema = DATABASE()
  AND table_name IN ('tasks', 'organizations', 'users', 'departments', 'locations')
  AND partition_name IS NOT NULL;

ADMIN SHOW DDL JOBS 20;
```

Không triển khai đồng thời với DDL khác trên các bảng liên quan. Nếu tài khoản không có quyền xem DDL jobs, cần xác nhận qua TiDB Cloud Console hoặc DBA trước khi tiếp tục.

## 3. Kiểm tra sau migration

Sau khi migration trên staging hoàn tất, chạy lại toàn bộ SQL ở mục 2 và bổ sung:

```sql
SHOW CREATE TABLE weekly_plans;
SHOW CREATE TABLE weekly_plan_entries;
SHOW CREATE TABLE weekly_plan_employee_reviews;
SHOW CREATE TABLE weekly_plan_comments;
SHOW CREATE TABLE weekly_plan_suggestions;
SHOW CREATE TABLE weekly_plan_events;
SHOW CREATE TABLE tasks;

SELECT migration, batch
FROM migrations
WHERE migration LIKE '2026_10_22_01%'
ORDER BY migration;

SELECT COUNT(*) AS existing_tasks,
       SUM(weekly_plan_entry_id IS NOT NULL) AS linked_weekly_tasks
FROM tasks;
```

Xác nhận:

- Cả sáu bảng Weekly Planning tồn tại với đầy đủ index/FK.
- Không có `/* FOREIGN KEY INVALID */` trong `SHOW CREATE TABLE`.
- `tasks.weekly_plan_entry_id` là `BIGINT UNSIGNED NULL`.
- Tên unique index là `tasks_weekly_plan_entry_unique`.
- Tên foreign key là `tasks_weekly_plan_entry_id_foreign`, `ON DELETE RESTRICT`.
- Hai migration Weekly Planning có cùng batch triển khai dự kiến.
- Số lượng Task cũ không giảm; trước khi publish Weekly Plan, `linked_weekly_tasks` phải là 0.

## 4. Partial DDL và cách dừng an toàn

TiDB/MySQL DDL có implicit commit; một file migration gồm nhiều câu DDL không phải một transaction nguyên tử. `010000` có thể tạo được một phần trong sáu bảng, và `010001` có thể thêm được cột nhưng chưa tạo xong index/FK.

| Trạng thái quan sát | Hành động an toàn |
| --- | --- |
| Không có bảng Weekly Planning, ledger chưa có `010000` | Có thể bắt đầu sau khi toàn bộ preflight đạt. |
| Chỉ có một tiền tố bảng của `010000`, ledger chưa có `010000` | Dừng. Ghi lại `SHOW CREATE TABLE` của từng bảng và DDL job. Không chạy lại migration, không drop bảng. Lập migration khắc phục tiếp tục từ trạng thái thực tế trên staging trước. |
| Đủ sáu bảng nhưng ledger thiếu `010000` | Dừng. So sánh toàn bộ schema với source; không tự chèn ledger. Chỉ sửa ledger hoặc dùng migration đánh dấu sau phê duyệt của DBA và khi schema khớp tuyệt đối. |
| Có cột `tasks.weekly_plan_entry_id` nhưng thiếu unique và FK | Dừng. Không rerun `010001`. Kiểm tra duplicate/orphan bằng các truy vấn bên dưới; chuẩn bị migration khắc phục chỉ thêm constraint còn thiếu. |
| Có unique nhưng thiếu FK, hoặc FK sai action | Dừng. Ghi metadata; kiểm tra dữ liệu; tạo migration sửa constraint trên staging. Không drop index/column thủ công trong production. |
| Ledger có `010001` nhưng schema thiếu cột/index/FK | Dừng triển khai ứng dụng. Đây là ledger/schema drift; DBA phải phê duyệt migration khắc phục. |
| `SHOW CREATE TABLE` báo foreign key invalid hoặc `foreign_key_checks = OFF` | Dừng. Không coi migration là thành công và không chạy Seeder. Xác minh phiên bản/cấu hình TiDB trước. |

Các truy vấn chẩn đoán dữ liệu dưới đây chỉ đọc:

```sql
SELECT weekly_plan_entry_id, COUNT(*) AS task_count
FROM tasks
WHERE weekly_plan_entry_id IS NOT NULL
GROUP BY weekly_plan_entry_id
HAVING COUNT(*) > 1;

SELECT t.id AS task_id, t.weekly_plan_entry_id
FROM tasks AS t
LEFT JOIN weekly_plan_entries AS e ON e.id = t.weekly_plan_entry_id
WHERE t.weekly_plan_entry_id IS NOT NULL
  AND e.id IS NULL;
```

Nếu một bước thất bại, giữ nguyên dữ liệu, chụp metadata và log lỗi. Không dùng `migrate:fresh`, không rollback hàng loạt, không drop bảng/cột, và không chạy Seeder cho đến khi schema đã được đối chiếu hoàn chỉnh.

## 5. Đánh giá việc tách migration `010000`

Tách thành sáu migration sẽ giúp ledger ghi nhận từng bảng và giảm phạm vi partial DDL của từng file. Tuy nhiên, việc này cần đổi tên/timestamp toàn bộ chuỗi migration, cập nhật thứ tự phụ thuộc và tạo thêm churn ngay trước rehearsal; nó không làm từng câu DDL của TiDB trở thành transaction nguyên tử.

Hiện tại `010000` chưa chạy trên TiDB, sáu bảng thuộc cùng một bounded workflow, thứ tự `up()`/`down()` đã đúng và không có dữ liệu phải chuyển đổi. Vì vậy review này **giữ nguyên một migration**, dùng preflight, staging rehearsal và runbook partial-state làm rào chắn. Nếu tổ chức yêu cầu khả năng resume theo từng bảng trong migration ledger, quyết định tách phải được thực hiện trước rehearsal đầu tiên và phải thay toàn bộ bộ migration/test cùng lúc.

## 6. Điều kiện bắt buộc trước production

- Backup/snapshot và kế hoạch khôi phục đã được xác nhận ngoài ứng dụng.
- Rehearsal thành công trên staging cùng major/minor TiDB và cấu hình foreign key tương đương production.
- Migration ledger khớp schema vật lý; Task migration vẫn là batch 5 theo baseline đã xác nhận.
- Không có DDL job xung đột; tài khoản triển khai có đủ quyền `CREATE`, `ALTER`, `INDEX`, `REFERENCES` cần thiết.
- `foreign_key_checks = ON`, kiểu tất cả khóa cha là `BIGINT UNSIGNED` tương thích.
- Có maintenance window và người chịu trách nhiệm dừng khi gặp partial DDL.
- Backend code tương ứng được sẵn sàng nhưng chưa nhận traffic tạo Weekly Plan trong lúc migration chạy.
- Chỉ chạy `WeeklyPlanningPermissionSeeder` sau khi cả hai migration, schema verification và application smoke test đều thành công, đồng thời đã xác nhận đủ các role OWNER, ADMIN và HR_MANAGER; seeder không thuộc bước schema migration.
- Không dùng dữ liệu production để thử nghiệm rollback. Rollback schema chỉ rehearsal trên database staging biệt lập.

