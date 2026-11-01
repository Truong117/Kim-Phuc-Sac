# Phân tích báo cáo công việc V2 — bàn giao và phát hành

## Phạm vi và kiến trúc

Màn chính `/ai/insights` có kỳ 1/7/30 ngày, ngày kết thúc, phòng ban và bốn tab: Tổng quan, Theo nhân viên, Lịch sử phân tích, Báo cáo nguồn. Dashboard `/ai/dashboard` là thống kê điều hành riêng; màn Trợ lý AI không bị thay thế. V1 không bị sửa dữ liệu hoặc chuyển thành dùng chung. Lịch sử V1 chỉ đọc bằng API cũ dưới đúng requester và quyền nguồn hiện tại.

V2 dùng API `/api/ai/work-report-analyses`: `GET preview`, `POST /`, `GET history`, `GET /{id}`, `GET /{id}/sources`, `POST /{id}/retry`. Mọi API đều cần Sanctum, membership KPS đang hoạt động, `ai.insights.view` và `reports.view`. Phạm vi là giao của hai quyền theo `ReportScopeService`; ID trực tiếp cũng kiểm tra organization, scope hash và quyền nguồn hiện tại. Không thêm quyền hay provider toàn cục. Owner/Admin/Executive Assistant có grant phù hợp dùng chung cùng scope. Các scope khác chỉ được dùng khi đã được cấp cả hai quyền tương ứng.

Nguồn là `locked_at <= now(UTC)`, với ngày báo cáo theo Asia/Ho_Chi_Minh. Bản nháp không được gửi AI. Không cần chặn cả hôm nay nếu đã có nguồn thực sự khóa; ngày tương lai bị từ chối. Giới hạn lịch sử tạo mới mặc định 365 ngày, bao gồm cả đầu kỳ; lịch sử đã lưu vẫn đọc được sau giới hạn này. Bộ chọn và API dùng cùng giá trị backend. Số liệu báo cáo/đầu việc/trạng thái do backend tính, không phải AI.

Roster dùng active/default KPS memberships và `ReportParticipationService` (Owner miễn báo cáo); tác giả có nguồn lịch sử vẫn xuất hiện nếu không còn trong roster hiện tại. Không có nguồn được ghi “Chưa có dữ liệu để phân tích”, không suy diễn chưa nộp.

## Chẩn đoán “không có báo cáo phù hợp”

V1 chỉ xét ngày trong 30 ngày tính từ hôm nay, chặn hôm nay trước cutoff và yêu cầu `locked_at` đã qua. `ReportScopeService` giới hạn theo quyền, KPS membership và department/location/self; quyền vào trang AI không nâng quyền báo cáo. Context V1 chỉ hỗ trợ 100 báo cáo/300 đầu việc/60 KB. Đây là các điều kiện đã đối chiếu trong code và fixture, không phải bằng chứng database staging/production hiện có hay không có báo cáo.

Không truy cập nội dung dữ liệu thật hoặc dùng credential production trong nhiệm vụ này. Khi nghiệm thu, kiểm tra business date/cutoff, số báo cáo đã khóa trong kỳ, scope hai quyền, phòng ban gắn trên báo cáo (lịch sử), và lỗi API 401/403/422 trước khi kết luận thiếu dữ liệu. Không đổi thời gian khóa để tạo dữ liệu hiển thị.

## Snapshot, cache và quyền

Migration `2026_11_02_000000_create_work_report_analyses.php` chỉ tạo hai bảng mới. Manifest mới có 23 migration; prefix 22 cũ giữ nguyên. Snapshot/config/result/input của unit là `LONGTEXT` với Laravel `encrypted:array`, dùng APP_KEY hiện có. Không xoay APP_KEY nếu chưa chuẩn bị decrypt/re-encrypt. Không cấp quyền đọc trực tiếp các bảng snapshot cho client; backup phải được quản lý như dữ liệu báo cáo. Mặc định snapshot tối đa 1 MB (trước mã hóa; chừa dung lượng cho ciphertext và giới hạn row TiDB), 3.000 báo cáo và 200 đơn vị xử lý gồm tổng hợp; không tự xóa lịch sử. Đội vận hành cần quyết định retention và quyền backup trước khi mở rộng dung lượng.

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

Runner incremental hiện có chỉ allowlist M16–M22 và **cố ý tiếp tục từ chối M23**; PR này không mở rộng quyền của runner/CI đang được làm ở PR #75. Sau khi release owner review SQL và phê duyệt riêng, dùng one-off migrator profile riêng của đúng môi trường và chạy chính xác path dưới đây (chưa chạy trong nhiệm vụ):

```text
php artisan migrate --database=mysql --path=database/migrations/2026_11_02_000000_create_work_report_analyses.php --force --no-interaction
```

Không dùng runtime credential/root, không `migrate:fresh`, không seed, không tự sửa ledger. TiDB/MySQL DDL implicit commit: có thể tạo xong bảng đầu rồi lỗi bảng sau hoặc chưa ghi ledger. Có bất kỳ bảng/index/FK một phần nào thì dừng, lưu trạng thái schema và yêu cầu kế hoạch phục hồi riêng; không tự drop hay chạy lại để thử. Chỉ áp dụng khi cả hai bảng chưa có và M1–M22 đã complete. Production hiện có thể đang baseline cũ: phải hoàn tất cutover được quản lý riêng trước M23. Không nhập các chỉ dẫn ở đây vào PR #74/#75.

Postflight giữ flag tắt, so sánh đủ P0 fingerprints/files theo quy trình preservation; đếm rows là kiểm tra bổ sung, không thay thế checkpoint/restore. Kiểm tra unique cache và unique unit, foreign keys RESTRICT, encrypted LONGTEXT; không có backfill V1. Không tự rollback database khi tắt feature.

## Nghiệm thu staging rồi production

- [ ] Review/merge riêng PR tính năng vào staging; không tự merge staging→main hoặc sửa CI đang dở.
- [ ] Checkpoint có đường restore đã thử, identity/TLS/grants/DDL/writers và preflight PASS; M23 được phê duyệt riêng.
- [ ] Migration M23 exact path và postflight PASS; backend mới trước frontend; feature vẫn tắt.
- [ ] Bật fake trên staging; chạy worker/scheduler, kiểm tra 1/7/30 ngày và lọc phòng ban thực tế.
- [ ] Owner/Admin/Trợ lý chia sẻ đúng run; staff, membership inactive, scope downgrade, tenant khác và ID trực tiếp bị từ chối; link nguồn không tăng quyền.
- [ ] Chỉ báo cáo khóa; roster không có nguồn có thông báo trung lập; số liệu đúng; reload/history không tăng call.
- [ ] Gửi đồng thời nhiều request cùng kỳ/scope và xác nhận một cache row; queue deliver trùng không tạo thêm call. Kiểm tra thực tế trên TiDB trước bật thật (local chỉ SQLite/DDL compile).
- [ ] Kiểm tra update nguồn tạo version mới, snapshot cũ nhất quán; timeout/kill worker/thiếu budget/partial/retry và limit không cắt đầu vào.
- [ ] Người nghiệm thu đánh giá từng case trong `WORK_REPORT_AI_GROUNDING_REVIEW.md`, dữ liệu giả sạch; không gọi mock là bằng chứng chất lượng model.
- [ ] Khi có phê duyệt chi phí và dữ liệu thử nghiệm riêng: bật openai staging với key staging, model/đơn giá/ngân sách được duyệt; đối chiếu token/metadata/bill, grounding, injection, thiếu deadline/result và khối lượng 30 ngày.
- [ ] Daily Work Report và AI Weekly Planning hồi quy PASS. Thu thập ảnh E2E và số call.
- [ ] Production chỉ sau nghiệm thu: checkpoint + migration/pre/post riêng, cấu hình worker/scheduler/APP_KEY và config price; bật flag theo quyết định release owner, theo dõi ngân sách/lỗi.

Sự cố: đặt `WORK_REPORT_AI_ENABLED=false`, refresh config và restart dedicated worker. Không tạo run/retry mới; vẫn đọc kết quả đã lưu. Stop dedicated queue và kiểm tra leases khi cần; request đã ra provider có thể vẫn tính phí. Không rollback/drop bảng, không sửa nguồn báo cáo, không tắt guard database. Khôi phục chỉ sau review nguyên nhân, ngân sách và trạng thái pending/unknown.

## Kiểm chứng thực tế

Kết quả chạy test, commit và PR được ghi trong phần bàn giao của PR. PHPUnit dùng SQLite `:memory:`; browser E2E dùng profile giả/SQLite riêng đã qua preflight và được cleanup. Test HTTP Gateway là `Http::fake`, không chứng minh AI thật. Không gọi OpenAI thật, không migration staging/production, không deploy hoặc bật production trong nhiệm vụ.
