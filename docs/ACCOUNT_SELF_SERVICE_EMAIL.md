# Hồ sơ cá nhân và email đăng nhập tự phục vụ

Nhánh: `feature/account-settings-self-service-email`.
Base: `62914c6ff4a7624c31554d2981cd58bc9e890ff8` (`origin/staging` đã fetch và xác minh).
Tái sử dụng có chọn lọc từ `2b3660debc0144273f60f8635c9b279b9a06eaca`; không cherry-pick cả commit.

## Hành vi

- `/profile` sửa tên hiển thị và xem thông tin tổ chức chỉ đọc. Không còn trường email.
- `/account` hiển thị email đăng nhập, cập nhật email và đổi mật khẩu. Không còn lặp lại tên.
- Hai trang có điều hướng chung, dùng các primitive, bản dịch tiếng Việt, dark mode và responsive hiện có.
- Không thêm trường hồ sơ mà backend chưa hỗ trợ, cảnh báo rời trang, gửi mail, token, API pending hoặc bảng pending.

## API

`PATCH /api/account/email`, trong nhóm Sanctum + active KPS membership + CSRF hiện có.

| Trường             | Ý nghĩa                                                                   |
| ------------------ | ------------------------------------------------------------------------- |
| `email`            | Bắt buộc, trim + lowercase, email RFC, tối đa 255 ký tự                   |
| `current_password` | Bắt buộc; kiểm tra lại trên user đã khóa trong transaction                |
| `expected_email`   | Tùy chọn; UI luôn gửi email lúc mở form để phát hiện cập nhật từ tab khác |

Endpoint lấy user từ session và chỉ truyền các trường được chọn vào service. Trường định danh người khác, quyền hoặc thông tin tổ chức bị từ chối. Không thay đổi ID, password, role, membership hoặc permissions.

Email được kiểm tra trùng không phân biệt hoa thường. Unique index hiện có là chốt cuối cùng; chỉ lỗi chắc chắn thuộc `users_email_unique` (SQLSTATE `23000`, driver code `1062`) hoặc SQLite `users.email` được đổi thành validation error. So sánh no-op chuẩn hóa cả email trong DB, nên `User@example.com` và `user@example.com` giữ nguyên địa chỉ lưu, verification và không sinh audit. Mật khẩu và `expected_email` luôn được kiểm tra trước no-op.

Response thành công là payload `AuthUser` hiện có; frontend cập nhật context và xóa mật khẩu khỏi form. Giữ nguyên session. Lần đăng nhập sau dùng email mới.

- `200`: cập nhật thành công hoặc cùng địa chỉ hiện tại.
- `422`: email sai/trùng, sai mật khẩu hoặc trường không được phép.
- `409`: `expected_email` đã cũ; tải lại trang trước khi sửa tiếp.
- `429`: giới hạn 3 lần/phút và 12 lần/giờ trên user.
- `401`/`419`: authentication, membership hoặc CSRF hiện có không hợp lệ.
- `503` và `Retry-After: 1`: deadlock/serialization failure, lock timeout hoặc TiDB write conflict đã rollback. Không tự động retry.

Audit bắt buộc `account.email_changed` nằm trong bảng `account_audit_events` đã được người dùng duyệt, cùng transaction và connection với thay đổi email. Hai bản ghi cùng commit hoặc cùng rollback. Nếu không lưu được audit thì email rollback và API báo lỗi; không âm thầm bỏ audit. Không còn callback file logger sau COMMIT, nên `LOG_LEVEL=warning` và logger ghi file bị lỗi không làm mất audit hay biến một cập nhật đã commit thành phản hồi thất bại. Không cần outbox/export trong phạm vi này.

Bảng chỉ có `id`, `event_type`, `actor_user_id`, `subject_user_id`, `previous_email`, `email`, `created_at`; không nhận metadata/request body tùy ý, password/hash/token. Giữ nguyên historical user ID khi user bị xóa, không cascade audit và không thêm API truy cập audit. Email là dữ liệu cá nhân; quyền đọc bảng, backup và thời hạn lưu giữ cần theo chính sách vận hành.

Transaction email dùng begin/commit/rollback tường minh để dọn đúng transaction và callbacks nếu PDO ném lỗi tại COMMIT. Lỗi callback sau commit không được đổi thành validation error. Không retry mù quáng. Lỗi mất kết nối lúc COMMIT có thể có kết quả chưa xác định; giữ nguyên lỗi, không đổi thành `422` và không tự replay. Client/operator cần đọc lại trạng thái tài khoản trước khi quyết định thử lại.

Đổi mật khẩu kiểm tra lại password trên bản ghi khóa và refresh instance user của guard trước khi Sanctum lưu session password hash. Không truy cập bảng pending. Giới hạn riêng: 6 lần/phút. Profile hỗ trợ `expected_name` để trả `409` khi form đã cũ.

## Database và kiểm thử

Migration mới: `2026_10_31_000001_create_account_audit_events_table.php`, chỉ tạo bảng audit, không backfill hay sửa user. `down()` từ chối xóa bảng có lịch sử audit. Đợt kiểm thử local chỉ dùng SQLite cô lập; Execute Staging là bước được phê duyệt riêng, có kết quả bên dưới.

Manifest bootstrap có đủ 22 migrations và fingerprint mới. Allowlist PowerShell/PHP, plan và inspector đã được mở rộng riêng cho migration 22; các điều kiện của migration 16–21 được giữ nguyên. M22 bắt buộc ledger đúng 1–21, schema tiền nhiệm hoàn chỉnh và bảng audit chưa tồn tại; postflight phải xác minh ledger 22, schema/index/không-FK, bảng mới rỗng và baseline nghiệp vụ giữ nguyên. Không thay đổi logging level Production, authentication hoặc session config.

Ngày 31/10/2026, sau phê duyệt riêng, M22 đã được áp dụng thành công trên instance độc lập `KPS-Staging`, database ứng dụng `kps_internal`, qua incremental runner chính thức. Status/Plan mới, identity/TLS/grants/prefix guards và hai preflight trước Execute đều PASS. Runner chạy đúng M22 một lần, exit code 0, không retry hoặc rollback; không chạy migration khác và không tác động Production.

Postflight tích hợp và postflight độc lập đều PASS: ledger chính xác M1–M22, không pending/ngoài manifest; bảng audit đủ bảy cột, primary key, composite index, không FK và rỗng ban đầu. Baseline inspector giữ nguyên một Weekly Plan, 19 entries, fingerprint ID và các row counts nghiệp vụ. Đây là kiểm tra schema trong phạm vi inspector M22, không phải kiểm toán toàn bộ M1–M15. Chưa triển khai ứng dụng; merge/deploy vẫn cần phê duyệt riêng. Các môi trường chưa có M22 phải tuân thủ `docs/KPS_STAGING_INCREMENTAL_MIGRATION_RUNNER.md`, không giả định ledger/schema đã đồng bộ.

PHPUnit ép SQLite `:memory:` và kiểm tra `EnvironmentSafety` trước `RefreshDatabase`. Browser tests dùng harness sẵn có, chỉ mở database SQLite trong `backend/storage/framework/testing/weekly-plan-v3-e2e`, kiểm tra cấu hình/đường dẫn/fixture identity trước sử dụng và dọn fixture sau khi chạy. Mailer của harness là `array`.

Các lệnh kiểm tra:

| Kiểm tra                    | Kết quả                                                       |
| --------------------------- | ------------------------------------------------------------- |
| PHPUnit toàn bộ             | PASS — 719 tests, 6.588 assertions                             |
| Targeted account + migration | PASS — 130 tests, 879 assertions                              |
| PowerShell incremental runner | PASS — 26 tests, không kết nối database                     |
| SQLite incremental 1–21 → 22 | PASS — giữ nguyên dữ liệu cũ, ledger/index/FK/rollback         |
| MySQL DDL compilation       | PASS — test biên dịch SQL, PDO không được mở kết nối           |
| M22 trên KPS-Staging        | PASS — Execute, postflight tích hợp và độc lập; ledger 21 → 22 |
| Node tests toàn bộ          | PASS — 249 tests                                              |
| ESLint                      | PASS — 0 lỗi, 4 cảnh báo Fast Refresh ở các context không sửa |
| TypeScript và Vite build    | PASS — còn cảnh báo bundle lớn hiện có                        |
| Laravel Pint toàn bộ        | PASS                                                          |
| Browser E2E                 | PASS — 2 tests trên fixture SQLite local                      |
| MySQL/TiDB nhiều connection | Chưa chạy; không kết nối database triển khai                  |

Các kết quả frontend/Browser E2E trong bảng thuộc đợt triển khai Account Settings trước đó. Đợt hoàn thiện migration chỉ sửa runner, kiểm thử backend/PowerShell và tài liệu, nên không chạy lại frontend. PHPUnit, Pint và `git diff --check` đã chạy lại trong đợt migration readiness.

```text
npm test
npm run lint
node node_modules/typescript/bin/tsc -b
npm run build
cd backend
php vendor/bin/phpunit
php vendor/bin/pint --test
```

Browser test từ root:

```text
npm run test:e2e -- account-self-service-email.spec.ts
```

Coverage mới gồm validation/password/duplicate, cập nhật trực tiếp, no-op với địa chỉ DB có chữ hoa, audit cùng transaction/rollback, logger thật dùng cấu hình Production và sink hỏng, lỗi audit storage, SQL unique collision, PDO fault injection tại COMMIT, không replay sau commit/mất ACK, form cũ, hai tài khoản tranh một email, chống sửa người khác/nâng quyền, 10 vai trò, navigation/membership, CSRF/throttle phút và giờ, session và đăng nhập lại. Browser tests kiểm tra profile, lỗi form, payload, cập nhật email, reload, đổi mật khẩu, đăng nhập email mới và hai tab gửi cạnh tranh (`200` + `409`), kèm mobile/dark mode.

Giới hạn: browser harness dùng PHP server một worker và SQLite. Kiểm thử hai tab cùng gửi và SQL collision có tính xác định không thay thế stress test nhiều connection trên MySQL/TiDB. Không kết nối database triển khai để kiểm chứng collation/index/dữ liệu thực tế. Không triển khai cơ chế cảnh báo thay đổi chưa lưu hoặc tuyên bố hỗ trợ Back/Forward.

PDO fault injection kiểm tra đường lỗi COMMIT thật của Laravel trên SQLite; không phải bằng chứng chạy MySQL/TiDB thực tế. Throttle chuẩn hiện tại vẫn là best-effort khi nhiều worker cùng kiểm tra counter; test 12 lần/giờ xác minh hành vi tuần tự và reset cửa sổ, chưa xác minh trần tuyệt đối khi burst đồng thời.

**Đánh giá sau security fixes: READY FOR PR.** Release vẫn cần migration audit được áp dụng qua quy trình được phê duyệt; không gộp việc tạo PR với cho phép merge/deploy.

## Kiểm thử thủ công khi review

1. Dùng tài khoản thử nghiệm vào Profile: sửa tên; email và các quyền không có form sửa tại đây.
2. Vào Account: thử email sai/trùng và mật khẩu sai; email hiện tại phải giữ nguyên.
3. Đổi sang email hợp lệ: header/Account cập nhật, reload vẫn đăng nhập và quyền điều hướng giữ nguyên.
4. Mở Account trong hai tab trước khi sửa: lưu tab đầu, tab sau phải báo xung đột khi lưu bản cũ.
5. Đổi mật khẩu, đăng xuất và đăng nhập bằng email mới/mật khẩu mới. Email cũ phải thất bại.

Thay đổi ứng dụng được đưa vào review qua nhánh feature; không gộp phê duyệt PR với cho phép merge hoặc deploy.

## Hoàn thiện migration readiness

**MIGRATION READY ở phạm vi repository và kiểm thử cô lập.** Đợt chuẩn bị runner không sửa ledger, không kết nối Staging/Production và không sửa nội dung 22 migration. Sau đó M22 đã được Execute riêng trên KPS-Staging theo phê duyệt, với kết quả postflight nêu ở trên.

Nguyên nhân M22 chưa được chấp nhận: bootstrap manifest đã có 22 mục, nhưng incremental allowlist PowerShell/PHP, plan và schema inspector chỉ hỗ trợ đến M21. Đã bổ sung riêng M22 cùng preflight/postflight chính xác, giữ nguyên identity/TLS/grants/receipt/concurrency guards. Cả 22 file được đối chiếu theo thứ tự manifest và thực thi 1–21 rồi 22 trên SQLite `:memory:`; không thiếu hoặc trùng bước.

11 file thay đổi trong đợt migration readiness (các thay đổi Account Settings trước đó được giữ nguyên):

- `backend/app/Support/StagingIncrementalMigrationPlan.php`
- `backend/scripts/inspect-staging-incremental-migration.php`
- `backend/scripts/run-staging-incremental-migration.php`
- `deployment/Invoke-KpsStagingIncrementalMigration.ps1`
- `backend/tests/Feature/AccountAuditMigrationTest.php` (mới)
- `backend/tests/Unit/StagingIncrementalMigrationInspectorTest.php`
- `backend/tests/Unit/StagingIncrementalMigrationLauncherTest.php`
- `backend/tests/Unit/StagingIncrementalMigrationPlanTest.php`
- `deployment/tests/Invoke-KpsStagingIncrementalMigration.Tests.ps1`
- `docs/KPS_STAGING_INCREMENTAL_MIGRATION_RUNNER.md`
- `docs/ACCOUNT_SELF_SERVICE_EMAIL.md` (tệp này)

## File thay đổi

Backend:

- `backend/app/Http/Controllers/AccountController.php`
- `backend/app/Http/Requests/Account/UpdateEmailRequest.php` (mới)
- `backend/app/Http/Requests/Account/UpdateProfileRequest.php`
- `backend/app/Models/User.php`
- `backend/app/Services/AccountService.php`
- `backend/app/Services/AccountEmailTransaction.php` (mới)
- `backend/database/migrations/2026_10_31_000001_create_account_audit_events_table.php` (mới, đã duyệt)
- `backend/app/Support/StagingBootstrapPlan.php`
- `backend/routes/api.php`
- `backend/tests/Feature/AccountEmailUpdateTest.php` (mới)
- `backend/tests/Feature/AccountProfileTest.php`
- `backend/tests/Feature/AccountEmailCommitTest.php` (mới)
- `backend/tests/Support/CommitFaultPdo.php` (mới)
- `backend/tests/Unit/StagingBootstrapPlanTest.php`
- `backend/tests/Unit/StagingIncrementalMigrationPlanTest.php`

Frontend:

- `src/components/account/AccountEmailForm.tsx` (mới)
- `src/components/account/AccountEmailSection.tsx` (mới)
- `src/components/account/AccountNavigation.tsx` (mới)
- `src/components/account/AccountPasswordSection.tsx`
- `src/components/account/AccountSummary.tsx`
- `src/components/profile/ProfilePersonalInfo.tsx`
- `src/pages/Account/Account.tsx`
- `src/pages/Profile/Profile.tsx`
- `src/services/accountService.ts`
- `src/locales/vi/common.json`

Kiểm thử và bàn giao:

- `tests/account-self-service-email.test.mjs` (mới)
- `tests/profile-account-locale.test.mjs`
- `e2e/account-self-service-email.spec.ts` (mới)
- `docs/ACCOUNT_SELF_SERVICE_EMAIL.md` (tệp này, mới)
