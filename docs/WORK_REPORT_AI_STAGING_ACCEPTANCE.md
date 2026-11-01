# Gói nghiệm thu staging PR #76 — M23, M24 và dữ liệu giả

Gói này chuẩn bị để phê duyệt **một lần cho triển khai staging + M23/M24 + nạp fixture + nghiệm thu fake**. Chưa thực hiện bất kỳ bước ghi staging, migration dùng chung, deploy hoặc gọi AI thật nào. Không bootstrap/reset/seed lại database. Không thay đổi lịch làm việc, giờ khóa, quyền hệ thống hoặc tạo kế hoạch tuần. Công việc production/cutover thuộc quy trình riêng.

[Dry-run staging đã xác minh](WORK_REPORT_AI_STAGING_DRY_RUN.json) chứa toàn bộ corpus, đáp án, matrix và fingerprint phê duyệt. Kết quả: `writes_executed=false`, M23/M24 pending; ledger OpenAI staging tháng 2026-11 đang reserved/spent 0 ở thời điểm kiểm tra. Đây là ảnh chụp ledger nội bộ, không phải hóa đơn; phải đọc lại trước pilot. Không reset ngân sách.

## Bộ dữ liệu và tài khoản

Dataset `wra76-acceptance-v1`, batch đầu `wra76-v1`. Nội dung được viết mới hoàn toàn, không sao chép production. Công cụ lấy ngày làm việc đã qua cutoff gần nhất làm đề xuất; khi apply phải truyền lại đúng ngày cuối được duyệt. Bản dry-run đính kèm đề xuất **02/10/2026–31/10/2026**, múi giờ Asia/Ho_Chi_Minh, cutoff 23:00, thứ Hai–thứ Bảy. Ngày 01/11 là Chủ nhật nên không ép tạo nguồn ngày đó. Nếu config lịch/giờ khóa thực tế trong runtime staging khác bản đề xuất, fingerprint không khớp và apply bị từ chối; không sửa config để vượt guard.

Tạo đúng **6 nhân viên**, **2 phòng ban giả** và **1 reviewer OWNER** miễn báo cáo. Không sửa tài khoản/phòng ban hiện có. Mỗi tài khoản có mật khẩu ngẫu nhiên riêng 48 ký tự, chuẩn bị dưới DPAPI của operator ngoài Git; không đưa mật khẩu vào tài liệu, CLI args, stdout hay PR.

| Tài khoản (`@kps-acceptance.example.test`) | Vai trò / phòng ban | Báo cáo trong 30 ngày |
|---|---|---:|
| `wra76.wra76-v1.sales-a` | OFFICE_STAFF / `WRA76_WRA76-V1_SALES` | 4 |
| `wra76.wra76-v1.sales-b` | OFFICE_STAFF / Sales | 4 |
| `wra76.wra76-v1.sales-c` | OFFICE_STAFF / Sales | 3 |
| `wra76.wra76-v1.ops-d` | OFFICE_STAFF / `WRA76_WRA76-V1_OPS` | 4 |
| `wra76.wra76-v1.ops-e` | OFFICE_STAFF / Ops | 3 |
| `wra76.wra76-v1.ops-f` | OFFICE_STAFF / Ops | 0 |
| `wra76.wra76-v1.reviewer` | OWNER / không gắn phòng ban | 0, miễn báo cáo |

Tổng **18 báo cáo / 22 đầu việc**. Các report_date đều là ngày làm việc; `locked_at` do `ReportClock::cutoffForDate()` tính, không đặt khóa sớm. `created_at` giả lập một phút trước cutoff của chính ngày đó. Đây là import lịch sử giả có phạm vi của công cụ vận hành, không mở API backdate hoặc nới quy tắc nộp báo cáo. Không cần mỗi người có nguồn mỗi ngày. Reviewer dùng quyền OWNER hiện có; không gọi AuthorizationSeeder hoặc sửa role grants. Nhân viên ops-f chỉ mang trạng thái “không có dữ liệu”, không đồng nghĩa chưa nộp hay không làm việc. Các tài khoản staging sẵn có có thể xuất hiện trong roster tổng công ty với trạng thái trung lập; không bị sửa hoặc cleanup.

| Kỳ đến 31/10 | Toàn công ty: báo cáo / đầu việc / units | Sales | Ops |
|---|---|---|---|
| 1 ngày | 2 / 4 / 3 | 1 / 2 / 2 | 1 / 2 / 2 |
| 7 ngày | 8 / 12 / 6 | 5 / 8 / 4 | 3 / 4 / 3 |
| 30 ngày | 18 / 22 / 6 | 11 / 14 / 4 | 7 / 8 / 3 |

9 tổ hợp có **33 units** gồm employee parts và summary. Dữ liệu nhỏ nên mỗi nhân viên có nguồn chỉ cần một leaf mỗi run. Kịch bản có completed/in-progress/blocked, kết quả rõ, mô tả chung chung, result trống nhưng content đủ, mâu thuẫn trạng thái, việc tương tự qua nhiều ngày với item ID khác, người không có nguồn, và prompt injection. Không có task link/deadline/KPI/nghiệm thu hoặc kế hoạch tuần làm căn cứ phụ.

Đáp án tham chiếu đầy đủ nằm trong `plan.reference` và `plan.no_source_reference` của dry-run JSON: mỗi case có `allowed`, `questions`, `reject`; report/item ghi `case_id` để người nghiệm thu tìm đúng nguồn sau khi nạp. Case rõ có `questions=[]`, không buộc tạo vấn đề. Những kết luận phải bác bỏ bao gồm đạt KPI/năng suất từ 10 lượt liên hệ, thiếu kết quả chỉ vì result rỗng, nghiệm thu từ COMPLETED, tự gộp việc, quá hạn, quy trách nhiệm hoặc biến đề xuất thành deadline. Đối chiếu cùng `WORK_REPORT_AI_GROUNDING_REVIEW.md` cho employee lẫn tổng công ty; mock không chứng minh model tuân thủ.

## Dry-run và kiểm tra đúng staging

`work-report-ai:fixture` mặc định dry-run. Public CLI từ chối mọi mode trên local/testing/production; unit test chỉ gọi service trong SQLite `:memory:` dưới PHPUnit. Trước truy cập dữ liệu thật, yêu cầu APP_ENV và KPS_DATABASE_ENVIRONMENT đều staging, MySQL/TiDB, database kps_internal, URL/socket không override, đúng runtime account `.kps_runtime` của profile độc lập KPS-Staging, prefix tách production kể cả dùng chung gateway. Kiểm tra fingerprint metadata đã xác nhận, CA và certificate/hostname verification, live DATABASE/CURRENT_USER/VERSION, TLS cipher và FK checks. Chỉ nhãn APP_ENV không đủ.

Profile identity và manifest sở hữu phải ở ngoài Git. Dùng profile audit staging v3 hiện có, không đưa secrets vào repo. Launcher Windows dưới đây chỉ có dry-run; giải mã runtime credential bằng DPAPI vào child env riêng, không kế thừa key AI, config cache hoặc môi trường production. APP_KEY tạm chỉ phục vụ bootstrap đọc, không được dùng để apply/đọc manifest thật. Không kết nối production.

```powershell
./deployment/Invoke-KpsWorkReportFixtureDryRun.ps1 `
  -ManifestPath C:/Users/DQT/AppData/Local/KPS/wra76-v1-ownership.json `
  -Batch wra76-v1 -EndDate 2026-10-31 `
  -OutputPath C:/Users/DQT/AppData/Local/KPS/wra76-v1-dry-run.json
```

Dry-run kiểm tra exact ledger prefix M1–M22/23/24, không partial schema, KPS/RBAC đã có, không namespace collision hoặc báo cáo ngoài bộ thử đã khóa trong kỳ được chọn. Khi chưa có M23/M24 vẫn xuất kế hoạch với `schema_ready=false`; **apply bắt buộc đủ M1–M24**. Không tự nhận nuôi hàng trùng email/code khi thiếu manifest đã ký.

Fingerprint phê duyệt ràng buộc target, org/roles, corpus/ngày/calendar và nội dung code/migrations liên quan (chuẩn hóa CRLF→LF giữa Windows/Linux). Thay code, lịch, ngày, scope hoặc corpus phải tạo lại kế hoạch, không sửa receipt để khớp. Public dry-run không chứa password, raw host, username DB hoặc dữ liệu production. Duy trì cùng code và APP_KEY thực tế của staging khi apply/review/cleanup.

## Chuẩn bị credential và trình tự phê duyệt chung

Tạo protected bundle từ dry-run đã xác minh, ngoài Git; helper chỉ in đường dẫn và số tài khoản. Thư mục mới đặt ACL operator/System/Administrators, không tự đổi ACL thư mục hiện có. File có sẵn không bị ghi đè.

```powershell
./deployment/Prepare-KpsWorkReportFixtureCredentials.ps1 `
  -DryRunPath C:/Users/DQT/AppData/Local/KPS/wra76-v1-dry-run.json `
  -OutputPath C:/Users/DQT/AppData/Local/KPS/wra76-v1/credentials.dpapi.json
```

Sau khi gói được duyệt, chính operator đã tạo DPAPI bundle xuất JSON riêng để chuyển qua kênh bí mật được duyệt vào runtime staging; **không xuất plaintext bây giờ hoặc đưa vào Git/artifact CI**:

```powershell
./deployment/Prepare-KpsWorkReportFixtureCredentials.ps1 `
  -ProtectedBundlePath C:/Users/DQT/AppData/Local/KPS/wra76-v1/credentials.dpapi.json `
  -OutputPath C:/Users/DQT/AppData/Local/KPS/wra76-v1/credentials.private.json
```

Gói trình phê duyệt gồm SHA PR #76, dry-run/fingerprint, hai migration đã review, SQL pre/post, checkpoint/restore + identity/DDL/writers, danh sách 7 account, 18 report/22 item, credential reference ngoài Git và bước fake-review/cleanup. Không cấp quyền deploy/migrate/load chỉ từ việc chuẩn bị dry-run.

Sau phê duyệt một lần, operator thực hiện tuần tự (chưa chạy):

1. Freeze writers, xác minh đúng target + backup/restore theo rollout. Áp dụng M23 bằng exact path và kiểm tra postflight; tiếp M24 + postflight. Không mở rộng runner M16–M22, không dùng `migrate:fresh`, seed hoặc sửa ledger. Partial DDL thì dừng theo recovery plan.
2. Triển khai backend/frontend và worker cùng SHA đã duyệt; flag AI production vẫn tắt. Migrations phải có trước code cần schema. Không chạy lẫn worker cũ. Trong phạm vi phê duyệt fake trên **staging**, bật tạm `WORK_REPORT_AI_ENABLED=true`, `WORK_REPORT_AI_DRIVER=fake` và worker dedicated để nghiệm thu thao tác UI tạo/retry/tạo lại; giữ budget thật chưa mở và không đổi API key. Sau fake có thể tắt flag để chỉ đọc lịch sử. CLI fake-review tự dùng config cục bộ và không đổi các flag này.
3. Trong runtime **staging thật** có APP_KEY hiện tại và runtime DML credential, đặt profile đã xác minh, manifest và credential private ở ngoài app/Git. Chạy dry-run lần cuối cùng batch/ngày và đối chiếu fingerprint với gói đã duyệt. Nếu khác thì dừng.
4. Nạp và chạy fake bằng lệnh bên dưới; chỉ truyền đường dẫn, không truyền mật khẩu. Apply giữ 1 transaction + organization lock + file lock; lưu manifest HMAC trước commit, lỗi ghi file rollback DML. Lưu bản manifest vào kho operator bền vững trước restart/cleanup; mất manifest hoặc commit/file không nhất quán thì dừng để phục hồi, không tự nhận nuôi/xóa dữ liệu.

```text
php artisan work-report-ai:fixture --mode=apply --profile=/secure/staging-audit.json --manifest=/secure/wra76-v1-ownership.json --batch=wra76-v1 --end-date=2026-10-31 --approval=<fingerprint> --credentials-file=/secure/credentials.private.json
php artisan work-report-ai:fixture --mode=fake-review --profile=/secure/staging-audit.json --manifest=/secure/wra76-v1-ownership.json --batch=wra76-v1 --end-date=2026-10-31 --approval=<fingerprint>
```

Apply không phát model events, queue/mail/notification: chỉ DML query builder và password hashing. Không chạy seeder, webhook hoặc email. Chạy lại cùng manifest không tạo thêm hàng và không đổi mật khẩu. Manifest giữ ID/expected fields của từng hàng tạo, quan hệ membership/role, scope và corpus; không giữ password. Các hàng đã bị sửa/thiếu, phụ thuộc mới hoặc batch đã cleanup khiến apply/cleanup từ chối.

Fake-review chỉ đổi config trong process: driver fake, key null, queue sync và cấm HTTP ngoài stub. Chạy thật pipeline 9 lượt theo matrix, kiểm tra counts/units/no_data, không thay persistent env flag/ledger. Rerun dùng cache. Đăng nhập reviewer để duyệt UI, 1/7/30 ngày, hai phòng ban, nguồn/quote, partial/retry và tạo bản mới có góp ý → quay lại bản cũ. Đăng nhập một nhân viên kiểm tra không truy cập được AI API. Chỉ dùng tài khoản reviewer của batch để tạo lịch sử có thể cleanup; tài khoản khác có thể đọc chung nhưng nếu tạo lịch sử riêng tham chiếu bộ này thì cleanup dừng bảo toàn.

## AI thật: pilot nhỏ và dự toán, chưa thực hiện

Đề xuất hạn mức tối đa **0,50 USD cho pilot**, cần quyết định rõ về model/key/đơn giá/chi phí trong phê duyệt; không mặc định bật AI thật từ gói fake. Bắt đầu **Sales, 1 ngày kết thúc 31/10**: đúng sales-a, 1 báo cáo/2 items → **2 provider calls** (employee + summary), tối đa 4 HTTP attempts nếu mỗi call có một retry 429/5xx. Xem grounding/injection/nhãn và token/cost thực tế rồi mới cân nhắc **một** lần tạo lại cùng snapshot có góp ý: thêm 2 calls, tổng tối đa 4 logical calls/8 HTTP attempts. Không tự retry timeout không rõ kết quả hoặc chạy mở rộng tự động.

Giá minh họa của model mặc định trong code `gpt-4.1-mini`: **0,40 USD input / 1,60 USD output mỗi triệu tokens**, theo [tài liệu OpenAI chính thức](https://developers.openai.com/api/docs/models/gpt-4.1-mini), kiểm tra ngày 02/11/2026. Trang hiện đánh dấu model deprecated: đây chỉ là tham chiếu tính toán, không cam kết model khả dụng hoặc tự chọn model thay thế. Phải duyệt model thực tế và tính lại bằng đơn giá hiện hành trước gọi thật.

Với giới hạn hiện có 65.000 input / 3.000 output tokens, 2 HTTP attempts/call, dự phòng mỗi unit: `(65.000×0,40 + 3.000×1,60)×2 / 1.000.000 = 0,0616 USD`. Pilot đầu 2 units ≤ **0,1232 USD** dự phòng; thêm một bản tạo lại tổng ≤ **0,2464 USD**. Đề xuất per-run cap **0,15 USD**, monthly threshold **0,50 USD**, không thay tự động trong code hoặc env. Ledger tháng dùng chung mọi feature: số đã dùng/dự phòng hiện tại phải được trừ, có thể chặn pilot sớm; không reset ledger để mở ngân sách. Đây là dự phòng bảo thủ theo config, không phải hóa đơn hay cam kết chi phí thực tế.

Nếu thực tế không như dự kiến, status partial/lỗi, quote thiếu, injection thành công hoặc chi phí không xác định: dừng và đối soát. Mở rộng cả 9 tổ hợp sẽ có 33 logical calls / tối đa 66 HTTP attempts, dự phòng minh họa **2,0328 USD** trước retry thủ công — vượt đề xuất pilot, nên **không nằm trong việc được phép tự chạy**. Chỉ mở rộng sau review chất lượng/usage và phạm vi ngân sách được duyệt. Ghi từng run ID, parent/revision, model/prompt/schema, đơn giá, số logical calls/HTTP attempts, input/output tokens, reserved/estimated/bill và verdict supported/unsupported/uncertain; không log password/raw provider payload.

## Cleanup theo manifest

Trước cleanup: dừng worker và người dùng ghi trong cửa sổ kiểm tra; không để pipeline đang chạy. Xuất cleanup-plan chỉ đọc, đối chiếu đúng fingerprint đã duyệt. Tool không chỉ xóa theo LIKE prefix.

```text
php artisan work-report-ai:fixture --mode=cleanup-plan --profile=/secure/staging-audit.json --manifest=/secure/wra76-v1-ownership.json --batch=wra76-v1 --end-date=2026-10-31
php artisan work-report-ai:fixture --mode=cleanup --profile=/secure/staging-audit.json --manifest=/secure/wra76-v1-ownership.json --batch=wra76-v1 --end-date=2026-10-31 --approval=<fingerprint> --writers-paused
```

Xác minh chữ ký HMAC/target/nội dung từng hàng và mọi FK trước khi xóa. Từ chối nếu report bị sửa, có comment/task/membership/role hoặc lịch sử từ người ngoài, source trộn dữ liệu khác, revision/operation tham chiếu ngoài bộ hoặc run pending/running. Chỉ xóa đúng IDs đã sở hữu và lịch sử V2 terminal do các account fixture yêu cầu với nguồn thuộc bộ này; xóa M24 operation/revision rồi units/runs, items/reports, role assignments/memberships, tài khoản và hai phòng ban giả. Revokes session/token của đúng account fixture. Không đụng V1/plan/tasks/comment/role/permission/đơn vị cũ.

**Ledger tháng và usage giữ nguyên**, kể cả đã chạy pilot thật. Nếu FK billing yêu cầu giữ tài khoản fixture, tool giữ danh tính đó nhưng tắt membership, bỏ role/phòng ban, đổi password ngẫu nhiên và revoke sessions/tokens; không còn đăng nhập hay tạo nguồn. Nội dung response cache mã hóa thuộc ledger có thể còn theo retention accounting. Kết quả cleanup ghi rõ IDs được giữ; không xóa usage/budget để làm sạch giả. Manifest chuyển `cleaned`, cleanup lại là no-op đã kiểm tra; không tái dùng batch đã retire. Credentials/private receipt xử lý qua chính sách kho bí mật operator, không đưa vào Git.
