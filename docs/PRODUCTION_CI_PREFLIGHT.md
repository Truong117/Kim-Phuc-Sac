# Production preflight, migration và phát hành KPS

## Trạng thái và giới hạn của đợt này

Đợt này sửa/kiểm thử code trên `feature/production-cicd-review`, cập nhật Draft PR
#80 và đọc DB Staging thật bằng profile KPS-Staging theo yêu cầu mới nhất.
Không chạy workflow có secrets Production, không kết nối DB Production, không
chạy migration, sửa grants/firewall/Render/Vercel, merge PR, deploy hoặc bật
`PRODUCTION_CD_ENABLED`. Không thay đổi các tệp chưa commit của tác vụ cutover.

Repo đã kiểm tra ngày 02/11/2026: main `d1c801f8aef3b1d9cafc39d1716323a8c34add6f`,
staging `9dafc5fda7f6200a189206574626a34150b15194` sau PR #82, gồm M23/M24 từ #76.
PR #75 **đã merge** tại `42996f8`, sau đó code CI/CD bị revert tại `b6e31a2`
qua PR #79 (`f37b5f6`). Vì vậy không coi CI/CD của #75 là đang có trên staging.
PR #80 đã đồng bộ HEAD staging này bằng merge `bc43313`; không có conflict.
Các tệp CI/CD được đưa lại trong #80 là nội dung cần review sau revert, không
phải một bản thứ hai đang chạy. PR #80 giữ Draft. Các SHA Git không chứng minh
backend/frontend đang live; đợt này không đọc lại nền tảng hay chứng nhận baseline.

Theo thông tin bạn cung cấp, Environment `production` có reviewer Truong117,
chỉ main và 7 SQL secrets. Đợt này không đọc secrets hoặc xác nhận cấu hình quản trị
GitHub. CA, effective grants, schema/ledger Production và mạng Actions → Production
chưa được thử live. Cả ba cờ Production ở repository/Environment chưa đọc được;
người dùng đã chọn giữ việc xác minh OFF là **blocker**, không tự xác nhận PASS.

**Kết luận đợt hoàn thiện: BLOCKED** cho yêu cầu READY FOR REVIEW cho đến khi có
bằng chứng ba cờ `PRODUCTION_CD_ENABLED`, `PRODUCTION_MIGRATIONS_ENABLED`,
`PRODUCTION_PREFLIGHT_ENABLED` đều unset/false ở cả repository và Environment
production. Connector không hỗ trợ đọc Variables; automatic approval review từ
chối lấy token từ Git credential helper, và Computer Use chưa được cấp quyền
Chrome. Người dùng đã chọn để việc xác minh này là blocker. Không thử lấy token
bằng cách khác, không đổi flag hoặc secrets. Người có quyền có thể kiểm tra chỉ
đọc ở Settings → Secrets and variables → Actions → Variables và Settings →
Environments → production → Environment variables, rồi cung cấp bằng chứng.
Các unit test dùng certificate fixture và SQL/network/provider giả lập. Test
integration mới bắt tay TLS thật với server MySQL giả lập trên **localhost**,
CA/key dùng một lần; không kết nối TiDB hay chứng minh mạng từ Actions đến DB.

## Các tệp và đường đi

| Tệp | Vai trò |
| --- | --- |
| `.github/workflows/production-release.yml` | Cổng provenance → CI → Environment → coordinator |
| `.github/workflows/production-preflight.yml` | Chỉ dispatch main, cổng CI/reviewer, chỉ đọc; mặc định chưa bật |
| `.github/workflows/production-network.yml` | Dispatch main + CI/reviewer; chỉ DNS/TCP/TLS, không SQL, không cần bật cờ Production |
| `deployment/production-network-diagnostics.mjs` | Evidence network riêng; luôn ghi SQL/identity/ledger/schema/release chưa được xác minh hoặc cấp quyền |
| `deployment/production-candidate.mjs` | Current main SHA phải thuộc merged PR staging → main cùng repository |
| `deployment/production-network.mjs` | DNS/TCP và nâng cấp MySQL sang TLS, kiểm CA + hostname/SNI trước authentication |
| `deployment/production-database.mjs` | Process PHP có environment riêng, không truyền deploy/API keys; evidence đã lược dữ liệu nhạy cảm |
| `backend/scripts/production-ci.php` | CLI riêng, không Laravel/.env/config cache/Artisan/runner Staging |
| `backend/app/Support/ProductionCi/` | Identity, grants, schema, manifest, recovery, khóa, P0 và executor |
| `deployment/production-db-policy.json` | Identity/console binding và schema hash cho từng ledger prefix; hiện chưa có bằng chứng nên giữ null/false |
| `deployment/production-sql-manifest.json` | Đủ 24 source hashes theo thứ tự; tất cả vẫn chặn automatic DDL |

Production job chỉ vào sau CI PASS và approval Environment. `PRODUCTION_CD_ENABLED`
phải là `true` cho cả push và retry thủ công. Retry không bỏ cổng merged PR. Job
kiểm tra lại current main trước DB, sau DB, sau backend và trước frontend promotion.
Code chỉ ghi ref khóa trong repo và gọi nền tảng khi đã được kích hoạt riêng.

Thứ tự release: provider identity/auto-deploy-off/Free → durable Git lock → network
+ DB preflight → recovery/migration gate → DB postflight → backend exact SHA +
health → build/stage frontend Production với `--skip-domain` → smoke staged URL
→ DB postflight → promote frontend → smoke đăng nhập/session trên domain chính
→ DB postflight + backend vẫn live → gỡ khóa → báo thành công.

## Những gì preflight thực sự xác minh

1. Policy đã review phải định danh KPS-Internal, instance ID, nhánh `main`, database
   `kps_internal`, username prefix, endpoint fingerprint, SQL hostname fingerprint
   và bằng chứng console không quá 30 ngày.
2. Secret host phải là hostname TiDB Cloud, không IP/URL/DSN. Reader và migrator
   phải là đầy đủ `<prefix>.kps_ci_reader` / `<prefix>.kps_ci_migrator` của main.
3. CA PEM phải là CA còn hạn và đúng SHA-256 đã review. Probe MySQL gửi SSLRequest,
   yêu cầu TLS >= 1.2, CA đúng và hostname/SNI đúng. Không gửi password qua probe.
4. PDO kiểm tra certificate lại, tắt emulated prepare và multi-statements, không
   nhận DB_URL/socket/config/.env. Session phải có cipher và TLS 1.2/1.3.
5. SQL kiểm tra database, CURRENT_USER, CURRENT_ROLE=NONE, TiDB, FK checks=1,
   hostname và grants trực tiếp của cả hai account. Role grants, GRANT OPTION,
   ALL/global privileges, scope ngoài app và cú pháp không biết đều chặn.
6. Ledger phải là prefix chính xác của manifest: không gap, trùng, đảo thứ tự hay
   tên lạ. Manifest kiểm inventory và SHA-256 nội dung source đã chuẩn hóa LF.
7. Schema fingerprint gồm toàn bộ table/type/engine/collation/create options; column order/type,
   nullability/default/extra/charset/collation/generated expression; index columns,
   uniqueness/prefix/type/collation/visibility/expression và FK parent/update/delete rules. Giá trị phải
   đúng contract cho prefix ledger hiện tại, không tự học/duyệt từ DB đang chạy.
   Views, engine ngoài InnoDB, CHECK constraints và partition ngoài phạm vi đều chặn.
8. DDL_JOBS trong database phải không có trạng thái đang chạy hoặc không biết.
   Lỗi quyền/capability hoặc vượt bound metadata sẽ dừng; không thay bằng kết luận
   “không có job”. Khả năng quan sát DDL của hai account chưa được thử live.

Staging inspector đã có contract M23/M24 tại `StagingWorkReportSchema.php`, pin
hai source hash riêng và đọc INFORMATION_SCHEMA cho đủ bốn bảng, parent keys,
thứ tự/type/default/nullability của cột, index/visibility và FK/update/delete rules.
Charset/collation được pin theo mặc định source `utf8mb4/utf8mb4_unicode_ci`;
generated expression, comment, CHECK, partition hoặc create options ngoài contract
đều bị chặn. Nếu DB thật dùng collation khác thì dừng để review bằng chứng và
contract, không tự đổi DB hoặc chấp nhận metadata chỉ để check xanh.
Chỉ chấp nhận FK-support index tùy chọn đúng tên/tuple đã biết khi compound index
đã bao phủ. Ledger thiếu nhưng schema có, ledger đã ghi nhưng schema thiếu/partial,
query lỗi hoặc capability không rõ đều dừng. Release yêu cầu đủ 24 ledger entries
và schema M16–M24 complete. Writer allowlist Staging vẫn chỉ M16–M22; không thêm
quyền chạy M23/M24. Test metadata tổng hợp/SQLite không chứng nhận schema TiDB thật.
**Staging thật đã PASS read-only ngày 02/11**, xem
[evidence 24/24](staging-db-readiness-2026-11-02.json): ledger đúng M1–M24, không
pending/unknown; schema M16–M24 complete, parent keys/TLS/grants/concurrency PASS,
không ghi DB. Đây không phải chứng nhận đầy đủ physical schema M1–M15 hoặc kết nối
từ GitHub Actions; không dùng credentials/runner Staging cho Production.

## Kiểm chứng cho PR #80

PR vẫn Draft. CI trên pull request chỉ chạy kiểm tra code, không gọi workflow
release/preflight/network có Environment Secrets. Đối chiếu kết quả CI với **HEAD
hiện tại** trong [PR #80](https://github.com/Truong117/Kim-Phuc-Sac/pull/80);
run xanh của commit cũ không chứng nhận commit mới.

| Kiểm tra | Phạm vi chứng minh |
| --- | --- |
| `npm test`, lint/build trong CI Frontend | Toàn bộ frontend và Node release/security regression; provider/SQL fixtures không gọi cloud |
| Composer/Pint và toàn bộ PHPUnit backend | Regression ứng dụng + Production guards; SQLite/metadata fixtures, không DB Staging/Production |
| CI `Deployment security regression` | Ba handshake TLS thật trên localhost: CA/hostname đúng qua, CA sai và SAN sai bị từ chối, không gửi SQL authentication |
| CI `Backend container build` | Docker build và container smoke cục bộ; không Render deploy |
| `Invoke-Pester deployment/tests/Invoke-KpsStagingIncrementalMigration.Tests.ps1` | Orchestration/allowlist/receipt/isolated child regression bằng doubles, không chạy migration shared DB |
| Staging DB read-only bằng profile DPAPI riêng | **PASS thực tế**: ledger M1–M24, physical schema M16–M24 và parent keys; evidence riêng đã lọc dữ liệu nghiệp vụ |
| Production DB/network/grants, live SHAs, recovery | **Chưa kiểm chứng thực tế trong đợt này**; cần thao tác riêng đã được cho phép |
| Production flags tại repository và Environment | **NOT VERIFIED / BLOCKER** theo lựa chọn người dùng; không sửa flag hoặc secrets |

Kiểm thử mới còn chặn sai ref/repo, CA hash sai, DNS treo, probe thiếu kết quả,
schema/ledger lệch, cột/index/FK/collation bị sửa, CHECK/partition lạ, metadata query
lỗi, và mọi candidate direct/hotfix/feature/fork/unmerged/stale cho cả push/manual.
Các kết quả fixture không được chép sang `production-baseline.json` hoặc schema policy.

CI backend tạo `.env` rỗng ngay trong runner dùng một lần, không sao chép `.env`
local/Production. Các biến testing và SQLite in-memory đã được force trong
`phpunit.xml`. Điều này xử lý cảnh báo phpdotenv đọc file không tồn tại (640 cảnh
báo trong run trước), không tắt warning hay bỏ bớt test.

### Dependency security ngày 02/11/2026

Đã vá dependency trên nhánh riêng và audit lại bằng registry thật. CI nay chạy
`npm audit` (toàn bộ dev/build/runtime) và `composer audit --no-interaction`, lỗi
audit chặn reusable CI và release. Không chạy `audit fix --force`, không bỏ qua
advisory hoặc hạn chế audit chỉ runtime để tạo kết quả xanh.

| Phạm vi | Kết quả |
| --- | --- |
| npm toàn bộ lockfile | Sau vá: 0 advisory; trước vá: 15 package affected (12 moderate, 3 high), không phải 15 lỗi độc lập |
| npm bỏ dev dependencies | 0 advisory; kết quả độc lập với backend và công cụ build/dev |
| Composer | Sau vá: 0 advisory; trước vá: medium của `symfony/mime` v7.4.19, [CVE-2026-45070](https://symfony.com/cve-2026-45070) |

| Dependency | Bản cũ → mới | Phạm vi và xử lý |
| --- | --- | --- |
| Vite | 8.2.2 → **8.2.4**, pin patch | Sáu advisory trong dev server/editor/middleware/file serving, gồm HIGH [Windows launch-editor](https://github.com/advisories/GHSA-587h-7wmj-h5pm); không nâng lên minor/major mới |
| source-map-js | 1.2.1 → **1.2.2** | HIGH [indexed source-map DoS](https://github.com/advisories/GHSA-68fv-2mgg-jv7q); transitive của PostCSS/Tailwind, dev/build |
| zod | 4.5.4 → **4.6.7** | HIGH [collection validation memory DoS](https://github.com/advisories/GHSA-mrhx-c7xc-m3w5); transitive của eslint-plugin-react-hooks, dev/lint |
| minimatch | 10.2.6 → **11.0.1**, exact override | Moderate [globparts DoS](https://github.com/advisories/GHSA-g3hv-75wg-m249); chưa có backport 10.x, thay major có kiểm thử tương thích |
| symfony/mime | 7.4.19 → **7.4.22** | Backend mail parameter-name/header injection; chỉ đổi một package Composer, không chạy Composer scripts |

Mười hai package moderate gồm minimatch và dependency effects lên:
`@eslint-community/eslint-utils`, `@eslint/config-array`, `@eslint/js`,
`@typescript-eslint/eslint-plugin`, `@typescript-eslint/parser`,
`@typescript-eslint/type-utils`, `@typescript-eslint/typescript-estree`,
`@typescript-eslint/utils`, `eslint`, `eslint-plugin-react-refresh`,
`typescript-eslint`. Chúng đều là lint/dev dependencies; sau override không còn
minimatch cũ trong lockfile. Không hạ ESLint/TypeScript hoặc thay major các consumer.

Minimatch 11 đổi implementation của `set` và bỏ `makeRe`/`AST` cùng một số API khác
([changelog upstream](https://github.com/isaacs/minimatch/blob/v11.0.1/changelog.md)).
Đã kiểm tra ba consumer đang lock chỉ dùng `Minimatch.match`, `pattern`,
`minimatch` trong đường chạy liên quan. Test mới kiểm module CJS/ESM resolve,
ESLint config/glob/directory traversal/dist ignore/lintText và TypeScript parser
project-service `allowDefaultProject`. Pin override là quyết định có phạm vi,
không khẳng định minimatch 11 tương thích mọi consumer/API. CI chạy toàn bộ lint,
build và regression trên Linux. Cần review lại override khi nâng lint toolchain.

Local Windows: full npm suite đã có lỗi timeout Vite SSR `ai-icon.svg?react`
(235 pass/49 fail ở lần chạy trước test inventory cuối); không ghi local PASS.
Lint/build, compatibility và TLS tests riêng đã PASS. Xem CI tại SHA cuối trên PR
để biết kết quả đầy đủ Linux, không dùng CI của commit cũ thay kết quả mới.

**Giới hạn identity:** TiDB Starter dùng endpoint shared; SQL không cung cấp độc
lập tên console/instance ID/nhãn branch để so sánh. Mapping console main → endpoint
+ account prefix phải có bằng chứng và review riêng, sau đó được ràng buộc với SQL
account/hostname thực tế. Evidence nói rõ `console_labels_independently_queryable:
false`. Không dùng hostname chung hoặc một chuỗi `KPS-Internal` làm chứng nhận độc lập.
Nếu cần chứng minh qua control-plane API thay cho console attestation, phải bổ sung
adapter đọc metadata API và quyền tương ứng trong một thay đổi được review riêng.

## Secrets/variables còn cần thao tác

Giữ 7 Environment Secrets bạn đã chuẩn bị: `PRODUCTION_DB_HOST`,
`PRODUCTION_DB_NAME`, `PRODUCTION_DB_PORT`, `PRODUCTION_RO_USERNAME`,
`PRODUCTION_RO_PASSWORD`, `PRODUCTION_MIGRATOR_USERNAME`,
`PRODUCTION_MIGRATOR_PASSWORD`. Không sao chép chúng vào repository, Vercel hoặc
`VITE_*`. Tên SQL trong secret phải gồm prefix do Connect dialog của main cung cấp.

| Cấu hình bổ sung | Nơi đặt / yêu cầu |
| --- | --- |
| `PRODUCTION_DB_CA_PEM` | Environment Secret: CA thật từ nguồn đã xác minh; chuẩn LF, không dùng CA fixture trong tests |
| `PRODUCTION_DB_CA_SHA256` | Environment Variable: SHA-256 đúng byte PEM được lưu trong secret; không tự thay hash để bỏ lỗi |
| `PRODUCTION_P0_HMAC_KEY` | Environment Secret: khóa ngẫu nhiên >=32 byte, riêng CI; chỉ cần khi thực sự có pending migration được duyệt |
| `PRODUCTION_RECOVERY_EVIDENCE` | Environment Variable JSON theo `production-recovery-evidence.example.json`; không chứa backup content/credential |
| `PRODUCTION_SMOKE_EMAIL`, `PRODUCTION_SMOKE_PASSWORD` | Environment Secrets: tài khoản smoke riêng, ít quyền, được phép xem navigation/reports/today; không tạo/sửa tài khoản trong task này |
| `RENDER_API_KEY`, `VERCEL_TOKEN`, `VERCEL_AUTOMATION_BYPASS_SECRET` | Environment Secrets cho release; read-only workflow không nhận các key deploy này |
| `VERCEL_ORG_ID`, `VERCEL_PROJECT_ID`, `VERCEL_CLI_VERSION` | Variables theo target cố định trong coordinator; CLI version phải pin semver đã thử |
| `PRODUCTION_RUNNER_LABELS` | Repository Variable JSON labels, mặc định `["ubuntu-latest"]`; dùng runner có egress đã được cho phép nếu hosted runner không truy cập được |
| `PRODUCTION_PREFLIGHT_ENABLED` | Repository switch cho workflow chỉ đọc; **không được bật trong đợt code này** |
| `PRODUCTION_CD_ENABLED` | Repository switch cho release push/manual; **giữ unset/false** |
| `PRODUCTION_MIGRATIONS_ENABLED` | Environment switch bổ sung cho DDL; **giữ unset/false**, kể cả khi không có migration pending |

Grants được chấp nhận:

- Reader: SELECT trên `kps_internal.*`; ngoài ra chỉ USAGE global.
- Migrator: SELECT/CREATE/ALTER/INDEX/REFERENCES trên `kps_internal.*`, INSERT trên
  riêng `kps_internal.migrations`; ngoài ra chỉ USAGE global. Không DROP, UPDATE,
  DELETE, INSERT trên cả DB, GRANT OPTION hay inherited role.

Không tự nới quyền khi check fail. Nếu DDL_JOBS hoặc GET_LOCK không khả dụng với
phiên bản/quyền này, giữ blocked, lấy lỗi capability đã redacted và review phương
án quan sát/khóa riêng. Không dùng root làm giải pháp thay thế trong Actions.

## CA và đường mạng GitHub Actions

Đường chẩn đoán tách riêng khỏi SQL preflight để không bị schema/identity policy
chưa hoàn thiện chặn việc tìm lỗi network. Sau khi code được review, có trên main
và được cho phép chạy kiểm tra riêng, chọn workflow **Production network TLS
diagnosis (no SQL)**, chọn `main`, đợi toàn bộ CI rồi reviewer duyệt Environment.
Không dispatch từ nhánh feature. Đợt code này không dispatch workflow đó.

Job chỉ nhận ba Secrets `PRODUCTION_DB_HOST`, `PRODUCTION_DB_PORT`,
`PRODUCTION_DB_CA_PEM` và Variable `PRODUCTION_DB_CA_SHA256` trong Environment
production; runner labels lấy từ repository variable. Không nhận username/password
SQL, deploy token, recovery evidence hay schema policy; không bật
`PRODUCTION_PREFLIGHT_ENABLED`, `PRODUCTION_CD_ENABLED` hoặc
`PRODUCTION_MIGRATIONS_ENABLED` để chạy đường này. DNS và TCP/TLS có timeout hữu hạn.
Probe chỉ đọc MySQL greeting, gửi SSLRequest rồi xác minh CA/hostname; đóng socket
trước authentication. Artifact `production-network-evidence.json` ghi candidate SHA,
thời điểm, CA hash và kết quả network hoặc loại lỗi đã redacted. Các trường
`sql_authenticated`, `identity_verified`, `ledger_verified`, `schema_verified`,
`writes_executed`, `release_authorized` luôn false. PASS chỉ chứng minh kết nối TLS
tới endpoint đã cấu hình, không chứng minh đúng branch/database hay cho phép migration.

Sau khi code/config được review và **có cho phép riêng**, admin có thể bật riêng
workflow SQL read-only `production-preflight.yml`, dispatch đúng SHA main và duyệt Environment. Job tạo artifact
`production-preflight-evidence.json` với DNS/TCP/TLS và kết quả SQL đã lược nhạy cảm.
Job này không cài/chạy Vercel CLI, không có token Render/Vercel, không gọi DDL.

Hiện không có artifact chứng minh GitHub Actions kết nối được Production. Không
coi local success/mock success là bằng chứng mạng hosted runner. Nếu DNS/TCP fail,
kiểm tra endpoint/port và egress IP đã phê duyệt; chọn runner Linux có IP outbound
ổn định được allowlist hẹp hoặc tuyến private/VPN đã quản trị. Không mở
`0.0.0.0/0`, không tự thêm dải IP Actions và không tắt hostname/certificate checking.
Nếu TLS fail, kiểm tra CA, newline/hash, hạn cert và hostname đúng Connect dialog;
không retry bằng `rejectUnauthorized:false` hay SSL_VERIFY_SERVER_CERT=false.

## Chuẩn bị schema contract và baseline

Admin xác minh instance ID và main tại console, endpoint/prefix đúng account, SQL
hostname qua phiên đọc đã được phép riêng; review các fingerprint trước khi điền
`production-db-policy.json`. Công thức endpoint:

```text
SHA256(host + "|" + port + "|kps_internal|" + username_prefix + "|main")
server_hostname_sha256 = SHA256(exact @@hostname)
```

Xuất metadata bằng đúng bốn câu SELECT và ORDER BY trong
`ProductionCi/Inspector::SCHEMA_QUERIES` từ một phiên đọc được phép. Không SELECT
nhân viên để xây contract. Tạo JSON chỉ có `tables`, `columns`, `indexes`,
`foreign_keys`, giữ thứ tự hàng do SQL trả về; sau đó chạy công cụ **offline**:

```powershell
node deployment/production-schema-fingerprint.mjs <metadata-export-outside-repo.json>
```

Công cụ chỉ in schema hash, không kết nối DB hay tự sửa policy. Đối chiếu metadata
với migration/source/rehearsal rồi review hash theo từng prefix thực sự cần chạy.
Không lấy schema drift làm baseline mới chỉ để chuyển đỏ thành xanh. Policy hiện
để null vì task này không có quyền chứng nhận live schema/identity. Không thay
`production-baseline.json.verified` bằng true từ việc PR #74 đã merge.

## Dedicated Production runner và bảo toàn P0

Runner dùng manifest riêng, SQL riêng và hai PDO connection rõ vai trò. Không
chạy PHP `up()` từ thư mục migrations; không seed/bootstrap/Artisan/Staging runner.
Mỗi source migration map đúng một ledger name theo thứ tự và exact hash. SQL được
review/hash riêng, phải khai báo bảng mới, không backfill và backward compatible.
Toàn bộ suffix pending được vet trước bất kỳ DDL nào. Cả 24 mục hiện tại vẫn là
`automatic:false`; M23/M24 chưa được duyệt migration Production. M19 và mọi thay đổi
phá tương thích không được tự áp dụng.

Executor đầu tiên chỉ hỗ trợ CREATE TABLE cho bảng mới độc lập. Semicolon, comment,
ALTER/DROP/TRUNCATE/RENAME, DML/SELECT/LIKE/AS, FK REFERENCES, routine, external
storage và tạo lại bảng đang tồn tại đều bị chặn. Quyền ALTER có sẵn không đồng
nghĩa executor được phép dùng ALTER. Muốn mở rộng phải review code/test/rehearsal mới.

Khi có migration được duyệt, runner:

1. Xác minh identity/grants/ledger/schema và recovery gate mới, đúng candidate.
2. Vet tất cả pending; cần hai switch, freeze writers đã được xác nhận và contract
   schema sau từng bước. Không chạy nếu trạng thái không biết.
3. Giữ durable Git ref `refs/tags/kps-release-lock` của coordinator và SQL advisory
   lock `GET_LOCK('kps-production-release',1)`. Kiểm ownership trước mỗi migration.
4. Tạo HMAC đầy đủ cho rows P0 trong memory, không log payload/row ID/hash password.
   Bound: 10.000 rows/bảng và 64 MiB serialized data toàn checkpoint; vượt bound
   dừng, không chuyển sang sampling rồi tuyên bố bảo toàn đầy đủ.
5. Trước từng migration: kiểm lại recovery freshness, identity, ledger/schema, DDL
   jobs, lock và P0. Ghi checkpoint “DDL may start” trước khi gửi DDL.
6. Sau DDL: kiểm schema của prefix kế tiếp trong khi ledger phải chưa đổi; P0
   phải giữ nguyên. Chỉ sau đó INSERT đúng một row `migrations` qua prepared SQL.
7. Postflight: đúng count/prefix/batch/schema, P0 không đổi; rồi mới sang migration
   tiếp theo. Không UPDATE/xóa/sắp xếp lại/sửa chữa ledger.

P0 gồm users, tổ chức/phòng ban/locations/memberships, RBAC, reports/items/comments,
tasks, notifications, AI report runs và account audit.
Các bảng AI work-report mới từ M23/M24 cũng thuộc checkpoint P0; migration chưa
được duyệt không được thực thi để tạo các bảng này. Chỉ migration mode mới đọc
business rows để đối chiếu HMAC; preflight chỉ đọc metadata/ledger. Checkpoint không
thay thế backup và không phải snapshot nguyên tử khi writers còn hoạt động: freeze
là thao tác vận hành độc lập cần bằng chứng. Mọi operator/migrator ngoài workflow
phải tôn trọng khóa; code không thể cấm quản trị viên SQL có quyền bypass.

## Backup/recovery gate và thất bại

Recovery JSON phải khớp exact candidate SHA, instance/main/database và schema/ledger
digest ban đầu; có backup ID/hoàn tất, restore proof, P0 checkpoint và staging
rehearsal evidence. Backup <=6 giờ; attestation TTL <=30 phút; yêu cầu restore
verified/RPO accepted, và freeze khi có DDL. Thiếu gate dừng kể cả release không có
migration pending. Các boolean là xác nhận reviewer từ bằng chứng thật; code không
tự chứng nhận nội dung backup hoặc chạy restore để kiểm tra.

DDL TiDB không được coi là transaction nguyên tử. Nếu DDL/ledger/P0/postflight lỗi,
không rollback DB, không sửa ledger, không tự retry hoặc chạy migration sau. Nếu DDL
đã tạo bảng nhưng chưa INSERT ledger, trạng thái partial phải bị chặn ở lần đọc sau.
SQL lock có thể được giải phóng khi connection chết; Git ref vẫn giữ qua lỗi/hủy/
timeout để ngăn lần release kế tiếp. Không thêm cleanup tự xóa ref khi failure.

Backend deploy lỗi thì coordinator chưa phát hành frontend. Frontend/promotion/
smoke lỗi có thể để backend mới đang live. Artifact ghi phase, deploy IDs/SHA,
checkpoint DB và việc DDL có thể đã bắt đầu. Không in raw PDO/API/CLI stderr, SQL
credentials hoặc response nhân viên. Operator kiểm tra cả DB và nền tảng trước khi
gỡ khóa theo quy trình hiện hữu; ưu tiên phương án tiếp tục được review, không
rollback application mù sau thay đổi schema không tương thích.

Authenticated smoke dùng tài khoản riêng: CSRF, login, auth/me, navigation,
reports/today, logout và auth/me phải 401. Không tạo report/task, sửa nhân viên hoặc
gọi AI. Session/login có thể tạo session/audit theo hành vi ứng dụng; chỉ chạy ở
đợt release được cho phép, không trong test code hiện tại.

## Tài liệu đối chiếu

- [TiDB Starter shared endpoint và nhánh main](https://docs.pingcap.com/tidbcloud/connect-via-standard-connection-serverless/)
- [TiDB locking functions, khóa trên các TiDB server và timeout tối thiểu 1 giây](https://github.com/pingcap/docs/blob/master/functions-and-operators/locking-functions.md)
- [CLUSTER_INFO không khả dụng trên Starter](https://docs.pingcap.com/tidb/stable/information-schema-cluster-info/)
- [TiDB TLS VERIFY_IDENTITY: kiểm cả CA và hostname](https://docs.pingcap.com/tidb/stable/enable-tls-between-clients-and-servers/)
- [GitHub Environment reviewers và giới hạn branch](https://docs.github.com/en/actions/reference/workflows-and-actions/deployments-and-environments)

Chỉ các tài liệu và test offline đã được dùng ở đợt này. Chưa có lần Production
preflight/migration/deploy từ Actions được thực hiện hay chứng nhận thành công.
