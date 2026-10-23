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
- `KPS_ALLOW_REMOTE_DATABASE=false` là mặc định. Chỉ đặt `true` khi development thực sự cần database từ xa; khi đó bắt buộc khai báo `KPS_EXPECTED_DATABASE_HOST` và `KPS_EXPECTED_DATABASE_NAME` đúng target đã duyệt. Guard đối chiếu cả target lấy từ `DB_URL`, vì vậy đổi connection name thành `sqlite` không thể che một URL remote. Các marker này là tripwire, không thay thế việc dùng account/service tách biệt và review target bởi con người.
- Automated tests luôn dùng `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` và `DB_URL` rỗng. Không truyền secret TiDB vào job CI.
- Staging phải có database, tài khoản database, bucket/prefix và credentials riêng. Không dùng staging để thử nghiệm trên tài nguyên production.

## Thiết lập development local an toàn

### Frontend

```bash
npm install
cp .env.example .env.local
npm run dev
```

Đặt `VITE_API_BASE_URL=http://localhost:8000`. Chỉ các biến có tiền tố `VITE_` mới được bundle vào frontend, vì vậy tuyệt đối không đặt database password, AWS secret, API key hoặc token vào biến `VITE_*`.

### Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

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
KPS_ALLOW_REMOTE_DATABASE=false
KPS_EXPECTED_DATABASE_HOST=
KPS_EXPECTED_DATABASE_NAME=
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

## Staging

Staging là môi trường triển khai độc lập, không phải chế độ của production database. Trước khi dùng:

1. Tạo TiDB/MySQL database và database user dành riêng cho staging, với quyền tối thiểu cần thiết.
2. Tạo bucket riêng hoặc prefix riêng được bảo vệ bằng policy, tuyệt đối không trùng namespace production.
3. Cấu hình `APP_ENV=staging`, `KPS_DATABASE_ENVIRONMENT=staging` và `APP_DEBUG=false`; runtime guard là bắt buộc và không có cờ tắt.
4. Cấu hình Vercel staging dùng `VITE_API_BASE_URL=/` và `KPS_BACKEND_ORIGIN` trỏ đúng backend staging riêng.
5. Cấu hình các origin/cookie Sanctum cho domain staging; không dùng domain production.
6. Chạy migration rehearsal có kiểm soát theo runbook bên dưới, sau đó chạy smoke test và acceptance test với dữ liệu giả.

Không tạo staging bằng cách clone credentials production vào service mới. Nếu cần dữ liệu gần production, phải có quy trình sao chép và khử định danh được phê duyệt riêng.

## Render và migration có kiểm soát

Container web chỉ khởi động ứng dụng. Start command/Docker `CMD` không được chứa `php artisan migrate`, seeder, `migrate:fresh`, `migrate:refresh`, `migrate:reset` hoặc `db:wipe`.

Migration production phải là bước release riêng do người có thẩm quyền chủ động chạy, tốt nhất bằng Render Pre-Deploy Command hoặc one-off job chỉ chạy một lần. Không cho nhiều web instance đồng thời chạy migration khi restart/autoscale.

### Preflight

1. Xác nhận branch/commit/release cần triển khai và bộ migration đi kèm đã qua CI.
2. Rehearse trên staging có cùng loại/version database và dữ liệu đại diện.
3. Xác nhận backup/PITR, cửa sổ triển khai, người chịu trách nhiệm và phương án dừng.
4. Tại Render, kiểm tra tên service và environment marker; không in credential ra log.
5. Xác nhận `APP_ENV=production`, `KPS_DATABASE_ENVIRONMENT=production`, `APP_DEBUG=false`; nếu marker thiếu/sai, runtime phải từ chối khởi động.
6. Chạy `php artisan migrate:status` để đối chiếu migration repository. Đây là bước đọc; nếu target không đúng hoặc trạng thái khác dự kiến, dừng lại.
7. Review migration pending, foreign keys, index/unique constraints, dung lượng và khả năng partial DDL của TiDB. Không tiếp tục nếu migration không backward-compatible với phiên bản ứng dụng đang chạy.

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

## Vercel, API và Sanctum

Sanctum SPA hiện tại dùng session cookie và frontend đọc cookie `XSRF-TOKEN` để gửi header `X-XSRF-TOKEN`. Vì `vercel.app` và `onrender.com` là hai site không liên quan, browser không cho JavaScript trên Vercel đọc cookie do Render cấp; `SESSION_DOMAIN` cũng không thể chia sẻ cookie giữa hai public suffix này. Không cấu hình frontend gọi trực tiếp từ `*.vercel.app` tới `*.onrender.com`.

Topology mặc định cho các domain nhà cung cấp là same-origin proxy:

1. Vercel Production/Preview đặt `VITE_API_BASE_URL=/`.
2. `vercel.mjs` lấy `KPS_BACKEND_ORIGIN` tại build time và rewrite `/api/:path*`, `/sanctum/:path*` tới đúng backend trước SPA fallback. Cả hai đường dẫn tắt rewrite caching để response theo session không bị CDN cache. Biến thiếu, URL có credential/path/query, hoặc HTTP không phải loopback sẽ làm cấu hình fail-closed.
3. Production đặt `KPS_BACKEND_ORIGIN` tới Render production; Preview chỉ được đặt tới Render staging cô lập. Không dùng chung một giá trị production cho mọi deployment.
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

## CI

GitHub Actions phải hoạt động mà không cần production secrets:

- Frontend: `npm ci`, `npm test`, `npm run lint`, `npm run build`.
- Backend: `composer install`, `composer validate --strict`, `vendor/bin/pint --test`, `php artisan test`.
- Container: build `backend/Dockerfile` không push và không chạy container; bước này xác minh PHP extensions cùng production dependencies.
- Backend test sử dụng SQLite `:memory:`, array cache/session và sync queue.
- Workflow không chạy migration/seeder trên TiDB, không upload dữ liệu nội bộ và không deploy.

Nếu CI từng yêu cầu TiDB credentials để pass, coi đó là lỗi cấu hình test và dừng; không thêm production secret để chữa tạm.

## Checklist cấu hình thủ công

### Render staging/production

- [ ] Database/bucket/service của staging và production tách biệt.
- [ ] `APP_ENV`, `KPS_DATABASE_ENVIRONMENT`, `APP_DEBUG` đúng môi trường.
- [ ] `KPS_ALLOW_REMOTE_DATABASE=false`.
- [ ] Development remote (nếu được phê duyệt riêng) phải khớp exact `KPS_EXPECTED_DATABASE_HOST` và `KPS_EXPECTED_DATABASE_NAME`; không dùng các biến này để hợp thức hóa production target.
- [ ] `DB_*`/`DB_URL` và TLS CA trỏ đúng target, được lưu trong secret store.
- [ ] `FILESYSTEM_DISK=s3`, `WEEKLY_PLAN_STORAGE_DISK=s3`; bucket private và prefix đúng môi trường.
- [ ] Session/cache/queue driver và worker được cấu hình; không dùng local ephemeral cho dữ liệu cần bền vững.
- [ ] Start command không chạy migration/seeder.
- [ ] Migration là pre-deploy/one-off có phê duyệt và chỉ một runner; nếu bước này fail/bị bỏ qua thì rollout bị hủy.
- [ ] `APP_URL`, `FRONTEND_URL`, Sanctum/CORS/cookie khớp domain thật.

### Vercel

- [ ] Production/Preview đặt `VITE_API_BASE_URL=/`; Development Vite có thể dùng `http://localhost:8000`.
- [ ] `KPS_BACKEND_ORIGIN` được khai báo riêng theo Vercel environment; Preview trỏ staging, không trỏ production.
- [ ] `/api` và `/sanctum` được proxy trước SPA fallback; thiếu upstream phải làm build fail.
- [ ] Authenticated staging/Preview dùng frontend hostname ổn định đã có trong `SANCTUM_STATEFUL_DOMAINS`.
- [ ] Không có API/storage/database secret mang tiền tố `VITE_`.
- [ ] Login/CSRF/cookie đã được kiểm tra trên domain triển khai thật.

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
