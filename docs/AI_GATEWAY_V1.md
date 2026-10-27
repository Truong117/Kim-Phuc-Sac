# KPS AI Gateway V1

AI Gateway V1 là ranh giới backend dùng chung cho OpenAI Responses API. Lát cắt đầu tiên kết nối bộ trích xuất tài liệu Weekly Planning; AI Daily Report có thể tái sử dụng gateway nhưng vẫn giữ `KPS_AI_DRIVER=fake` theo mặc định.

## Luồng Weekly Planning

```text
DOCX private upload
  -> parser lưu passages có source_key vào database
  -> HR bấm Trích xuất bằng AI
  -> POST /api/weekly-plans/{id}/source/ai-extract
  -> RBAC + organization scope + trạng thái draft
  -> AI Gateway (Responses API, strict JSON Schema, store=false)
  -> backend kiểm tra source_keys, ngày, schema và provenance
  -> HR xem preview, chọn tài khoản KPS và chỉnh sửa
  -> API source/apply hiện có tạo Weekly Plan Entry
```

Gateway không tự tạo Task, không tự gán nhân viên, không thay đổi workflow và không publish. Endpoint đọc passages đã persist; file DOCX gốc có thể đã mất trên Render mà preview AI vẫn hoạt động. File gốc chỉ còn cần cho thao tác parse/reparse/replace.

## Bảo mật và kiểm soát chi phí

- `OPENAI_API_KEY` chỉ tồn tại ở backend secret store; không dùng tên `VITE_*`, không trả về frontend và không ghi log.
- Ngoài môi trường automated test, gateway chỉ chấp nhận origin HTTPS chính xác `api.openai.com`; cấu hình sai origin fail-closed trước khi gửi API key.
- Request luôn gửi `store=false` và Structured Outputs với JSON Schema `strict`.
- Weekly Planning trên model `gpt-6-luna` chỉ được coi là đã cấu hình khi `WEEKLY_PLAN_AI_REASONING_EFFORT=low`; gateway gửi `reasoning.effort=low` và đưa effort vào fingerprint để cache của cấu hình khác không bị tái sử dụng.
- Instructions coi toàn bộ nội dung tài liệu/báo cáo là untrusted input và cấm thực thi chỉ dẫn nằm trong nguồn.
- Weekly Planning chỉ gửi kỳ kế hoạch và passages cần phân tích; không gửi email, permission, role, membership hay credential.
- Endpoint yêu cầu `weekly_plans.manage_draft`, organization scope hợp lệ, active KPS membership và bị throttle hai request/phút/người dùng.
- Fingerprint theo model, feature, idempotency key, input và schema ngăn request trùng. Kết quả cache được mã hóa bằng `APP_KEY`; prompt và provider response body không xuất hiện trong application log.
- Mỗi call giữ trước chi phí tối đa của toàn bộ số HTTP attempt được phép trong ledger. Row lock trên kỳ ngân sách tuần tự hóa phép kiểm tra `spent + reserved`, nên request đồng thời không thể cùng tiêu một phần ngân sách chưa được giữ.
- Retry chỉ áp dụng có giới hạn cho HTTP 429 và 5xx. Timeout/mất kết nối là kết quả không xác định và không được tự retry vì provider có thể đã nhận request; reservation của attempt đó được tính bảo thủ. Các attempt trước response thành công cũng được tính theo mức tối đa để application budget không đánh giá thấp chi phí có thể đã phát sinh.
- Mỗi HTTP attempt gửi một `X-Client-Request-Id` không chứa dữ liệu nguồn để hỗ trợ đối chiếu với provider. Đây là correlation ID, không được coi là provider idempotency guarantee.
- Refusal, incomplete response, invalid JSON, schema/source/date sai đều không được áp dụng vào kế hoạch. Kết quả sai hợp đồng nghiệp vụ bị đánh dấu `rejected`, xóa encrypted cache và chỉ được thử lại bằng một hành động rõ ràng của người dùng.
- `reasoning_tokens` và `incomplete_details.reason` (nếu provider trả về) chỉ được ghi dưới dạng metadata vận hành đã allowlist. Prompt, passages, API key và toàn bộ provider response không được ghi log. `AI_INCOMPLETE` không tự retry.
- Automated tests chỉ dùng Laravel HTTP fake và SQLite in-memory; không có paid call.

Hai bảng mới:

- `ai_budget_periods`: tổng chi phí đã giữ và đã dùng theo environment/provider/tháng.
- `ai_usage_records`: feature, model, token usage, duration, trạng thái, safe error code và estimated cost. Structured result cache được mã hóa; không lưu prompt.

Migration nguồn là `2026_10_24_000000_create_ai_gateway_usage_tables.php`. Đây là migration thứ 14 của repository và chưa được phép chạy tự động. Trên staging đã bootstrap 13 migration, chỉ migration mới này được review/rehearse bằng migration operator trước khi bật driver. Preflight, lệnh one-off và phương án partial DDL nằm trong `docs/AI_GATEWAY_TIDB_MIGRATION_REVIEW.md`.

## Cấu hình

Mặc định an toàn cho local/CI/production chưa duyệt:

```dotenv
KPS_AI_DRIVER=fake
WEEKLY_PLAN_DOCUMENT_AI_DRIVER=none
KPS_AI_MONTHLY_BUDGET_USD=0
KPS_AI_INPUT_USD_PER_MILLION_TOKENS=0
KPS_AI_OUTPUT_USD_PER_MILLION_TOKENS=0
OPENAI_API_KEY=
```

Sau khi migration staging đã được duyệt và OpenAI project đã có hard usage limits, cấu hình secret/variables trên Render Staging:

```dotenv
KPS_AI_DRIVER=fake
WEEKLY_PLAN_DOCUMENT_AI_DRIVER=openai
WEEKLY_PLAN_AI_REASONING_EFFORT=low
WEEKLY_PLAN_AI_MAX_OUTPUT_TOKENS=6000
KPS_AI_MODEL=gpt-6-luna
KPS_AI_MAX_OUTPUT_TOKENS=6000
KPS_AI_MONTHLY_BUDGET_USD=10.00
KPS_AI_INPUT_USD_PER_MILLION_TOKENS=<current-input-price>
KPS_AI_OUTPUT_USD_PER_MILLION_TOKENS=<current-output-price>
OPENAI_BASE_URL=https://api.openai.com/v1
OPENAI_API_KEY=<backend-secret>
```

Giá không được hardcode vào source vì thay đổi theo model/thời điểm. Người vận hành phải lấy đúng giá USD trên một triệu token từ tài liệu OpenAI hiện hành, review model hỗ trợ Structured Outputs, rồi mới điền hai biến giá. Giá bằng 0 cố ý làm gateway báo chưa cấu hình. `APP_KEY` phải được giữ ổn định; nếu khóa thay đổi, encrypted cache cũ không đọc được và gateway fail-closed thay vì gọi provider lại ngoài kiểm soát.

Các giới hạn kỹ thuật được mô tả trong `.env.staging.example`: timeout/connect timeout, tối đa hai attempts, bounded retry delay, stale reservation, input/output limit và giới hạn passages/context riêng của Weekly Planning.

`WEEKLY_PLAN_AI_MAX_TASKS_PER_RESPONSE` là giới hạn an toàn riêng cho số đầu việc trong một kết quả extraction. Biến này không dùng chung với `WEEKLY_PLAN_MAX_SOURCE_ENTRIES_PER_REQUEST`, vốn chỉ giới hạn số entry trong một lần ghi `source/apply`. Backend từ chối toàn bộ response vượt giới hạn AI và không truncate; tài liệu trên giới hạn tiếp tục fail-closed cho tới khi batching được triển khai.

## Trình tự bật thử trên staging

1. CI xanh và review migration mới; không chạy qua container startup.
2. Làm theo `docs/AI_GATEWAY_TIDB_MIGRATION_REVIEW.md`: dùng `<staging-prefix>.kps_migrator` trên đúng TiDB `KPS-Staging`, chạy preflight read-only, sau đó chỉ áp dụng migration thứ 14 bằng `--path`. Không chạy lại bootstrap hoặc seed 13 migration cũ.
3. Xác nhận hai bảng ledger, FK/index và trạng thái 14/14; Render runtime vẫn chỉ có DML.
4. Tạo OpenAI project/key riêng cho staging, đặt provider-side hard usage limit không quá ngân sách đã duyệt.
5. Đặt secret và giá hiện hành trên Render Staging; giữ `KPS_AI_DRIVER=fake`, chỉ chuyển `WEEKLY_PLAN_DOCUMENT_AI_DRIVER=openai`.
6. Đặt `WEEKLY_PLAN_AI_REASONING_EFFORT=low` và giữ cả giới hạn output của feature/gateway ở `6000`; restart staging backend/queue nếu cần để nạp config. Kiểm tra detail plan trả `source_document.ai.configured=true` và action opaque `can_ai_extract_source=true` cho HR hợp lệ.
7. Với tài liệu giả không chứa dữ liệu doanh nghiệp thật, thực hiện đúng một preview có phê duyệt chi phí. Kiểm tra output, evidence, ledger, `reasoning_tokens`, `incomplete_reason`, log an toàn và HR confirmation trước source/apply.
8. Khi có lỗi hoặc chi phí bất thường, chuyển driver Weekly Planning về `none`; không xóa ledger hay passages.

Không bật trên production chỉ vì staging pass. Production cần review riêng về retention, DPA/data residency, model, ngân sách, migration và secret rotation.

## API failure contract

- `403`: thiếu quyền quản lý draft.
- `404`: plan ngoài organization scope.
- `409`: provider chưa cấu hình, vượt budget hoặc request giống hệt đang chạy.
- `422`: context quá giới hạn hoặc output không kiểm chứng được.
- `429`: throttle của KPS API.
- `503`: provider/auth/timeout/rate-limit/upstream failure được che bằng thông báo an toàn.

Các lỗi không xóa passages, entries hay file metadata đã lưu. HTTP provider body, API key và stack trace không được trả cho frontend.
