# Phân tích báo cáo công việc V2 — bàn giao và phát hành

> Cập nhật hiện hành: prompt `work-report-v3.1` giữ bằng chứng bắt buộc nhưng
> bỏ yêu cầu tiền tố phân loại ở đầu câu. Báo cáo được trình bày theo bốn phần
> nghiệp vụ; xem `WEEKLY_AI_FOCUSED_READER.md`. Các nhãn mô tả bên dưới là
> quy chuẩn lịch sử, không còn bắt buộc với đầu ra mới.


**Dữ liệu nghiệm thu staging:** xem [gói M23/M24 + fixture](WORK_REPORT_AI_STAGING_ACCEPTANCE.md). Gói bổ sung 6 nhân viên giả/2 phòng ban + reviewer, 18 báo cáo/22 items trong 30 ngày, đáp án tham chiếu, dry-run chỉ đọc, manifest ký và cleanup có phạm vi. Chỉ trình phê duyệt chung với deploy staging/M23/M24; chưa nạp staging hoặc gọi AI trả phí. Không seed/reset database, không tạo kế hoạch tuần giả.

## Phạm vi và kiến trúc

Màn chính `/ai/insights` có kỳ 1/7/30 ngày, ngày kết thúc, phòng ban và bốn tab: Tổng quan, Theo nhân viên, Lịch sử phân tích, Báo cáo nguồn. Dashboard `/ai/dashboard` là thống kê điều hành riêng; màn Trợ lý AI không bị thay thế. V1 không bị sửa dữ liệu hoặc chuyển thành dùng chung. Lịch sử V1 chỉ đọc bằng API cũ dưới đúng requester và quyền nguồn hiện tại.

V2 dùng API `/api/ai/work-report-analyses`: `GET preview`, `POST /`, `GET history`, `GET /{id}`, `GET /{id}/sources`, `POST /{id}/retry`. Mọi API đều cần Sanctum, membership KPS đang hoạt động, `ai.insights.view` và `reports.view`. Phạm vi là giao của hai quyền theo `ReportScopeService`; ID trực tiếp cũng kiểm tra organization, scope hash và quyền nguồn hiện tại. Không thêm quyền hay provider toàn cục. Owner/Admin/Executive Assistant có grant phù hợp dùng chung cùng scope. Các scope khác chỉ được dùng khi đã được cấp cả hai quyền tương ứng.

Nguồn là `locked_at <= now(UTC)`, với ngày báo cáo theo Asia/Ho_Chi_Minh. Bản nháp không được gửi AI. Không cần chặn cả hôm nay nếu đã có nguồn thực sự khóa; ngày tương lai bị từ chối. Giới hạn lịch sử tạo mới mặc định 365 ngày, bao gồm cả đầu kỳ; lịch sử đã lưu vẫn đọc được sau giới hạn này. Bộ chọn và API dùng cùng giá trị backend. Số liệu báo cáo/đầu việc/trạng thái do backend tính, không phải AI.

Roster dùng active/default KPS memberships và `ReportParticipationService` (Owner miễn báo cáo); tác giả có nguồn lịch sử vẫn xuất hiện nếu không còn trong roster hiện tại. Không có nguồn được ghi “Chưa có dữ liệu để phân tích”, không suy diễn chưa nộp.

## Quy chuẩn nhận xét và phiên bản thực thi

Prompt **work-report-v2.3** dùng sáu tiêu chí trong `WORK_REPORT_AI_GROUNDING_REVIEW.md`: công việc, kết quả/tiến độ, vướng mắc, nhất quán, bước tiếp theo và diễn biến trong kỳ. Đây là hướng dẫn, không phải bảng chấm điểm; không ép đủ sáu nhận xét, không tạo vấn đề/câu hỏi giả. Kế hoạch tuần chưa được áp dụng trong vận hành KPS: thiếu kế hoạch là giới hạn dữ liệu, không phải điểm trừ. Không kết luận đạt/không đạt kế hoạch, KPI, năng suất, hiệu quả hay bỏ sót nhiệm vụ nếu không có dữ liệu đối chiếu; không lấy số đầu việc, độ dài/cách viết làm thước đo. Giữ các giới hạn về result tùy chọn, COMPLETED chưa xác minh nghiệm thu, thiếu deadline, quan hệ giữa công việc và quy trách nhiệm.

Giữ schema hình dạng **work-report-v2.1**: các section và `{text, evidence}` không đổi, danh sách có thể trống. Text mới có một trong bốn nhãn `Ghi nhận từ nguồn: `, `Nhận xét có căn cứ: `, `Chưa đủ thông tin: `, `Đề xuất: `, được validator kiểm tra về định dạng; đây không phải kiểm chứng ngữ nghĩa. Đề xuất không trở thành nhiệm vụ/cam kết/deadline đã giao. UI ghi rõ: “Nhận xét dựa trên nội dung báo cáo; chưa đối chiếu với kế hoạch tuần hoặc kết quả nghiệm thu.” Không thêm API hoặc truy vấn đối chiếu kế hoạch tuần.

**Chính sách lượt cũ:** cấu trúc hiện có chỉ lưu version/hash, không lưu nguyên instructions/schema. Deployment này chỉ thực thi đúng hợp đồng hiện tại; không suy đoán cách tái tạo hợp đồng cũ. So sánh đồng thời `prompt_version`, `schema_version`, `instructions_hash`, `schema_hash` ở retry API, worker trước tổng hợp/giữ ngân sách và provider trước mọi lời gọi (cả fake). Sai hoặc thiếu bất kỳ trường nào thì chặn với `AI_ANALYSIS_VERSION_UNAVAILABLE`; retry trả 422, `can_retry=false`, `requires_new_run=true`. Worker đánh dấu phần chưa xử lý bị lỗi, giữ phần thành công và trả partial khi có phần cũ, không ghép thêm nhận định theo quy chuẩn mới. GET/history vẫn đọc được kết quả cũ và không sửa metadata. Người dùng chủ động tạo lượt mới bằng quy chuẩn hiện tại; cache key đổi theo version/hash. Không backfill hoặc tự phân tích lại hàng loạt. Guard phiên bản không cần đổi schema; chức năng tạo lại bên dưới có migration M24 riêng.

Khi rollout thay quy chuẩn, **dừng dedicated worker cũ, triển khai web/worker cùng SHA, restart toàn bộ worker trước khi nhận/xử lý lượt mới**. Worker trước bản có guard không thể tự kiểm tra version; không chạy lẫn binary cũ đó với web mới. Lượt đang ra provider theo binary cũ cần drain/đối soát theo quy trình timeout/lease trước khi restart. Với các bản đã có guard, ngay cả quên bump version nhưng instructions/schema đổi thì hash khác vẫn chặn. Khi sửa validator/prompt/schema về sau phải cập nhật phiên bản tương ứng và kiểm tra lại cache/retry.

## Tạo bản phân tích mới từ snapshot đã chốt

Ba thao tác độc lập:

- **Cập nhật theo dữ liệu mới / tạo thông thường**: POST `/api/ai/work-report-analyses`, chụp nguồn hiện tại; giữ cache chuẩn theo scope/kỳ/nguồn/config. Cùng dữ liệu/config vẫn mở run cache cũ, kể cả khi đã có bản tạo lại.
- **Tạo bản phân tích mới**: POST `/{id}/regenerate`, JSON `{idempotency_key: UUID, review_feedback?: string}`. Chỉ completed/partial được tạo lại. Snapshot, source_hash, captured_at, kỳ, phòng ban, scope giữ nguyên. Run mới dùng config/model/quy chuẩn hiện tại, toàn bộ leaf/summary mới; không sao chép kết quả cũ hay sửa hash nguồn. Bản cũ bất biến. Prompt v2.3 thêm ranh giới góp ý; schema v2.1 giữ nguyên.
- **Thử lại phần lỗi**: POST `/{id}/retry`, cùng run và hợp đồng đã lưu; giữ guard/attempts/budget, không viết lại kết quả hoàn tất.

API tạo lại và GET `/regenerations/{key}` kiểm tra lại membership, cả hai quyền, organization, scope và quyền nguồn hiện tại. ID cũ không vượt scope downgrade. Lượt ngoài cửa sổ 365 ngày vẫn có thể tạo lại từ snapshot nếu còn quyền và đáp ứng giới hạn dung lượng hiện tại. Góp ý tối đa 1.000 Unicode code points, chỉ điều chỉnh trọng tâm/chi tiết/cách trình bày; được lọc danh tính/bí mật bằng bộ lọc báo cáo, lưu Laravel encrypted LONGTEXT ở revision và encrypted unit input. Không log text thô; `request_hash` chỉ phát hiện đổi payload khi replay. JSON `review_feedback` đi vào cả employee/summary, không ghép system instructions, không dùng làm evidence. Nhận xét cũ không được đưa làm nguồn lượt mới.

UUID mỗi xác nhận có unique database theo organization/requester/key. Replay cùng payload mở cùng run kể cả đã terminal; đổi payload cùng key trả 409. Transaction khóa hàng organization rồi kiểm tra operation và lượt tạo lại tương đương pending/running theo scope/nguồn/kỳ/phòng ban/config/góp ý đã lọc. Requester đồng thời dùng chung lượt đang chạy, mỗi thao tác có audit record riêng (`reused_existing=true`). Khóa organization để cấp số phiên bản nhất quán; provider chỉ chạy sau commit. Lượt đã terminal không chặn một xác nhận mới chủ động. Cần nghiệm thu nhiều connection TiDB trên staging; SQLite local không chứng minh isolation TiDB.

M24 lưu revision parent/root/number và operation source/result/requester/key; requester/time của phiên bản lấy từ run. Root gốc mặc định phiên bản 1, không backfill. Coalesce giữ parent của lần cấp lượt đầu tiên; operation audit vẫn ghi parent từng requester chọn. Cache namespace tạo lại là danh tính thao tác, không thay cache chuẩn. Gateway dùng `work-analysis:<run_id>:<unit_key>` nên fingerprint mới thực sự gọi provider. Ledger environment/provider/tháng vẫn chung; per-run reservation, giới hạn tháng, rate limit 4/phút và unit limits áp dụng đầy đủ. Không đặt lại ngân sách tháng; run mới lỗi không tác động bản cũ. Đọc/history/khôi phục chỉ GET, không phát sinh AI.

Hộp thoại hiển thị kỳ/phạm vi/captured_at, bản cũ được giữ, giới hạn góp ý và chi phí AI thật. Nguồn đã đổi được ghi rõ: tạo lại vẫn dùng nguồn cũ, cập nhật là thao tác riêng. Sau xác nhận mở URL `analysis=<new_id>` và poll. Chống nhấp đúp bằng ref đồng bộ. Trước POST lưu sessionStorage **chỉ** `{key, sourceId}` theo tài khoản/tổ chức, không góp ý. Reload chỉ GET lookup; request chưa tới server thì nhập lại góp ý với **cùng key**. Replay/conflict không tạo trùng; mount không tự POST. Mất quyền/kết nối giữ pointer để kiểm tra lại. Preview mặc định mở completed/partial mới nhất; chưa có thì mở lần thử mới nhất. `latest_attempt` mở lượt mới hơn pending/failed; lịch sử/link parent luôn mở bản cũ khi còn quyền.

## Chẩn đoán “không có báo cáo phù hợp”

V1 chỉ xét ngày trong 30 ngày tính từ hôm nay, chặn hôm nay trước cutoff và yêu cầu `locked_at` đã qua. `ReportScopeService` giới hạn theo quyền, KPS membership và department/location/self; quyền vào trang AI không nâng quyền báo cáo. Context V1 chỉ hỗ trợ 100 báo cáo/300 đầu việc/60 KB. Đây là các điều kiện đã đối chiếu trong code và fixture, không phải bằng chứng database staging/production hiện có hay không có báo cáo.

Không truy cập nội dung dữ liệu thật hoặc dùng credential production trong nhiệm vụ này. Khi nghiệm thu, kiểm tra business date/cutoff, số báo cáo đã khóa trong kỳ, scope hai quyền, phòng ban gắn trên báo cáo (lịch sử), và lỗi API 401/403/422 trước khi kết luận thiếu dữ liệu. Không đổi thời gian khóa để tạo dữ liệu hiển thị.

## Snapshot, cache và quyền

Migration `2026_11_02_000000_create_work_report_analyses.php` chỉ tạo hai bảng mới. Manifest hiện có 24 migration; M1–M23 giữ nguyên, M24 chỉ thêm metadata tạo lại. Snapshot/config/result/input của unit là `LONGTEXT` với Laravel `encrypted:array`, dùng APP_KEY hiện có. Không xoay APP_KEY nếu chưa chuẩn bị decrypt/re-encrypt. Không cấp quyền đọc trực tiếp các bảng snapshot cho client; backup phải được quản lý như dữ liệu báo cáo. Mặc định snapshot tối đa 1 MB (trước mã hóa; chừa dung lượng cho ciphertext và giới hạn row TiDB), 3.000 báo cáo và 200 đơn vị xử lý gồm tổng hợp; không tự xóa lịch sử. Đội vận hành cần quyết định retention và quyền backup trước khi mở rộng dung lượng.

Chốt nguồn trong transaction, khóa parent/items, sắp thứ tự ổn định và hash nội dung đã dùng, roster, kỳ, bộ lọc, phạm vi. Unique `work_analysis_cache_unique(cache_key)` gồm organization/scope, kỳ, bộ lọc, source hash, cấu hình, prompt/schema hash. `createOrFirst` và unique database ngăn tạo trùng giữa requester; atomic claim + lease token ngăn chạy trùng từng unit. Không dựa vào khóa frontend. Thay đổi nguồn/cấu hình sinh phiên bản khác; GET và đổi tab không gọi AI. Link nguồn mở báo cáo hiện tại, không giả vờ đó là bản chốt; quote đã chốt hiển thị cùng nhận định.

Chỉ gửi ID, ngày, nội dung/result/note đã lọc; không gửi hồ sơ tài khoản, email, customer object hay password column. Che danh tính nhân viên đã biết, email/URL, phone/account-number và mẫu password/token/API key/customer/account trong văn bản. Lọc mẫu không phải giải pháp DLP hoàn hảo: tên khách hàng không gắn nhãn hoặc bí mật định dạng lạ có thể không nhận diện được. Trước khi bật AI thật, kiểm duyệt dữ liệu thử nghiệm và quy trình nhập liệu phù hợp; không đưa báo cáo có bí mật vào hệ thống AI. Không log snapshot, provider payload hay exception message của unit.

## Provider, ngân sách và worker

Đây là **OpenAiGateway nội bộ trong Laravel**, không thêm Vercel AI Gateway hoặc SDK mới. Giữ nguyên Responses API, `store=false`, JSON schema strict và model đang cấu hình. Tham khảo hợp đồng [Structured Outputs chính thức](https://developers.openai.com/api/docs/guides/structured-outputs?api-mode=responses). Tính đúng schema không bảo đảm nhận định đúng nội dung.

`backend/.env.example` chứa các biến `WORK_REPORT_AI_*`. `WORK_REPORT_AI_ENABLED=false` mặc định ở mọi môi trường; `WORK_REPORT_AI_DRIVER=fake` không phát sinh API. Không ghi đè OPENAI_API_KEY hiện có. Khi bật thật, model phải được duyệt, cùng đơn giá input/output và ngân sách tháng dương; thiếu cấu hình sẽ fail closed. Feature dùng instance Gateway với cấu hình riêng, không sửa `kps_ai` hay AI Weekly Planning. Gateway hiện có ledger theo environment/provider/tháng dùng chung: giới hạn tháng của feature được so với tổng ledger, nên có thể chặn sớm khi các feature khác đã tiêu ngân sách. Đây là giới hạn bảo thủ, không hứa ngân sách feature tách biệt hoàn toàn.

Mỗi nhân viên được chia nhóm theo dung lượng, không cắt đầu vào. Tổng hợp lấy các phần hợp lệ; phần lỗi giữ nguyên trạng thái và phần thành công luôn xem được. Nếu đầu vào tổng hợp vượt giới hạn, kết quả là partial và đề nghị giảm kỳ/phòng ban; không tuyên bố đã tổng hợp đầy đủ. Số phần tiến độ bao gồm tổng hợp.

```text
php artisan queue:work work_report_ai --queue=ai-work-reports --timeout=150 --tries=1 --sleep=1
php artisan schedule:run
php artisan work-report-ai:recover
```

Connection `work_report_ai` dùng queue database hiện có, retry_after=240 giây, job timeout=150, lease=300. Có thể đặt `WORK_REPORT_AI_QUEUE_CONNECTION=work_report_ai` để bắt buộc queue nền dù queue mặc định của app là sync. Gateway timeout=60 giây/lần, tối đa 2 lần cho 429/5xx (có Retry-After), không tự retry network timeout; job chỉ một delivery attempt. Supervisor cần restart worker khi đổi code/config. Scheduler chạy recovery mỗi 5 phút: re-enqueue pending; running hết lease chuyển `AI_OUTCOME_UNKNOWN`, yêu cầu thao tác retry riêng và không tự gọi provider lại. Kết quả đến muộn bị lease fencing loại bỏ. Không chạy queue sync trong production.

Retry thủ công chỉ chạy phần lỗi chưa hết hạn mức (mặc định 2, hard cap 3), giữ phần thành công; tổng hợp cần chạy lại nếu có phần nhân viên mới thành công. Ngân sách run giữ khoản dự phòng cho từng call gồm retry HTTP, tổng hợp và retry thủ công; không hoàn dự phòng khi outcome không rõ. Gateway kiểm soát ngân sách tháng đồng thời bằng transaction/row lock. Mở kết quả không gọi AI. Usage/cost của mô phỏng, usage thiếu hoặc run có lượt thử lại không đủ cơ sở cộng phí được hiển thị chưa xác định, không bằng 0. X-Client-Request-Id là mã truy vết, không phải cam kết provider idempotency: timeout, worker kill và retry có thể phát sinh phí lặp.

Theo dõi SQL aggregate trạng thái/error_code/attempts/leased_until của units, run pending quá 5 phút, partial rate, `AI_TIMEOUT`, `AI_OUTCOME_UNKNOWN`, `AI_BUDGET_EXCEEDED`, `AI_RUN_BUDGET_EXCEEDED`, `AI_REDUCE_SCOPE` và log metadata Gateway. Không bật debug/log HTTP bodies. Hiện không bổ sung dịch vụ giám sát hoặc worker trả phí.

## Migration preflight/postflight — chưa thực thi trên hệ thống dùng chung

Áp dụng quy trình recovery checkpoint, TLS VERIFY_IDENTITY, kiểm tra TiDB identity/grants, dừng competing migrator/writers và exact-SHA của dự án. Không tái bootstrap/seed database đang có. SQL chỉ đọc đã chuẩn bị:

- `backend/scripts/work-report-analysis-preflight.sql`: ledger chính xác prefix 22, hai bảng mới chưa tồn tại, parent BIGINT UNSIGNED, FKs bật và số lượng P0 đã lưu.
- `backend/scripts/work-report-analysis-postflight.sql`: ledger 23, đủ cột/index/FK, hai bảng trống và so sánh P0 theo checkpoint.

Runner incremental hiện có chỉ allowlist M16–M22 và **cố ý tiếp tục từ chối M23/M24**; PR này không mở rộng quyền của runner/CI đang được làm ở PR #75. Sau khi release owner review SQL và phê duyệt riêng, dùng one-off migrator profile riêng của đúng môi trường và chạy chính xác path dưới đây (chưa chạy trong nhiệm vụ):

```text
php artisan migrate --database=mysql --path=database/migrations/2026_11_02_000000_create_work_report_analyses.php --force --no-interaction
```

Không dùng runtime credential/root, không `migrate:fresh`, không seed, không tự sửa ledger. TiDB/MySQL DDL implicit commit: có thể tạo xong bảng đầu rồi lỗi bảng sau hoặc chưa ghi ledger. Có bất kỳ bảng/index/FK một phần nào thì dừng, lưu trạng thái schema và yêu cầu kế hoạch phục hồi riêng; không tự drop hay chạy lại để thử. Chỉ áp dụng khi cả hai bảng chưa có và M1–M22 đã complete. Production hiện có thể đang baseline cũ: phải hoàn tất cutover được quản lý riêng trước M23. Không nhập các chỉ dẫn ở đây vào PR #74/#75.

Postflight giữ flag tắt, so sánh đủ P0 fingerprints/files theo quy trình preservation; đếm rows là kiểm tra bổ sung, không thay thế checkpoint/restore. Kiểm tra unique cache và unique unit, foreign keys RESTRICT, encrypted LONGTEXT; không có backfill V1. Không tự rollback database khi tắt feature.

## Nghiệm thu staging rồi production

### M24 — migration nối tiếp cho phiên bản và chống trùng

Kiểm tra chỉ đọc staging ngày 2026-11-02 qua profile xác minh TLS/identity: **22 migration đã áp dụng**, M23/M24 pending, không migration lạ; `writes_executed=false`, không DDL đang chạy. Inspector cũ `-Status` trả FAIL vì manifest mới 24 bước và M22 đã tồn tại; không có thực thi migration. Chưa xác minh ledger production trực tiếp; không suy ra production từ staging. M23 giữ nguyên byte theo HEAD e9fa108 bất kể trạng thái môi trường khác.

M24 `2026_11_02_010000_create_work_report_regeneration_tables.php` chỉ tạo `work_report_analysis_revisions` và `work_report_regeneration_requests`, không ALTER M23/backfill/gọi AI. Trước M24: đúng prefix M1–M23, hai bảng M24 chưa có, parent/FKs hợp lệ. Dùng `backend/scripts/work-report-regeneration-preflight.sql`; lưu counts/fingerprints mọi bảng cũ gồm snapshot/config/result/units M23. Sau phê duyệt release riêng, operator dùng exact path (chưa chạy dùng chung):

```text
php artisan migrate --database=mysql --path=database/migrations/2026_11_02_010000_create_work_report_regeneration_tables.php --force --no-interaction
```

Postflight `backend/scripts/work-report-regeneration-postflight.sql`: ledger 24, feedback encrypted LONGTEXT, tám FK RESTRICT, unique revision/operation, index equivalence, bảng mới trống; so sánh nguyên vẹn M23/P0 với checkpoint. Rollback từ chối khi có revision/operation. DDL TiDB implicit commit: dừng nếu partial schema, không drop/sửa ledger/chạy lại tự động. Runner M16–M22 không mở rộng. Chỉ local/test riêng đã chạy migration trong nhiệm vụ. Hoàn tất M23/M24 trước backend mới, feature vẫn tắt đến quyết định release riêng.

- [ ] Review/merge riêng PR tính năng vào staging; không tự merge staging→main hoặc sửa CI đang dở.
- [ ] Checkpoint có đường restore đã thử, identity/TLS/grants/DDL/writers và preflight PASS; M23 được phê duyệt riêng.
- [ ] Migration M23 rồi M24 exact path và từng postflight PASS; backend mới trước frontend; feature vẫn tắt.
- [ ] Bật fake trên staging; chạy worker/scheduler, kiểm tra 1/7/30 ngày và lọc phòng ban thực tế.
- [ ] Owner/Admin/Trợ lý chia sẻ đúng run; staff, membership inactive, scope downgrade, tenant khác và ID trực tiếp bị từ chối; link nguồn không tăng quyền.
- [ ] Chỉ báo cáo khóa; roster không có nguồn có thông báo trung lập; số liệu đúng; reload/history không tăng call.
- [ ] Gửi đồng thời nhiều request cùng kỳ/scope và xác nhận một cache row; queue deliver trùng không tạo thêm call. Kiểm tra thực tế trên TiDB trước bật thật (local chỉ SQLite/DDL compile).
- [ ] Kiểm tra update nguồn tạo version mới, snapshot cũ nhất quán; timeout/kill worker/thiếu budget/partial/retry và limit không cắt đầu vào.
- [ ] Tạo lại có góp ý, snapshot cũ khi source_changed, liên kết parent/root/người tạo/thời điểm; double click, timeout/reload, replay conflict, nhiều requester đồng thời. Mặc định giữ completed khi lượt mới pending/failed; giới hạn 1.000 ký tự và quyền sau downgrade.
- [ ] Kiểm tra deploy đổi instructions/schema sau capture hoặc sau lỗi provider: worker/retry chặn hợp đồng cũ, giữ phần cũ đọc được, không thêm usage/call; tạo lượt mới có cache key khác, không trộn quy chuẩn.
- [ ] Người nghiệm thu đánh giá từng case trong `WORK_REPORT_AI_GROUNDING_REVIEW.md`, dữ liệu giả sạch; không gọi mock là bằng chứng chất lượng model.
- [ ] Khi có phê duyệt chi phí và dữ liệu thử nghiệm riêng: bật openai staging với key staging, model/đơn giá/ngân sách được duyệt; đối chiếu token/metadata/bill, grounding, injection, thiếu deadline/result và khối lượng 30 ngày.
- [ ] Daily Work Report và AI Weekly Planning hồi quy PASS. Thu thập ảnh E2E và số call.
- [ ] Production chỉ sau nghiệm thu: checkpoint + migration/pre/post riêng, cấu hình worker/scheduler/APP_KEY và config price; bật flag theo quyết định release owner, theo dõi ngân sách/lỗi.

Sự cố: đặt `WORK_REPORT_AI_ENABLED=false`, refresh config và restart dedicated worker. Không tạo run/retry mới; vẫn đọc kết quả đã lưu. Stop dedicated queue và kiểm tra leases khi cần; request đã ra provider có thể vẫn tính phí. Không rollback/drop bảng, không sửa nguồn báo cáo, không tắt guard database. Khôi phục chỉ sau review nguyên nhân, ngân sách và trạng thái pending/unknown.

## Kiểm chứng thực tế

Kết quả chạy test, commit và PR được ghi trong phần bàn giao của PR. PHPUnit dùng SQLite `:memory:`; browser E2E dùng profile giả/SQLite riêng đã qua preflight và được cleanup. Test HTTP Gateway là `Http::fake`, không chứng minh AI thật. Không gọi OpenAI thật, không migration staging/production, không deploy hoặc bật production trong nhiệm vụ.
