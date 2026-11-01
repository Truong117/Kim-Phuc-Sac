# CI/CD Kim-Phuc-Sac theo giai đoạn

## Phạm vi và tình trạng

Luồng Git: local → `feature/*` / `fix/*` / `refactor/*` → PR `staging` → PR `main`.
Hotfix vào `main` dùng `hotfix/*`; bot mở PR đưa thay đổi về `staging`, không merge
hoặc xử lý conflict. Release `staging → main` nên dùng merge commit để giữ ancestry.

Giai đoạn 1 tự động triển khai **Staging khi database đã sẵn sàng**. Các migration
hiện hữu vẫn yêu cầu vận hành thủ công; pipeline dừng nếu còn pending, schema drift,
DDL khác đang chạy, thiếu quyền hoặc không xác minh được identity. Không có lệnh
DDL, seeder, database rollback hoặc Production deploy tự động trong giai đoạn này.
Không được hiểu bước `migration_gate` là đã thực thi migration.

| Giai đoạn | Nội dung | Điều kiện chuyển tiếp |
| --- | --- | --- |
| 1 | CI dùng lại; DB/schema gate; backend → frontend → smoke; khóa; evidence; PR | Cấu hình secrets và auto-deploy controls, merge PR tính năng, một lượt Staging thực tế PASS |
| 2 | Chỉ tự chạy migration additive đã review riêng | Executor độc lập được review, hash allowlist, tương thích bản cũ, backup/restore, pre/postflight và khóa chung; chưa có executor này |
| 3 | Production phát hành thủ công có phê duyệt và kiểm tra lại CI | Có baseline/restore được review, secrets trong Environment bảo vệ, Vercel Git auto-deploy Off và Staging rehearsal PASS; xem [hướng dẫn kích hoạt](KPS_PRODUCTION_CD_ACTIVATION.md) |

Đối chiếu ngày 01/11/2026 (Asia/Saigon):

| Nguồn | SHA / trạng thái đã đọc từ nền tảng |
| --- | --- |
| `origin/main` | `ac5b4ae1d1110a80a0b477cda6de398e6f720615` |
| `origin/staging` | `8f25b086c08c4d664ad5cb926324e8332c2ea496`, 143 commit ahead / 0 behind main |
| Render Staging | `dep-dbisqrgu01pc73drskbg`, live, SHA `8f25b086…`, Free, 1 instance, auto-deploy off |
| Render Production | `dep-dbdc9obm8hqs73e5je90`, live, SHA `ac5b4ae1…`, Free, 1 instance, auto-deploy off |
| Alias Vercel Staging | `kim-phuc-sac-git-staging-tristan-7343.vercel.app` → `dpl_9jGvmrcA6TbZWxoim4koQPVXBxmt`, SHA `8f25b086…` |
| Alias Vercel Production | `kimphucsac.vercel.app` → `dpl_BUVcPo5Up5zn8eDb2G3NXiyuLvd2`, SHA `ac5b4ae1…` |
| Migration Staging | Xem [evidence chỉ đọc](staging-db-readiness-2026-11-01.json); 22/22, không pending/unknown, M16–M22 complete |
| Migration/backup Production | Chưa kiểm chứng trực tiếp; không chạy migration/deploy/restore/backup Production trong đợt này |

SHA của nền tảng và alias là bằng chứng bản đã triển khai; chưa có smoke nghiệp vụ
Production trong đợt này. Mã trên feature branch CI/CD chưa nằm trong bất kỳ bản
live nào ở bảng trên. `production-baseline.json` giữ `verified: false`.
Tệp `.env.*.backup` là cấu hình, không chứng minh có bản sao database khôi phục được.

**Production hiện đang chạy Dockerfile cũ có `php artisan migrate --force` ở startup.**
Dockerfile ở staging đã bỏ lệnh đó, nhưng sửa trong Git chưa thay đổi image live.
Không resume/redeploy image cũ như một cách đồng bộ baseline. Xử lý cutover và dừng
writers theo PR #74, backup/files, migration và kiểm chứng nghiệp vụ riêng trước.

## Điều phối phát hành

`.github/workflows/ci.yml` tiếp tục kiểm tra PR vào staging/main, push main và có
`workflow_call`. `.github/workflows/release.yml` chỉ điều phối push staging và gọi lại toàn bộ frontend
test/lint/build, Composer validate/Pint/PHPUnit và build/container smoke trước job
Staging. CI không nhận secrets triển khai.

1. Xác minh ref `staging` và candidate vẫn là HEAD, đúng repository/project/service,
   Render Free một instance và không còn deploy đang chạy trên Render/Vercel.
2. Xác minh Render auto-deploy off; Vercel dashboard Git deployment creation
   `disabled`; system environment variables được bật. Thiếu/khác trạng thái thì dừng.
3. Tạo Git ref `refs/tags/kps-release-lock` bằng API atomic. Toàn bộ release có chung
   GitHub concurrency group `kps-release`, `cancel-in-progress: false`.
4. Kiểm kê/hash migration (chuẩn hóa LF), rồi inspector chỉ đọc trong environment
   sạch, TLS xác minh certificate, đối chiếu TiDB instance/username prefixes,
   database/grants, ledger, schema M16–M22, DDL jobs và competing migrators.
5. `migration_gate`: chỉ qua nếu đủ ledger/schema. Mọi migration hiện tại có
   `automatic: false` trong `deployment/migration-policy.json`; file mới, file sửa
   hash, migration thiếu hoặc unknown đều chặn. Không dùng `artisan migrate` chung.
6. Deploy backend bằng Render API với `commitId` chính xác. Chờ `live`, đối chiếu
   SHA rồi `/api/health` phải trả đúng SHA/environment. Startup không migration.
7. Deploy frontend Preview bằng Vercel Git-source API với `sha` chính xác; chờ
   READY, đối chiếu SHA, gán alias Staging ổn định. Không target Production.
8. Smoke alias: trang login, `release.json`, proxy health, CSRF cookie, đăng nhập
   tài khoản giả, `/auth/me`, navigation, reports/today, logout và xác nhận 401.
   Chỉ login/logout tạo session; không tạo report/tài liệu hay sửa người dùng.
9. Inspector DB/schema chạy lại; kiểm tra backend vẫn live, không có deploy khác.
   Chỉ khi mọi bước qua mới xóa khóa.

Mỗi chuyển bước ghi `release-evidence.json` (artifact 30 ngày, kể cả khi lỗi) gồm
candidate SHA, backend/frontend deployment ID và SHA thực tế, trạng thái ledger,
schema, migration `writes_executed`, smoke, phase lỗi và khóa. Không ghi credentials,
cookie, row IDs, payload người dùng, DB host hoặc nội dung response auth.
`/api/health` thêm `revision`/`environment`; Vite tạo `/release.json` khi build.
Các bản live cũ chưa có metadata mới; không suy ra SHA từ health cũ.

Khóa ref tồn tại qua job cancellation, runner crash và timeout. Mất phản hồi POST
không được tự gửi lại: nền tảng có thể đã nhận lệnh. Khi workflow bị hủy trước lúc
upload, lấy candidate từ ref và deploy IDs từ nền tảng/nhật ký run. GitHub concurrency
không bảo đảm FIFO và có thể thay thế pending run; candidate cũ bị chặn bằng HEAD.
Không có hàng đợi migration bên ngoài GitHub được khóa tự động: mọi operator/manual
deploy/migration phải tôn trọng khóa chung, không chạy ngoài pipeline đang hoạt động.

## Cấu hình trước khi merge PR CI/CD

Không cần nâng gói Render, tạo thêm service, paid pre-deploy command, SSH hoặc one-off
job. DB verification chạy từ GitHub-hosted runner. Free có thể cold start/sleep;
timeout và khóa giữ nguyên để operator xác minh, không giả định deploy thất bại đã dừng.

Render đã được đọc là off. **Giữ off** cho cả hai service. Tắt Vercel Git auto-deploy
ở dashboard/API trước khi merge, kể cả Production đang còn config cũ trong main.
Repo config mới tắt `git.deploymentEnabled` cho mọi branch, nhưng riêng file đó không
chứng minh dashboard đã tắt. Connector Vercel hiện tại không cung cấp trường này
để xác minh/cập nhật; pipeline dùng REST API kiểm tra lại trước release. Không đặt
`AUTO_DEPLOY_DISABLED_ACK=verified` khi chưa kiểm tra thật.

`After CI Checks Pass` của Render không thay thế coordinator: migration và frontend
ordering không được bảo đảm chỉ bằng chế độ đó. Không dùng deploy hook ngoài pipeline.

Tạo GitHub Environment **staging**, chỉ cho branch `staging`. Tạo **production**,
chỉ cho `main`, bật required reviewer nếu tài khoản hỗ trợ; chưa cấp secrets Production
cho workflow hiện tại. Kiểm tra ruleset cả staging và main: bắt buộc PR, cấm direct
push/force push, yêu cầu `Branch policy`, `Frontend`, `Backend`, `Backend container build`.
Hiện đã đọc được ruleset staging có bốn required checks; main cần kiểm tra/cấu hình thêm.

### Environment variables `staging`

| Variable | Giá trị |
| --- | --- |
| `RENDER_SERVICE_ID` | `srv-dbe6h0hsrm7s73b2oovg` |
| `RENDER_PRODUCTION_SERVICE_ID` | `srv-db3esa59fdbs73dg62lg` (chỉ làm deny guard) |
| `BACKEND_ORIGIN` | `https://kps-api-staging.onrender.com` |
| `PRODUCTION_BACKEND_ORIGIN` | `https://kim-phuc-sac.onrender.com` (chỉ làm deny guard) |
| `FRONTEND_ORIGIN` | `https://kim-phuc-sac-git-staging-tristan-7343.vercel.app` |
| `VERCEL_PROJECT_ID` | `prj_sSEBHcwALQHag7fQQxFmbisxocz4` |
| `VERCEL_TEAM_ID` | `team_hJaxsqskjIBmzfrdt8eI9Sky` |
| `AUTO_DEPLOY_DISABLED_ACK` | `verified`, chỉ sau khi kiểm tra hai dashboard |

### Environment secrets `staging`

| Secret | Nội dung / yêu cầu |
| --- | --- |
| `RENDER_API_KEY` | API key workspace đúng; chỉ dùng trong deploy job, không Vite |
| `VERCEL_TOKEN` | Token có quyền đọc project/deploy, tạo Preview và gán alias trong team đúng |
| `STAGING_DB_INSPECTOR_ENV` | JSON theo [mẫu](../deployment/staging-db-inspector-env.example.json); migrator Staging và audit user có PROCESS; không dùng runtime/prod credential |
| `STAGING_DB_CA_PEM` | PEM CA được operator xác minh; runner ghi file tạm private và xóa sau kiểm tra |
| `STAGING_SMOKE_EMAIL` | Email tài khoản giả Staging hiện có, được phép xem reports/today |
| `STAGING_SMOKE_PASSWORD` | Mật khẩu của tài khoản giả trên; không log hay dùng nhân viên thật |
| `VERCEL_AUTOMATION_BYPASS_SECRET` | Cần nếu alias Staging có Deployment Protection; gửi header, không đặt query URL |

GitHub cấp `github.token` với `contents: write` riêng job release để tạo/xóa khóa.
Không cần PAT cho deploy coordinator. Bot PR cần `pull-requests: write`, bật
Settings → Actions → General → **Allow GitHub Actions to create and approve pull
requests** (workflow chỉ tạo/cập nhật, không approve/merge).
Tùy chọn repository secret `RELEASE_PR_TOKEN` dùng GitHub App/PAT phạm vi repo tối
thiểu contents+PR write để PR bot kích hoạt CI. PR tạo bằng `GITHUB_TOKEN` không tự
kích hoạt phần lớn workflow khác; nếu không có token này, operator cần mở lại PR
bằng tài khoản người dùng để PR CI chạy, không được bỏ required checks.

Hồ sơ DPAPI local không thể dùng trực tiếp trên Linux runner. Operator chuyển đúng
các trường metadata đã xác minh và hai mật khẩu Staging vào secret JSON, không
commit/export plaintext trong repo, không đưa `DB_URL`, APP_KEY hay key AI vào bundle.
Các fingerprint giữ nguyên công thức của runner hiện hữu; instance TiDB shared gateway
có thể cùng endpoint nhưng username prefix của Production/Staging phải khác.

Vercel Preview **branch staging** cần các biến đang dùng bởi `vercel.mjs`:
`VITE_KPS_ENVIRONMENT=staging`, `VITE_API_BASE_URL=/`,
`KPS_BACKEND_ENVIRONMENT=staging`, `KPS_BACKEND_ORIGIN=https://kps-api-staging.onrender.com`,
`KPS_PRODUCTION_BACKEND_ORIGIN=https://kim-phuc-sac.onrender.com`.
Bật Automatically expose System Environment Variables để có
`VERCEL_ENV`, `VERCEL_GIT_COMMIT_REF`, `VERCEL_GIT_COMMIT_SHA`. Backend phải chấp nhận
đúng alias Staging trong CORS/Sanctum/session settings; giữ same-origin proxy hiện có.
`RENDER_GIT_COMMIT` phải có ở runtime để health trả revision. Không chép production
APP_KEY/DB credential vào Preview hoặc `VITE_*`.

Trong đợt này chưa đọc/ghi giá trị secrets trên GitHub, chưa xác minh sự hiện diện
của các secret nêu trên. Quyền MCP đọc dịch vụ không tự cung cấp token cho Actions.

## Vận hành và migration thủ công

Sau merge tính năng vào staging, theo dõi run **Release**, tải artifact và đối chiếu
SHA hai nền tảng. `workflow_dispatch` dùng branch staging chỉ để retry có chủ đích
sau điều tra; script vẫn từ chối candidate không còn là HEAD. Không dispatch từ main
để thử Staging. Các kiểm tra phải qua; không có tùy chọn bỏ CI/DB/smoke.

Đọc readiness local (không DDL, không receipt):

```powershell
.\deployment\Invoke-KpsStagingIncrementalMigration.ps1 `
  -Migration 2026_10_31_000001_create_account_audit_events_table.php `
  -ReleaseReadiness
```

`-Status` cũ kiểm tra điều kiện **trước** một migration. Khi M22 đã áp dụng, `-Status`
có thể báo FAIL vì ledger đã 22 và bảng đã tồn tại; không có nghĩa cần chạy M22 lại.
`-ReleaseReadiness` kiểm tra trạng thái đầy đủ sau phát hành, cho phép các bảng đã có
dữ liệu nghiệp vụ; vẫn bắt buộc mọi identity/TLS/grants/DDL/schema gate.

Nếu còn migration, dừng release, xác minh backup có restore/RPO, exclusivity và risk.
Dùng `-Plan` rồi `-Execute` theo [runner hiện hữu](KPS_STAGING_INCREMENTAL_MIGRATION_RUNNER.md),
đúng một file, receipt mới và các xác nhận tương tác. Không vô hiệu hóa confirmation
để chạy unattended. M19 chuyển trạng thái không tương thích: dừng writers, rehearsal,
không chạy backend cũ sau migration. M20/M21 cần kiểm tra dữ liệu V3; M22 cần audit
schema. Các migration khác cũng cần review riêng, không mặc định an toàn vì có CREATE.
File mới phải bổ sung inspector/policy/test cùng PR; chỉ thêm hash chưa cho phép auto DDL.

## Khi thất bại hoặc bị hủy

| Điểm lỗi | Xử lý |
| --- | --- |
| CI/configuration/HEAD | Sửa lỗi hoặc cấu hình; chưa deploy. Nếu chưa tạo ref thì chưa có khóa |
| Tạo ref lỗi/đã có khóa | Không xóa khóa để thử ngay. Xác định run đang giữ khóa, candidate và remote deployments |
| DB/ledger/schema/pending | Giữ khóa, xác minh chỉ đọc, xử lý migration theo runner sau khi workflow dừng; không retry DDL mù |
| POST timeout/mất phản hồi | Có thể nền tảng đã nhận deploy. Tra deployment trên dashboard trước mọi thao tác khác |
| Backend lỗi | Frontend chưa được deploy bởi coordinator. Kiểm tra bản đang live, logs và tính tương thích schema |
| Frontend/alias/smoke lỗi | Backend có thể đã mới. Artifact ghi từng SHA; sửa forward hoặc rollback **ứng dụng** sau kiểm tra tương thích DB |
| DDL thủ công lỗi/partial | Dừng tất cả retry; kiểm tra ledger, schema và DDL jobs, review phương án tiếp tục. Không tự down/restore DB |

Không tự rollback database khi deploy lỗi. Restore DB là quyết định riêng vì có thể
mất dữ liệu sau recovery point; phải xác minh recovery và target mới trước khi đổi
traffic. Rollback ứng dụng cũng không an toàn mặc định sau M19.

Chỉ sau khi run đã dừng, không có deploy/DDL đang chạy, hai SHA/schema và phương án
tiếp tục đã được ghi lại, operator mới tháo khóa:

```powershell
gh api repos/Truong117/Kim-Phuc-Sac/git/ref/tags/kps-release-lock
# Đối chiếu SHA với run/evidence, kiểm tra Render và Vercel trước khi xóa.
gh api --method DELETE repos/Truong117/Kim-Phuc-Sac/git/refs/tags/kps-release-lock
```

Không thêm cleanup job tự xóa khóa trên failure/cancel. Operator có quyền bypass
vẫn có thể khởi động deploy từ dashboard; quy trình này cần hạn chế quyền đó và
tuân thủ khóa. Không tuyên bố khóa GitHub có thể ngăn mọi thao tác quản trị ngoài hệ thống.

## PR phát hành và hotfix

`release-prs.yml` mở/cập nhật Draft PR staging → main khi có code diff. PR #74 đang
tồn tại: bot chỉ cập nhật vùng marker CI/CD, giữ checklist cutover của người vận hành.
Nếu main có thay đổi code chưa ở staging, bot tạo `hotfix/backport-main-<sha>` từ main
và Draft PR vào staging. Không cherry-pick, merge, force-update hoặc giải quyết conflict.
PR đã có cùng branch không được tạo trùng. Khi conflict, người review xử lý trên branch
riêng; CI phải chạy lại trước merge. Quy trình PR hoạt động độc lập với deploy secrets.

## Điều kiện bật Production

1. Baseline thực tế: đối chiếu backend và frontend SHA/alias, migration ledger **và**
   schema, identity/TLS/grants; không dùng HEAD main làm bằng chứng bản đang chạy.
2. Recovery: backup DB + files/key phù hợp, restore rehearsal ở target riêng, RPO/RTO,
   retention và người chịu trách nhiệm được xác nhận. Không có bằng chứng này ở CI hiện tại.
3. Đồng bộ cutover có kiểm soát: xử lý image cũ chạy migration ở startup, dừng writers,
   schema không tương thích, durable files/storage, queues/session, origins và P0
   accounts/RBAC/reports/tasks. Giữ Render Free; không dùng giả định về paid jobs/disks.
4. Đợt cutover/rehearsal được phép riêng, backend trước frontend, kiểm tra dữ liệu và
   smoke nghiệp vụ. Cập nhật `production-baseline.json` bằng bằng chứng cụ thể có review.
5. Workflow Production thủ công đã có mã điều phối: khóa bền vững, backend SHA chính xác,
   frontend staged `--skip-domain`, smoke rồi `promote`. Tuy nhiên baseline recovery
   vẫn `verified=false`, và credentials/dashboard gates chưa cấu hình; do đó **không thể
   chạy Production release**. Không tự kích hoạt trên push main. Cần review Environment,
   diễn tập lỗi và baseline thực tế trước khi dùng; xem runbook kích hoạt mới.

Production release được tách thành `.github/workflows/production-release.yml`:
CI tự kích hoạt trên `push main`. Job phát hành Production **chỉ** chạy khi repository
variable `PRODUCTION_CD_ENABLED=true`, SHA đến từ PR đã merge `staging → main`,
đủ cổng baseline/recovery và được Environment reviewer phê duyệt. Workflow cũng
hỗ trợ `workflow_dispatch` có confirmation. `check-production-baseline.mjs`
đang fail-closed; chưa kích hoạt deploy tự động. Xem
[KPS_PRODUCTION_CD_ACTIVATION.md](KPS_PRODUCTION_CD_ACTIVATION.md).
Không thêm `continue-on-error`, không sửa biến để bỏ baseline hoặc triển khai
Production khi chưa có bằng chứng khôi phục và cấu hình theo runbook.

## Tài liệu nền tảng

- [Render deploys và auto-deploy controls](https://render.com/docs/deploys)
- [Render API triển khai đúng commit](https://api-docs.render.com/reference/create-deploy)
- [Vercel deployment API](https://vercel.com/docs/rest-api/deployments/create-a-new-deployment)
- [Vercel project configuration](https://vercel.com/docs/project-configuration)

Tài liệu hiện hành được đọc khi chuẩn bị; các API write chưa được kiểm chứng live trong
pipeline này. Cần một lượt Staging sau review/merge và cấu hình trước khi coi CD đã nghiệm thu.
