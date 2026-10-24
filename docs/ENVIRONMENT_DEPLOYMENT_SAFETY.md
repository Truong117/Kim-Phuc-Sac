# An toàn môi trường và triển khai KPS

Tài liệu này là runbook cấu hình cho frontend React/Vite, backend Laravel, TiDB Cloud, Render, Vercel và kho object S3-compatible. Mục tiêu là giữ development, staging và production tách biệt; không để test hoặc quá trình khởi động ứng dụng tự ghi vào production.

Không ghi credential thật vào repository, ticket, log hoặc ảnh chụp màn hình. Các file `.env` chỉ tồn tại tại máy hoặc secret store của nền tảng; repository chỉ giữ file `*.example` với giá trị giả. `.gitignore` và Docker build context đều loại trừ các biến thể `.env.*` thật, nhưng vẫn phải review diff trước mọi commit.

## Ma trận môi trường

| Môi trường | `APP_ENV` | `KPS_DATABASE_ENVIRONMENT` | Database | Weekly Planning storage | Debug | Mục đích |
| --- | --- | --- | --- | --- | --- | --- |
| Development local | `local` | `development` | SQLite file local mặc định | `local` private | Có thể bật | Phát triển hằng ngày, không dùng dữ liệu thật |
| Automated test/CI | `testing` | `testing` | SQLite `:memory:` | `local`/fake trong test | Tắt | PHPUnit và kiểm thử tự động, không cần secret |
| Staging | `staging` | `staging` | TiDB/MySQL riêng, không dùng chung production | Bucket hoặc prefix private riêng | Tắt | Rehearsal migration và acceptance test |
| Production | `production` | `production` | TiDB production | Bucket private production | Tắt | Dữ liệu vận hành thật |

Các quy tắc bắt buộc:

- Kiểm tra `EnvironmentSafety` luôn được thực thi khi ứng dụng/web worker/Artisan runtime khởi động; không có biến opt-out. `php artisan test` cô lập ngay bootstrap ban đầu sang SQLite `:memory:` trước khi PHPUnit tiếp tục ép cấu hình test. Chỉ `package:discover` được hoãn kiểm tra vì lệnh build-time này không được truy cập dữ liệu ứng dụng.
- `APP_ENV` và `KPS_DATABASE_ENVIRONMENT` phải cùng chỉ một môi trường. Không dùng `APP_ENV=local` với database production.
- Local/development chỉ chấp nhận SQLite hoặc MySQL với `DB_HOST` loopback (`127.0.0.1`, `localhost`, `::1`) và luôn từ chối `DB_URL` không rỗng. Không có cờ opt-in cho remote database. Guard là lớp phòng vệ bổ sung; không dùng production credential hoặc tunnel production qua loopback trên máy lập trình.
- Automated tests luôn dùng `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` và `DB_URL` rỗng. Không truyền secret TiDB vào job CI.
- Staging phải có database, tài khoản database, bucket/prefix và credentials riêng. Không dùng staging để thử nghiệm trên tài nguyên production.
- Marker `KPS_DATABASE_ENVIRONMENT` không thể tự chứng thực database phía sau một hostname/`DB_URL`. Quản trị viên phải đối chiếu Render environment, TiDB project/cluster/database/user và fingerprint target trước migration; guard không thay thế việc kiểm tra này.

## Thiết lập development local an toàn

### Frontend

```bash
npm install
# Chỉ trên clone mới, sau khi xác nhận .env.local chưa tồn tại:
test ! -e .env.local && cp .env.example .env.local
npm run dev
```

Trên PowerShell, dùng `if (-not (Test-Path .env.local)) { Copy-Item .env.example .env.local }`. Nếu file đã tồn tại, không chạy lệnh copy; chuyển thẳng tới mục “Chuyển đổi local một lần” bên dưới và sao lưu an toàn trước khi sửa.

Đặt `VITE_API_BASE_URL=http://localhost:8000`. Chỉ các biến có tiền tố `VITE_` mới được bundle vào frontend, vì vậy tuyệt đối không đặt database password, AWS secret, API key hoặc token vào biến `VITE_*`.

### Backend

```bash
cd backend
composer install
# Chỉ trên clone mới, sau khi xác nhận .env chưa tồn tại:
if [ ! -e .env ]; then
  cp .env.example .env
  php artisan key:generate
fi
```

Trên PowerShell, dùng `if (-not (Test-Path .env)) { Copy-Item .env.example .env; php artisan key:generate }`. Nếu `backend/.env` đã tồn tại, không chạy `cp`, `Copy-Item` hoặc `php artisan key:generate` trước khi có bản sao lưu ngoài repository; làm theo quy trình chuyển đổi một lần ở dưới.

Tạo file SQLite local (chỉ cần thực hiện một lần):

```bash
# macOS/Linux/Git Bash
touch database/database.sqlite

# PowerShell
New-Item -ItemType File -Path database/database.sqlite -Force
```

Trước mọi lệnh có thể ghi dữ liệu, mở `backend/.env` và xác nhận tối thiểu:

```dotenv
APP_ENV=local
KPS_DATABASE_ENVIRONMENT=development
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite
DB_URL=
FILESYSTEM_DISK=local
WEEKLY_PLAN_STORAGE_DISK=local
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
```

Nếu đổi cấu hình đã được cache, chạy `php artisan config:clear`, rồi kiểm tra lại target. Chỉ sau khi chắc chắn `DB_CONNECTION=sqlite` và `DB_DATABASE` là file local mới chạy migration khởi tạo:

```bash
php artisan migrate
php artisan serve --host=localhost --port=8000
```

Không sao chép `DB_URL` hoặc `DB_*` từ Render/TiDB vào `.env` local để chạy test. PHPUnit đã được khóa vào SQLite in-memory; chạy bằng:

```bash
php artisan test
```

### Chuyển đổi local một lần và giữ ổn định khi đổi branch

`/.env.local` và `/backend/.env` là cấu hình của máy lập trình, đã bị Git bỏ qua và không đại diện cho branch. Vì vậy không tạo `.env.feature`, không checkout một file `.env` theo branch và không dùng script tự động chép `.env.staging.example` hoặc `.env.production.example` vào cấu hình local.

Nếu máy đang có `.env` kết nối database từ xa, thực hiện một lần theo thứ tự sau:

1. Dừng Vite, Laravel, queue worker và scheduler local.
2. Sao lưu `backend/.env` và `.env.local` vào kho bí mật hoặc vị trí mã hóa **ngoài repository**. Không in nội dung file ra terminal/log và không thêm bản sao lưu vào Git.
3. Giữ nguyên bản sao lưu cho tới khi đã xác nhận local hoạt động; không ghi đè file hiện tại trước bước này.
4. Đối chiếu với `.env.example`, rồi thay riêng các giá trị database bằng SQLite file hoặc MySQL chạy trên máy. Xóa `DB_URL` remote và giữ storage local; không tạo SSH tunnel tới production dưới một địa chỉ loopback.
5. Đặt frontend local cố định trong `.env.local` với `VITE_API_BASE_URL=http://localhost:8000` và không thêm secret vào bất kỳ biến `VITE_*` nào.
6. Xóa config cache cũ, khởi động lại ứng dụng và xác nhận guard không báo remote database. Chỉ migration database local mới được phép sau khi kiểm tra target.

Sau chuyển đổi, `git switch` không thay hai file ignored này. Nếu một branch cố thêm hoặc track `.env`, dừng merge và xử lý như sự cố secret; không dùng branch đó để “đổi môi trường”.

## Quy trình Git và cổng phát hành

Luồng chuẩn là:

```text
feature/* | fix/* | refactor/*
                -> Pull Request -> staging -> Pull Request được phê duyệt -> main
hotfix/* (từ main) -------------> main -----> đồng bộ ngược bằng PR -> staging
```

Khởi tạo branch `staging` chỉ một lần, từ đúng baseline `origin/main`, sau khi xác nhận working tree sạch:

```bash
git fetch origin
git switch --create staging origin/main
git push --set-upstream origin staging
```

Sau đó mọi chức năng thông thường bắt đầu từ `staging` mới nhất:

```bash
git switch staging
git pull --ff-only origin staging
git switch --create feature/<ten-chuc-nang>
```

Mở Pull Request từ feature branch vào `staging`. CI phải xanh và review phải hoàn tất trước merge. Không push trực tiếp vào `staging` hoặc `main`; cấu hình branch protection trên GitHub cho cả hai branch và yêu cầu các check `Branch policy`, `Frontend`, `Backend`, `Backend container build`. Tắt force-push và yêu cầu branch được cập nhật trước merge.

Khi staging đã được nghiệm thu, mở Pull Request duy nhất từ `staging` vào `main`. CI chạy lại trên PR này. Merge PR là quyết định phát hành production và chỉ người được ủy quyền mới được merge; không tự động merge. Workflow CI không có quyền deploy và không biến một lần test xanh thành phê duyệt phát hành.

### Bootstrap branch `staging` lần đầu

Tại thời điểm runbook này được đưa vào hệ thống, workflow mới có thể chỉ tồn tại trên `feature/kps-environment-safety` và branch `staging` chưa tồn tại. Không merge thẳng feature này vào `main` để “cài CI”. Thực hiện một lần như sau:

1. Tạo `staging` từ đúng `origin/main`, không từ working tree có thay đổi.
2. Push branch feature sau khi được phép, rồi mở bootstrap PR `feature/kps-environment-safety -> staging`.
3. Nếu GitHub chưa chạy workflow mới vì file workflow chưa có trên base branch, review diff và kết quả test local đã ghi nhận, merge duy nhất bootstrap PR này; push/merge commit trên `staging` sau đó phải kích hoạt workflow mới ngay.
4. Chỉ tiếp tục khi cả bốn check trên commit `staging` đều xanh. Sau đó đặt chúng thành required checks và không dùng ngoại lệ bootstrap lần nữa.
5. Cấu hình và nghiệm thu cloud staging trước; chỉ mở PR `staging -> main` sau khi staging chạy đúng commit đó.

Bootstrap này không cấp quyền deploy production, không bỏ qua review và không cho phép branch khác đi thẳng vào `main`.

## Staging

Staging là môi trường triển khai độc lập, không phải chế độ của production database. Merge vào `staging` mới là tín hiệu triển khai staging; feature branch không được dùng backend production và mặc định không tạo authenticated Preview.

Trước khi dùng:

1. Tạo TiDB/MySQL database và database user dành riêng cho staging, với quyền tối thiểu cần thiết.
2. Tạo bucket riêng hoặc prefix riêng được bảo vệ bằng policy, tuyệt đối không trùng namespace production.
3. Tạo Render Staging service theo dõi riêng branch `staging`, bật auto-deploy sau CI nếu gói/tích hợp hỗ trợ, và không chia sẻ environment group chứa production credentials.
4. Cấu hình `APP_ENV=staging`, `KPS_DATABASE_ENVIRONMENT=staging` và `APP_DEBUG=false`; runtime guard là bắt buộc và không có cờ tắt.
5. Cấu hình Vercel staging dùng một hostname/alias ổn định. Scope Preview của riêng branch `staging` phải có `VITE_API_BASE_URL=/`, `VITE_KPS_ENVIRONMENT=staging`, `KPS_BACKEND_ENVIRONMENT=staging`, `KPS_BACKEND_ORIGIN` trỏ Render Staging và `KPS_PRODUCTION_BACKEND_ORIGIN` là origin production chỉ dùng để so sánh (không phải credential).
6. Bật Vercel system environment variables để `VERCEL_ENV` và `VERCEL_GIT_COMMIT_REF` có mặt khi đọc `vercel.mjs`. Repository chỉ cho Git deployment từ `main` và `staging`; config còn fail-closed nếu một Preview khác vượt qua bộ lọc. Nếu project/gói không áp dụng được bộ lọc branch, dùng project staging riêng chỉ theo dõi `staging`. Không đặt production backend làm giá trị Preview mặc định.
7. Cấu hình origin/cookie Sanctum bằng hostname staging chính xác; không dùng wildcard hoặc domain production.
8. Chạy migration rehearsal có kiểm soát theo runbook bên dưới, sau đó chạy smoke test và acceptance test với dữ liệu giả.

Không tạo staging bằng cách clone credentials production vào service mới. Nếu cần dữ liệu gần production, phải có quy trình sao chép và khử định danh được phê duyệt riêng.

### Audit TiDB staging chỉ đọc

Repository có công cụ audit fail-closed cho hai target tách biệt: branch cũ `kps-weekly-planning-staging` và instance độc lập `KPS-Staging`. Công cụ không bootstrap Laravel, không đọc hoặc thay đổi `backend/.env`, không nhận SQL tùy ý và chỉ chạy các truy vấn nhận diện server, metadata schema, migration ledger, quyền tài khoản và `COUNT(*)`.

Instance độc lập phải được cấu hình bằng profile riêng sau khi chọn đúng instance **KPS-Staging** trong hộp thoại **Connect** của TiDB Cloud:

```powershell
.\deployment\Invoke-KpsStagingDatabaseAudit.ps1 -Target IndependentInstance -Configure
.\deployment\Invoke-KpsStagingDatabaseAudit.ps1 -Target IndependentInstance
```

Profile này luôn kết nối database hệ thống `sys` cho preflight và kiểm tra toàn bộ user schema qua `information_schema`; nó không tạo `kps_internal`. Mật khẩu được Windows DPAPI mã hóa cho Windows account hiện tại và lưu tại `%LOCALAPPDATA%\KPS\independent-staging-db-audit.json`, bên ngoài Git. Cấu hình branch cũ vẫn nằm riêng tại `%LOCALAPPDATA%\KPS\staging-db-audit.json` và không bị đọc hoặc ghi đè bởi profile mới. Script từ chối ghi đè cả hai file cấu hình.

TiDB Cloud Starter có thể dùng chung gateway HOST/PORT cho nhiều instance. Mỗi instance có username prefix riêng và username đầy đủ phải có dạng `<instance-prefix>.<account>`. Khi cấu hình, phải mở riêng hộp thoại **Connect** của `KPS-Staging` và `KPS-Internal`, xác nhận đúng hai instance name, rồi nhập full username và prefix hiển thị ở từng nơi. Hai prefix/full username phải hợp lệ, khớp nhau và khác nhau; production password không được yêu cầu hoặc lưu.

Shared gateway được phép và vẫn được fingerprint để phát hiện thay đổi cấu hình. Ranh giới instance không dựa riêng vào username khác nhau: audit còn khóa nguồn metadata là TiDB Cloud Connect dialog, kiểm tra integrity của connection metadata đã lưu, bắt buộc TLS hostname/certificate verification, xác minh `CURRENT_USER()` thực tế trùng full staging username và prefix, kiểm tra TiDB/server fingerprint, đồng thời xác nhận `KPS-Staging` không có user schema hoặc dữ liệu ứng dụng. Ngoại lệ duy nhất là schema mặc định `test` do TiDB cung cấp: audit chỉ cho phép schema này khi nó thực sự có 0 bảng và 0 dòng, trả PASS kèm cảnh báo `empty_default_test_schema_present`, đồng thời vẫn liệt kê tên schema trong evidence.

TiDB SQL không cung cấp nhãn display name `KPS-Staging` của TiDB Cloud Console. Vì vậy instance name được người vận hành đối chiếu tại nguồn Connect; kết nối sau đó được chứng thực bằng TLS, authenticated identity và trạng thái schema/data. Nếu prefix production/staging trùng, metadata thiếu/sai mẫu/không khớp, authenticated identity khác cấu hình, audit thấy user schema khác `test`, hoặc schema `test` có bất kỳ bảng/dòng nào trước bootstrap, kết quả phải FAIL và không được chạy bước ghi. Evidence định danh và evidence schema được báo cáo riêng để không mô tả sai một kết nối FAIL là “empty schema”.

Profile branch cũ vẫn dùng được bằng các lệnh sau, nhưng không phải target triển khai staging mới:

```powershell
.\deployment\Invoke-KpsStagingDatabaseAudit.ps1 -Target LegacyBranch -Configure
.\deployment\Invoke-KpsStagingDatabaseAudit.ps1 -Target LegacyBranch
```

TiDB Cloud branch bắt đầu từ một bản sao dữ liệu của parent tại thời điểm tạo. Không dùng branch cũ làm target staging mới và không sao chép dữ liệu production vào instance độc lập.

### Kế hoạch bootstrap database KPS-Staging sau phê duyệt

Bootstrap được điều khiển bởi script sau; mặc định luôn là `Plan` và không ghi database:

```powershell
# Read-only: chạy lại identity/TLS/empty-state audit và in đủ 13 migration theo thứ tự.
.\deployment\Invoke-KpsStagingDatabaseBootstrap.ps1 -Mode Plan

# Chỉ lưu ba secret mới bằng Windows DPAPI ngoài Git; chưa ghi database.
.\deployment\Invoke-KpsStagingDatabaseBootstrap.ps1 -Mode Plan -ConfigureSecrets

# Chỉ dùng sau một lần phê duyệt riêng. Fingerprint phải lấy từ Plan mới nhất
# và người vận hành còn phải nhập lại confirmation phrase tương tác.
.\deployment\Invoke-KpsStagingDatabaseBootstrap.ps1 -Mode Execute -ApprovalFingerprint <64-hex-plan-fingerprint>

# Read-only hậu kiểm database đã bootstrap; không yêu cầu database còn rỗng.
.\deployment\Invoke-KpsStagingDatabaseBootstrap.ps1 -Mode Status
```

`Plan` tái dùng profile `%LOCALAPPDATA%\KPS\independent-staging-db-audit.json`, bắt buộc live audit PASS, rồi tự kết nối read-only qua đúng đường `bootstrapConnectAndVerify()` mà `Execute` sử dụng. TLS/identity phải đúng, username prefix staging phải khác production, `kps_internal` chưa tồn tại và schema mặc định `test` chỉ được chấp nhận khi không có object. Approval fingerprint v2 khóa các input ổn định gồm connection identity đã xác minh, username prefix, database đích, hash của đúng 13 migration, seeders và fixtures giả. Server hostname vẫn được xác minh và ghi nhận làm evidence nhưng không tham gia approval hash vì node phía sau shared gateway có thể thay đổi. Thay đổi migration hoặc kế hoạch làm fingerprint đổi và bắt buộc review/phê duyệt lại.

`-ConfigureSecrets` tạo file `%LOCALAPPDATA%\KPS\independent-staging-bootstrap-secrets.json`, không ghi đè file có sẵn. File chứa mật khẩu DPAPI của migration operator, Laravel runtime và các tài khoản test giả. Người vận hành phải giữ bản gốc của các mật khẩu này trong secret manager được duyệt; script không có chức năng in/reveal secret.

Hai SQL account được suy ra từ prefix đã xác minh của KPS-Staging:

- `<staging-prefix>.kps_migrator`: chỉ có `SELECT`, `INSERT`, `UPDATE`, `DELETE`, `CREATE`, `ALTER`, `DROP`, `INDEX`, `REFERENCES` trên `kps_internal.*`; không có quyền toàn instance, `CREATE DATABASE`, `CREATE USER` hoặc `GRANT`.
- `<staging-prefix>.kps_runtime`: chỉ có `SELECT`, `INSERT`, `UPDATE`, `DELETE` trên `kps_internal.*`; không có DDL hoặc quyền toàn instance.

Cả hai account bắt buộc TLS. Credential root/audit từ profile DPAPI chỉ dùng cho preflight và bước tạo database/account có phê duyệt; migration và seed chạy bằng migration operator. Render Staging phải dùng runtime account lấy từ secret manager, không dùng root hoặc migration operator.

Seeder chuyên biệt `Database\\Seeders\\StagingSeeder` gọi tuần tự authorization nền, cấu trúc tổ chức, quyền Task Management, AI Dashboard, Weekly Planning và cuối cùng mới tạo năm tài khoản giả dưới reserved domain `kps-staging.example.test`. Seeder bị khóa vào `APP_ENV=staging`, marker database `staging`, driver MySQL/TiDB, database `kps_internal` và cờ one-off do bootstrap cung cấp. Không gọi `DatabaseSeeder` chung để thay thế và không nhập dữ liệu production.

Baseline repository hiện có đúng 13 migration. Quy trình Execute đã chuẩn bị nhưng chỉ được chạy sau phê duyệt riêng và phải giữ các gate sau:

1. Khóa target bằng cùng endpoint/connection/server fingerprints đã PASS; chạy lại preflight ngay trước thao tác và dừng nếu `kps_internal`, user schema khác `test`, hoặc bất kỳ object/dữ liệu nào trong schema `test` đã xuất hiện.
2. Dùng credential provisioning được giữ ngoài repository để tạo duy nhất database `kps_internal` với charset/collation đã review. Không `DROP`, reset hoặc dùng `migrate:fresh`.
3. Tạo hai account tách biệt: migration operator tạm thời có quyền DDL cần thiết và Render Staging runtime account không có `CREATE`, `ALTER`, `DROP`, `GRANT` hoặc quyền toàn instance. Mật khẩu do secret manager sinh và không in vào log.
4. Chạy `php artisan migrate:status` với cấu hình staging tạm thời; chỉ khi thấy đúng 13 migration pending mới chạy `php artisan migrate --force --no-interaction` bằng one-off operation. Sau đó đối chiếu đúng 13 migration applied và schema constraints.
5. Không chạy `DatabaseSeeder` mù quáng. Chỉ chạy `Database\\Seeders\\StagingSeeder` đã được khóa fail-closed vào `APP_ENV=staging`, `KPS_DATABASE_ENVIRONMENT=staging`, driver MySQL/TiDB, database `kps_internal` và cờ one-off. Seeder nhận mật khẩu test từ secret store, chỉ dùng email/dữ liệu giả và gọi tường minh các permission seeders cần thiết.
6. Chỉ chuyển Render Staging sang runtime account sau khi migration/seeding được duyệt hoàn tất. Chạy smoke test authentication, RBAC, Task, Work Report và Weekly Planning với dữ liệu giả; không mở quyền truy cập nếu audit hậu kiểm phát hiện dữ liệu không thuộc fixture staging.

Script mặc định plan-only, yêu cầu fingerprint cùng confirmation phrase tương tác cho chế độ ghi, từ chối nếu empty-state thay đổi và không nằm trong Docker startup, CI hoặc auto-deploy. Do TiDB DDL không thể coi là một transaction nguyên tử, lỗi sau khi bắt đầu `CREATE DATABASE` có thể để lại trạng thái partial; script không tự xóa/rollback. Khi đó phải chạy `-Mode Status`, dừng mọi retry và review trạng thái trước một phương án tiếp tục riêng. Không dùng lại initial empty-state audit để đánh giá database đã bootstrap.

### Xác minh sau mỗi merge vào staging

1. Ghi lại commit SHA đã merge và xác nhận cả Vercel Staging lẫn Render Staging triển khai đúng SHA đó.
2. Xác nhận hostname frontend staging proxy tới hostname backend staging, không tới production.
3. Kiểm tra health, CSRF cookie, login/logout, reload session và ít nhất một request ghi được phép.
4. Chạy acceptance test cho các module bị ảnh hưởng bằng dữ liệu staging giả; không import dữ liệu nhân sự production.
5. Kiểm tra queue worker, private storage và log; không chấp nhận filesystem local ephemeral cho file cần lưu lâu dài.
6. Ghi kết quả, người kiểm tra và các migration đã rehearsal vào checklist phát hành trước khi mở PR `staging -> main`.

## Production

`main` là branch production duy nhất. Vercel Production và Render Production phải dùng project/service, database, storage và secret riêng với staging.

- PR `staging -> main` cần required reviews, CI xanh và xác nhận staging ở đúng commit. Merge PR là phê duyệt phát hành; không cho bot tự merge.
- Vercel Production chỉ nhận `KPS_BACKEND_ORIGIN` production, `KPS_BACKEND_ENVIRONMENT=production`, `VITE_KPS_ENVIRONMENT=production` và `VITE_API_BASE_URL=/` trong scope Production. Không đưa origin production vào Preview mặc định hoặc biến `VITE_*`.
- Render Production theo dõi `main`, nhưng việc deploy phải tuân cổng phê duyệt. Nếu Render không thể đợi required checks hoặc không phối hợp an toàn với migration, tắt auto-deploy production và để release operator triển khai đúng commit SHA sau khi merge.
- Start command không chạy migration. Release có schema change phải hoàn tất preflight/backup và bước migration được duyệt trước khi phiên bản phụ thuộc schema nhận traffic.
- Ưu tiên migration tương thích ngược theo chiến lược expand/contract để frontend và backend không phụ thuộc vào việc hai nền tảng hoàn tất cùng một thời điểm.

Không phát hành trực tiếp từ feature branch, không cấu hình staging service theo `main`, và không dùng chức năng “redeploy latest” khi chưa đối chiếu commit SHA.

## Render và migration có kiểm soát

Container web chỉ khởi động ứng dụng. Start command/Docker `CMD` không được chứa `php artisan migrate`, seeder, `migrate:fresh`, `migrate:refresh`, `migrate:reset` hoặc `db:wipe`.

Automated tests kiểm tra Dockerfile, package/composer scripts, GitHub workflows và các file Render/startup script được lưu trong repository. Chúng không thể đọc Start Command hoặc Pre-Deploy Command được nhập trực tiếp trên Render Dashboard; quản trị viên phải đối chiếu thủ công hai trường này trước mỗi thay đổi cấu hình service.

Migration production phải là bước release riêng do người có thẩm quyền chủ động chạy, tốt nhất bằng Render Pre-Deploy Command hoặc one-off job chỉ chạy một lần. Không cho nhiều web instance đồng thời chạy migration khi restart/autoscale.

### Preflight

1. Xác nhận branch/commit/release cần triển khai và bộ migration đi kèm đã qua CI.
2. Rehearse trên staging có cùng loại/version database và dữ liệu đại diện.
3. Xác nhận backup/PITR đang bật, thời điểm restore gần nhất, retention, cửa sổ triển khai, người chịu trách nhiệm và phương án dừng. Backup chưa từng kiểm tra restore không được coi là đủ.
4. Tại Render, kiểm tra tên service và environment marker; không in credential ra log.
5. Xác nhận `APP_ENV=production`, `KPS_DATABASE_ENVIRONMENT=production`, `APP_DEBUG=false`; nếu marker thiếu/sai, runtime phải từ chối khởi động.
6. Chạy `php artisan migrate:status` để đối chiếu migration repository. Đây là bước đọc; nếu target không đúng hoặc trạng thái khác dự kiến, dừng lại.
7. Review migration pending, foreign keys, index/unique constraints, dung lượng và khả năng partial DDL của TiDB. Không tiếp tục nếu migration không backward-compatible với phiên bản ứng dụng đang chạy.

### Backup và điểm khôi phục

Trước migration production, release operator phải ghi vào biên bản phát hành:

- Commit SHA, danh sách migration pending và schema baseline.
- Backup/PITR checkpoint hoặc timestamp có thể khôi phục, retention và tài khoản chịu trách nhiệm restore.
- Kết quả restore rehearsal gần nhất trên môi trường biệt lập; không restore đè production để kiểm tra.
- RPO/RTO được chấp thuận và tiêu chí quyết định dừng/rollback.
- Snapshot hoặc versioning tương ứng cho private object storage nếu release thay đổi metadata file.

Không sao chép database production sang máy cá nhân. Nếu nhà cung cấp không có backup/PITR đáp ứng yêu cầu, dừng release có migration và báo người quản trị; không dùng SQL dump chứa dữ liệu thật trong repository làm phương án thay thế.

### Thực thi được phê duyệt

Chỉ sau khi preflight đạt và có phê duyệt:

```bash
php artisan migrate --force --no-interaction
```

Lệnh trên chỉ được đặt trong bước pre-deploy/one-off được kiểm soát, không nằm trong startup command. Seeder là thao tác riêng; không tự ghép `db:seed` vào migration.

Nếu pre-deploy bị bỏ qua hoặc thất bại, phải hủy rollout phiên bản phụ thuộc schema mới. Không đưa migration trở lại start command để “bù” cho cấu hình Render thiếu; repository hiện không có `render.yaml`, vì vậy cấu hình Pre-Deploy Command thực tế trên Render phải được kiểm tra thủ công trước release.

### Kiểm tra sau migration

1. Chạy lại `php artisan migrate:status` và đối chiếu đúng migration vừa phê duyệt.
2. Kiểm tra health endpoint, đăng nhập, quyền truy cập và các luồng nghiệp vụ bị ảnh hưởng.
3. Kiểm tra log lỗi mà không xuất payload/credential nhạy cảm.
4. Chỉ tăng traffic/hoàn tất release khi schema và phiên bản ứng dụng tương thích.

Nếu migration lỗi giữa chừng: dừng release, không chạy lại mù quáng và không sửa bảng `migrations` thủ công. Đối chiếu bảng `migrations` cùng `information_schema` ở chế độ read-only, xác định DDL nào đã áp dụng, rồi chuẩn bị forward-fix hoặc rollback được review. Không dùng `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe` hoặc xóa bảng/dữ liệu trên production. `migrate:rollback` cũng không được chạy nếu chưa chứng minh `down()` an toàn với dữ liệu hiện tại.

### Chiến lược rollback

1. Nếu ứng dụng lỗi nhưng schema vẫn tương thích ngược, rollback ứng dụng về commit trước; giữ schema và chuẩn bị forward-fix.
2. Nếu migration đang chạy hoặc TiDB báo partial DDL, dừng rollout và mọi thao tác ghi liên quan; thu thập `migrate:status`, `information_schema` và DDL job ở chế độ read-only.
3. Không chạy `migrate:rollback` chỉ vì deploy thất bại. Chỉ rollback schema khi `down()` đã được rehearsal trên staging, không làm mất dữ liệu và được DBA/người phê duyệt release chấp thuận.
4. Với migration phá hủy hoặc DDL không thể đảo an toàn, restore/PITR hoặc forward-fix phải có kế hoạch riêng. Việc restore production là thao tác sự cố được phê duyệt, không nằm trong CI và không được tự động hóa trong container startup.
5. Sau phục hồi, xác nhận schema ledger, health, authentication, queue và các luồng nghiệp vụ trước khi mở lại traffic. Ghi đầy đủ quyết định và khoảng dữ liệu có thể bị ảnh hưởng.

## Vercel, API và Sanctum

Sanctum SPA hiện tại dùng session cookie và frontend đọc cookie `XSRF-TOKEN` để gửi header `X-XSRF-TOKEN`. Vì `vercel.app` và `onrender.com` là hai site không liên quan, browser không cho JavaScript trên Vercel đọc cookie do Render cấp; `SESSION_DOMAIN` cũng không thể chia sẻ cookie giữa hai public suffix này. Không cấu hình frontend gọi trực tiếp từ `*.vercel.app` tới `*.onrender.com`.

Topology mặc định cho các domain nhà cung cấp là same-origin proxy:

1. Vercel Production/Preview đặt `VITE_API_BASE_URL=/`.
2. `vercel.mjs` đối chiếu `VERCEL_ENV`, `VERCEL_GIT_COMMIT_REF`, các marker frontend/backend và lấy `KPS_BACKEND_ORIGIN` tại build time. Sau đó nó rewrite `/api/:path*`, `/sanctum/:path*` tới đúng backend trước SPA fallback. Cả hai đường dẫn tắt rewrite caching để response theo session không bị CDN cache. Branch/marker sai, biến thiếu, URL có credential/path/query, hoặc HTTP không phải loopback sẽ làm cấu hình fail-closed.
3. Production đặt `KPS_BACKEND_ORIGIN` tới Render production. Preview của `staging` đặt origin Render staging và khai báo thêm `KPS_PRODUCTION_BACKEND_ORIGIN` để guard từ chối hai origin giống nhau. Giá trị so sánh này không chứng thực danh tính backend; quản trị viên vẫn phải kiểm tra service/commit/database target trên dashboard.
4. Laravel đặt `FRONTEND_URL` là origin frontend ổn định, `SANCTUM_STATEFUL_DOMAINS` là hostname tương ứng (không có scheme), `SESSION_DOMAIN=` để cookie host-only tại proxy, `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, `SESSION_SAME_SITE=lax`, `SESSION_PARTITIONED_COOKIE=false`.
5. CORS chỉ cho phép origin frontend chính xác và credentials; không dùng wildcard.

Sanctum cần biết chính xác frontend stateful. Vì vậy authenticated Preview phải dùng một domain/alias staging ổn định. Không wildcard toàn bộ `*.vercel.app`; preview URL ngẫu nhiên chỉ dùng được sau khi hostname cụ thể được phê duyệt và cấu hình đồng bộ ở backend, hoặc chỉ dùng kiểm tra UI không đăng nhập. Preview tuyệt đối không proxy tới production.

Phương án dài hạn thay thế là hai custom subdomain cùng site, ví dụ `app.kimphucsac.example` và `api.kimphucsac.example`. Khi đó frontend có thể gọi API trực tiếp, dùng `SESSION_DOMAIN=.kimphucsac.example`, cookie Secure/SameSite Lax, CORS và stateful domain chính xác. Không hạ bảo mật cookie và không chuyển sang lưu bearer token ở frontend để né topology.

Sau khi thay domain/proxy, phải smoke-test trên browser thật: nhận `XSRF-TOKEN`, login, reload phiên, một request ghi có CSRF, logout, 401/419, và xác nhận Preview không truy cập production. Build thành công chưa đủ để xác nhận authentication.

## Private object storage S3-compatible

Development có thể dùng disk `local`, đặt dưới `storage/app/private` và không serve trực tiếp. Staging/production phải dùng object storage bền vững, private:

```dotenv
FILESYSTEM_DISK=s3
WEEKLY_PLAN_STORAGE_DISK=s3
AWS_ACCESS_KEY_ID=<secret-store>
AWS_SECRET_ACCESS_KEY=<secret-store>
AWS_SESSION_TOKEN=
AWS_DEFAULT_REGION=<region>
AWS_BUCKET=<private-bucket>
AWS_ENDPOINT=<s3-compatible-endpoint-if-needed>
AWS_USE_PATH_STYLE_ENDPOINT=false
AWS_ROOT_PREFIX=kps/production
AWS_URL=
```

Đối với staging, dùng bucket hoặc `AWS_ROOT_PREFIX` khác production. Bucket phải chặn public access, không dùng public-read ACL và giới hạn IAM theo bucket/prefix cần thiết. Giữ `AWS_URL` rỗng nếu không có CDN private được thiết kế riêng.

Trước production phải smoke-test upload/read/delete trên chính provider S3-compatible của staging. Flysystem gửi visibility private khi ghi; một số bucket bật chế độ vô hiệu hóa ACL có thể từ chối request nếu provider không tương thích. Không bỏ privacy để chữa lỗi này: xác nhận policy/ACL mode của provider, quyền IAM tối thiểu, mã hóa/retention và namespace staging riêng trước khi bật.

Frontend không nhận storage credentials hoặc filesystem path. API không trả permanent public URL cho Word/Excel nội bộ. Nếu bổ sung download sau này, backend phải kiểm tra authentication, active membership, permission và organization scope trước khi stream file hoặc tạo URL tạm thời có thời hạn ngắn.

### File local đã tồn tại

Đổi `WEEKLY_PLAN_STORAGE_DISK` chỉ áp dụng cho file mới; nó không tự di chuyển file cũ. File đang nằm trên filesystem local/ephemeral cần kế hoạch riêng, không thực hiện trong deployment cấu hình này:

1. Inventory read-only metadata `disk/path` và đối chiếu file tồn tại; không xuất nội dung nhân sự.
2. Backup, rồi copy từng object sang bucket private bằng key không đoán được.
3. Xác minh kích thước/checksum và thử đọc qua luồng backend được phân quyền.
4. Chỉ repoint metadata của các bản ghi đã xác minh, theo batch có audit và khả năng tiếp tục an toàn.
5. Trong giai đoạn chuyển tiếp, ứng dụng phải đọc đúng disk đã lưu trên từng bản ghi; không giả định mọi file ở default disk mới.
6. Giữ bản gốc theo thời hạn retention đã duyệt; không xóa file local trước khi hoàn tất đối chiếu và rollback window.

Quy trình copy/verify/repoint này cần rehearsal staging và phê duyệt riêng vì có ghi dữ liệu; không được chạy tự động khi container khởi động.

## Hotfix production và đồng bộ ngược

Hotfix bắt đầu từ `origin/main`, không từ một feature branch hoặc từ staging đang có thay đổi chưa phát hành:

```bash
git fetch origin
git switch --create hotfix/<ten-loi> origin/main
```

1. Giữ thay đổi tối thiểu, thêm regression test và mở PR `hotfix/* -> main`.
2. CI và required review vẫn bắt buộc. Trường hợp khẩn cấp có thể rút ngắn số người duyệt theo chính sách công ty, nhưng không bỏ kiểm thử hoặc push thẳng vào `main`.
3. Rehearse trên staging hoặc môi trường cô lập với đúng commit hotfix nếu thời gian cho phép. Nếu staging chứa thay đổi chưa phát hành, không coi việc test HEAD của staging là bằng chứng cho hotfix production.
4. Sau khi merge/deploy main, mở PR đồng bộ cùng thay đổi từ `main` hoặc hotfix branch vào `staging`. Giải quyết conflict bằng review; không force-push, reset hoặc thay thế lịch sử staging.
5. Nếu hotfix có migration, áp dụng đầy đủ preflight, backup và rollback ở trên; nhãn “hotfix” không cho phép migration chạy trong startup.

## CI

GitHub Actions phải hoạt động mà không cần production secrets:

- Workflow chạy trên Pull Request vào `staging` hoặc `main`, chạy lại sau push/merge vào hai branch này, và cho phép chạy thủ công. Push feature branch đơn thuần không deploy và không thay thế PR checks.
- Job `Branch policy` chỉ chấp nhận `feature/*`, `fix/*`, `refactor/*`, `hotfix/*` vào `staging`; PR vào `main` phải đến từ `staging` hoặc một `hotfix/*` được phê duyệt.
- Frontend: `npm ci`, `npm test`, `npm run lint`, `npm run build`.
- Backend: `composer install`, `composer validate --strict`, `vendor/bin/pint --test`, `php artisan test`.
- Container: build `backend/Dockerfile` không push và không chạy container; bước này xác minh PHP extensions cùng production dependencies.
- Backend test sử dụng SQLite `:memory:`, array cache/session và sync queue.
- Workflow dùng `contents: read`, không giữ Git credential sau checkout, không nhận production secret, không chạy migration/seeder trên TiDB, không upload dữ liệu nội bộ và không deploy.

Nếu CI từng yêu cầu TiDB credentials để pass, coi đó là lỗi cấu hình test và dừng; không thêm production secret để chữa tạm.

GitHub branch protection là cấu hình quản trị bắt buộc nhưng nằm ngoài repository. Bật required status checks và required reviews cho `staging`/`main`; CI YAML tự nó không ngăn quản trị viên push trực tiếp hoặc merge khi check chưa xanh.

## Checklist cấu hình thủ công

### Render staging/production

- [ ] Database/bucket/service của staging và production tách biệt.
- [ ] Render Staging theo dõi `staging`; Render Production theo dõi `main`. Không service nào theo dõi wildcard feature branch.
- [ ] Auto-deploy staging chỉ chạy sau commit đã merge/qua CI. Production chỉ deploy sau cổng phê duyệt; tắt auto-deploy nếu không thể đảm bảo điều này.
- [ ] `APP_ENV`, `KPS_DATABASE_ENVIRONMENT`, `APP_DEBUG` đúng môi trường.
- [ ] Local/development không có `DB_URL`, chỉ dùng SQLite hoặc MySQL loopback; không dùng production credential hay tunnel production qua loopback.
- [ ] `DB_*`/`DB_URL` và TLS CA trỏ đúng target, được lưu trong secret store.
- [ ] `FILESYSTEM_DISK=s3`, `WEEKLY_PLAN_STORAGE_DISK=s3`; bucket private và prefix đúng môi trường.
- [ ] Session/cache/queue driver và worker được cấu hình; không dùng local ephemeral cho dữ liệu cần bền vững.
- [ ] Start command không chạy migration/seeder.
- [ ] Migration là pre-deploy/one-off có phê duyệt và chỉ một runner; nếu bước này fail/bị bỏ qua thì rollout bị hủy.
- [ ] `APP_URL`, `FRONTEND_URL`, Sanctum/CORS/cookie khớp domain thật.

### Vercel

- [ ] Production branch là `main`; authenticated Preview chỉ dành cho `staging`. Bật Vercel system variables `VERCEL_ENV`/`VERCEL_GIT_COMMIT_REF`; feature Preview bị bỏ qua và không có production backend access.
- [ ] Production/Preview staging đặt `VITE_API_BASE_URL=/`; Development Vite dùng `http://localhost:8000` từ `.env.local`.
- [ ] Các marker `VITE_KPS_ENVIRONMENT` và `KPS_BACKEND_ENVIRONMENT` khớp `staging`/`production`; `KPS_BACKEND_ORIGIN` được khai báo riêng theo scope.
- [ ] Preview branch `staging` có `KPS_PRODUCTION_BACKEND_ORIGIN` để đối chiếu và trỏ `KPS_BACKEND_ORIGIN` tới Render Staging. Không có production origin ở Preview mặc định ngoài biến so sánh không bí mật này.
- [ ] `/api` và `/sanctum` được proxy trước SPA fallback; thiếu upstream phải làm build fail.
- [ ] Authenticated staging/Preview dùng frontend hostname ổn định đã có trong `SANCTUM_STATEFUL_DOMAINS`.
- [ ] Không có API/storage/database secret mang tiền tố `VITE_`.
- [ ] Login/CSRF/cookie đã được kiểm tra trên domain triển khai thật.

## Thứ tự đưa staging vào hoạt động

Các bước dưới đây do quản trị viên nền tảng thực hiện thủ công; repository và CI không tạo tài nguyên cloud:

1. Tạo TiDB/MySQL staging độc lập, user riêng và backup/PITR theo khả năng nhà cung cấp. Không clone credential production.
2. Tạo private object storage staging, bucket hoặc prefix riêng, least-privilege credentials và lifecycle/retention phù hợp.
3. Tạo branch `staging` từ đúng baseline `origin/main` sau khi review, rồi push branch này một lần để dashboard cloud có thể chọn đúng target.
4. Bật branch protection/required checks cho `staging` và `main` trước khi nhận PR tính năng đầu tiên.
5. Tạo Render Staging service từ repository, root `backend/`, branch `staging`; nhập biến từ `backend/.env.staging.example` qua secret store và đối chiếu marker staging.
6. Tạo hoặc cấu hình Vercel Staging cho branch `staging`; đặt hostname ổn định, bật system environment variables, rồi cấu hình đầy đủ marker/origin theo `.env.staging.example` trong Preview scope chỉ dành cho `staging`.
7. Cấu hình Render Staging `FRONTEND_URL`, CORS, Sanctum và cookie khớp đúng hostname Vercel Staging.
8. Chạy migration staging bằng một pre-deploy/one-off operation được phê duyệt; không đưa lệnh vào Docker CMD hay GitHub Actions.
9. Merge một PR không có dữ liệu thật vào staging, theo dõi deploy, rồi hoàn tất smoke test authentication, queue và private storage.
10. Chỉ sau khi staging ổn định mới cho phép PR `staging -> main`; xác minh lại toàn bộ production target trước lần phát hành đầu tiên.

Chi phí có thể phát sinh từ Render Staging service/worker, TiDB staging cluster hoặc database, object storage/request/egress, stable/custom domain và các tính năng Vercel như branch-specific environment hay deployment protection. Giá phụ thuộc nhà cung cấp và gói tại thời điểm tạo; quản trị viên phải duyệt ngân sách trước. Nếu gói hiện tại không hỗ trợ cô lập branch/environment, dùng project/service staging riêng hoặc dừng rollout—không chia sẻ production credentials để tiết kiệm cấu hình.

## Các thao tác tuyệt đối không thực hiện trên production

- Không chạy automated test, fixture, factory hoặc demo import trên database production.
- Không chạy `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`, truncate hoặc xóa dữ liệu để sửa deployment.
- Không chạy seeder nếu chưa có runbook riêng và phê duyệt về tác động của cơ chế sync quyền.
- Không để migration/seeder trong Docker startup, web start command hoặc CI.
- Không dùng local `.env` để kết nối TiDB production cho công việc phát triển.
- Không dùng bucket public hoặc trả URL/file path nội bộ không qua authorization.
- Không chia sẻ credential qua source code, frontend bundle, log, tài liệu hoặc chat.
- Không rollback production theo phản xạ; dừng, thu thập bằng chứng read-only và review phương án forward-fix/rollback trước.

## Điều kiện trước khi triển khai production

1. CI xanh và regression tests chạy hoàn toàn bằng SQLite in-memory.
2. Environment guard bắt buộc đã pass trên staging; không tồn tại cờ runtime để bỏ qua.
3. Migration đã rehearsal trên staging tương thích TiDB; backup/PITR và runbook sự cố đã được xác nhận.
4. Object storage private đã được cấu hình, kiểm thử quyền và retention; kế hoạch file cũ được phê duyệt riêng nếu cần.
5. Domain, Vercel API target, Sanctum, CORS và cookie đã qua smoke test staging.
6. Không còn startup migration hoặc CI deployment ngầm.
7. Release có người phê duyệt, người vận hành và người xác nhận sau triển khai.
