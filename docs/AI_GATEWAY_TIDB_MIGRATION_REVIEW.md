# AI Gateway V1 — TiDB Incremental Migration Review

Tài liệu này chỉ dành cho migration thứ 14:

`2026_10_24_000000_create_ai_gateway_usage_tables.php`

KPS-Staging đã được bootstrap 13 migration và seed dữ liệu giả. Không chạy lại `Invoke-KpsStagingDatabaseBootstrap.ps1 -Mode Execute`, không chạy Seeder và không dùng initial empty-state audit. Thay đổi `StagingBootstrapPlan::EXPECTED_MIGRATIONS` lên 14 chỉ bảo đảm một database staging mới được bootstrap từ source hiện tại có manifest/fingerprint mới; approval fingerprint 13 migration cũ cố ý không còn hợp lệ.

## 1. Kết quả review schema

- `ai_budget_periods.id`, `organizations.id`, `users.id` và các foreign key đều là `BIGINT UNSIGNED` trên MySQL/TiDB.
- Unique `ai_budget_period_environment_provider_unique` bảo đảm một ledger cho mỗi environment/provider/tháng.
- Unique `ai_usage_org_feature_fingerprint_unique` bảo đảm một usage record cho mỗi organization/feature/request fingerprint.
- `reservation_version` ngăn response cũ hoặc stale worker settle một reservation mới dùng lại cùng fingerprint.
- Hai index truy vấn usage có tên tường minh và mọi identifier đều ngắn hơn giới hạn 64 ký tự của MySQL/TiDB.
- Foreign keys từ usage record dùng `ON DELETE RESTRICT`, giữ audit/budget history khi organization, user hoặc budget period còn được tham chiếu.
- `response_payload` là `LONGTEXT`; Laravel mã hóa/decrypt bằng cast `encrypted:array` và `APP_KEY`. Không thay `APP_KEY` nếu chưa có kế hoạch decrypt/re-encrypt hoặc chấp nhận vô hiệu hóa cache cũ.
- `down()` xóa bảng con trước bảng cha. Tuy nhiên rollback sẽ xóa ledger/cache AI và không phải lựa chọn mặc định sau release.

TiDB/MySQL DDL có implicit commit; hai câu `CREATE TABLE` không tạo thành một transaction nguyên tử. Mỗi `CREATE TABLE` là một DDL riêng, nên migration có thể dừng sau khi tạo `ai_budget_periods` nhưng trước khi tạo/ghi nhận `ai_usage_records` và migration ledger.

## 2. Điều kiện bắt buộc

1. Commit/PR chứa đúng migration đã review và CI xanh.
2. Render Staging vẫn để `WEEKLY_PLAN_DOCUMENT_AI_DRIVER=none` và `KPS_AI_DRIVER=fake` trong suốt migration.
3. Có backup/PITR hoặc recovery point của TiDB KPS-Staging theo khả năng gói dịch vụ.
4. Chỉ một one-off runner; không chạy migration từ container startup, CI hay nhiều web instance.
5. Credential phải là `<staging-prefix>.kps_migrator`, lấy từ secret manager/DPAPI ngoài repository. Không dùng root hoặc `kps_runtime`.
6. TLS phải dùng hostname verification và CA đã duyệt. Không đưa password vào command history, source hoặc log.

## 3. Preflight chỉ đọc

Kết nối bằng MySQL client với chế độ nhập password tương tác:

```powershell
mysql --ssl-mode=VERIFY_IDENTITY --ssl-ca=<ca-path> --host=<shared-gateway-host> --port=4000 --user=<staging-prefix>.kps_migrator --password kps_internal
```

Chạy các truy vấn chỉ đọc sau và lưu evidence không chứa credentials/dữ liệu cá nhân:

```sql
SELECT DATABASE() AS database_name,
       CURRENT_USER() AS authenticated_user,
       VERSION() AS version_value,
       TIDB_VERSION() AS tidb_version_value,
       @@hostname AS server_hostname,
       @@foreign_key_checks AS foreign_key_checks;

SHOW GRANTS FOR CURRENT_USER;

SELECT migration, batch
FROM migrations
ORDER BY batch, migration;

SELECT table_name
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('ai_budget_periods', 'ai_usage_records');

SELECT table_name, column_name, column_type
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND ((table_name = 'organizations' AND column_name = 'id')
    OR (table_name = 'users' AND column_name = 'id'));
```

Chỉ tiếp tục khi:

- database là `kps_internal` trên connection identity đã xác minh của instance `KPS-Staging`;
- authenticated user chính xác là `<staging-prefix>.kps_migrator` và khác production prefix;
- `foreign_key_checks = 1`;
- đúng 13 migration cũ đã applied;
- migration thứ 14 chưa có trong bảng `migrations`;
- cả hai bảng AI Gateway chưa tồn tại;
- grants chỉ thuộc `kps_internal.*` và đủ `SELECT/INSERT/UPDATE/DELETE/CREATE/ALTER/DROP/INDEX/REFERENCES`.

Nếu bất kỳ điều kiện nào khác dự kiến, dừng. Không sửa bảng `migrations`, không drop và không chạy migration để “thử”.

## 4. Dry-run source và one-off migration

Trên artifact/commit đã được CI kiểm tra, inject cấu hình Staging và credential từ secret store vào process one-off. Không sửa `backend/.env` local.

Các lệnh read-only trước thao tác:

```powershell
cd backend
php artisan about --only=environment
php artisan migrate:status --database=mysql
```

Đối chiếu output: 13 migration cũ là `Ran`, chỉ file `2026_10_24_000000_create_ai_gateway_usage_tables` là `Pending`.

Sau phê duyệt riêng, lệnh ghi duy nhất là:

```powershell
php artisan migrate --database=mysql --path=database/migrations/2026_10_24_000000_create_ai_gateway_usage_tables.php --force --no-interaction
```

Không thêm `--seed`; không chạy `migrate:fresh`, `migrate:refresh`, `migrate:reset` hoặc bootstrap Execute.

## 5. Hậu kiểm chỉ đọc

```powershell
php artisan migrate:status --database=mysql
```

```sql
SELECT migration, batch
FROM migrations
WHERE migration = '2026_10_24_000000_create_ai_gateway_usage_tables';

SHOW CREATE TABLE ai_budget_periods;
SHOW CREATE TABLE ai_usage_records;

SELECT COUNT(*) AS budget_rows FROM ai_budget_periods;
SELECT COUNT(*) AS usage_rows FROM ai_usage_records;

SELECT table_name, index_name, non_unique,
       GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_in_order
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name IN ('ai_budget_periods', 'ai_usage_records')
GROUP BY table_name, index_name, non_unique
ORDER BY table_name, index_name;

SELECT table_name, constraint_name, referenced_table_name,
       delete_rule, update_rule
FROM information_schema.referential_constraints
WHERE constraint_schema = DATABASE()
  AND table_name = 'ai_usage_records'
ORDER BY constraint_name;
```

Expected: migration 14/14 applied; hai bảng tồn tại và rỗng trước lần gọi AI đầu tiên; unique/index đúng tên; ba foreign key đều hợp lệ với `DELETE RESTRICT`; runtime account có DML nhưng không có DDL.

Chỉ sau hậu kiểm mới cấu hình Weekly Planning provider. `KPS_AI_DRIVER` vẫn giữ `fake`.

## 6. Partial DDL và recovery

Nếu lệnh migration lỗi:

1. Giữ `WEEKLY_PLAN_DOCUMENT_AI_DRIVER=none`; dừng rollout và mọi retry.
2. Không chạy lại migration mù quáng. `up()` không được thiết kế để tự bỏ qua bảng đã tồn tại vì điều đó có thể che giấu schema sai.
3. Thu thập read-only: `migrate:status`, hàng tương ứng trong `migrations`, `information_schema.tables`, `SHOW CREATE TABLE` cho bảng đã tồn tại, index/FK và TiDB DDL job/error nếu có quyền.
4. Nếu không bảng nào tồn tại và migration ledger chưa ghi: sửa nguyên nhân đã xác minh, tạo approval mới và có thể chạy lại cùng migration.
5. Nếu chỉ `ai_budget_periods` tồn tại và ledger chưa ghi: đây là partial DDL. Phương án forward-fix là chuẩn bị một revision của chính migration có bước xác minh đầy đủ schema/index của bảng đã tồn tại, chỉ skip bảng khi mọi thuộc tính khớp, rồi tạo bảng còn thiếu bằng `php artisan migrate --path=...` để Laravel tự ghi migration ledger. Revision làm đổi checksum nên phải chạy lại CI, review và approval trước khi thực thi. Không sửa tay bảng `migrations`.
6. Nếu cả hai bảng tồn tại nhưng ledger chưa ghi, hoặc ledger ghi nhưng schema thiếu/sai: dừng và chuẩn bị recovery migration/command riêng sau khi đối chiếu đầy đủ. Không coi đây là thành công.
7. Chỉ cân nhắc xóa bảng partial khi đã chứng minh chúng rỗng, AI driver chưa từng bật, backup/recovery point có hiệu lực và DBA/release approver phê duyệt câu SQL cụ thể. Không tự động hóa bước xóa.

Nếu application release gặp lỗi sau khi schema đã hoàn chỉnh, rollback code về phiên bản trước và giữ hai bảng vì schema tương thích ngược. Forward-fix là mặc định. Chỉ chạy `migrate:rollback` sau rehearsal riêng, khi migration là migration duy nhất trong batch, hai bảng rỗng và việc mất ledger/cache đã được phê duyệt; TiDB DDL rollback vẫn không nguyên tử.
