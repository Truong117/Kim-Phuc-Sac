# Nạp dữ liệu nghiệm thu AI ngày → tuần vào staging

Tool `tools/New-KpsAiAcceptance.ps1` và PHP runner cùng thư mục là công cụ vận hành local. **Chạy trên máy Windows nhưng ghi trực tiếp vào TiDB staging**; không tải file lên Render, không đổi database local, không migrate và không tự gọi AI. Backend staging hiện có M27 là đủ.

## Bộ dữ liệu

Phiên bản `daily-ai-acceptance-v1`, phòng ban riêng `[NGHIỆM THU AI] Báo cáo ngày và tuần`, 4 tài khoản nhân viên giả lập, 8 báo cáo đã khóa, 12 đầu việc. Không tạo OWNER/ADMIN và không đổi tài khoản hiện có. Mật khẩu ngẫu nhiên chỉ lưu dạng hash và không được cung cấp; dùng tài khoản quản lý staging hiện có để xem dữ liệu.

Với `-WeekEnd 2026-10-31`, các báo cáo thuộc **29/10 và 31/10/2026**, mốc khóa 23:00 giờ Việt Nam. Chỉ chấp nhận ngày cuối tuần là thứ Bảy đã qua giờ khóa, trong 300 ngày gần nhất. Bỏ tham số thì chọn thứ Bảy trước đó.

| Nhân viên giả lập | Ngày đầu | Ngày sau | Kết quả tuần cần đối chiếu |
|---|---|---|---|
| Sale | Bán 10 sản phẩm dòng A trong ngày | Bán 30 sản phẩm dòng A trong ngày | 40 sản phẩm |
| Editor | Hoàn thành 2 ảnh và 1 video | Hoàn thành 3 ảnh và 2 video | 5 ảnh, 3 video; tách loại kết quả |
| Marketing | Kịch bản đang sửa; đã đăng 2 video TikTok | Hoàn tất 3 kịch bản; bài Facebook bị chặn vì chờ duyệt | Gom nhóm công việc, giữ diễn biến/vướng mắc; không tính bài Facebook là đã đăng |
| Sale lũy kế | Bán 10 sản phẩm dòng B trong ngày | Lũy kế cả tuần 30, gồm kết quả cũ | Giữ chưa xác nhận; không khẳng định 10 + 30 = 40 |

Các báo cáo đều có kế hoạch tiếp theo theo dòng; không được tính kế hoạch thành thành tích. URL `example.org` là minh chứng giả lập chưa xác minh, không phải bài đăng thật.

## Điều kiện trước khi chạy

- Giữ auto-dispatch staging tắt trong lúc nạp/nghiệm thu (`DAILY_REPORT_AI_AUTO_DISPATCH=false`). Tool không điều khiển runtime Render; nếu tự bật quét thì dịch vụ có thể xử lý nguồn mới độc lập với tool.
- Có hai profile MigrationTool đã cấu hình: `%LOCALAPPDATA%\KPS\MigrationTool\staging.json` và `production.json`. Chỉ đọc metadata production để loại trừ prefix, không kết nối production. Host/CA lấy lại từ profile staging, kiểm tra hash CA và TLS.
- PHP CLI có PDO MySQL; Git có quyền đọc repo. Không cần chạy `composer install` riêng cho tool và không nạp `.env` local.
- Dùng mật khẩu của tài khoản **TiDB staging root** (`<prefix-staging>.root` mặc định), không dùng mật khẩu migrator hoặc tài khoản web. Có thể truyền `-Username <prefix-staging>.kps_runtime` nếu tài khoản runtime hiện hữu có quyền INSERT các bảng nhân viên/báo cáo. Không cấp thêm quyền cho migrator chỉ để chạy thử.
- Tool chỉ dùng root/runtime đúng prefix staging đã lưu; từ chối prefix production hoặc hai profile cùng prefix. Không có tham số cho môi trường production.

## Cách lấy tool khi PR chưa merge

Chạy từ PowerShell, giữ local staging sạch và cập nhật trước. Không xóa/reset thay đổi local để vượt kiểm tra.

```powershell
Set-Location "D:\DQT\KPS\Kim-Phuc-Sac"
git switch staging
git pull --ff-only origin staging
git fetch origin feature/daily-ai-acceptance-fixture
if ($LASTEXITCODE -ne 0) { throw "Fetch fixture tool failed" }
$kpsFixtureCommit = (git rev-parse FETCH_HEAD).Trim()
$kpsFixtureHome = Join-Path $env:LOCALAPPDATA "KPS\AiAcceptanceTool"
New-Item -ItemType Directory -Path $kpsFixtureHome -Force | Out-Null
$kpsFixtureEncoding = New-Object System.Text.UTF8Encoding($false)
foreach ($kpsFixtureFile in @("New-KpsAiAcceptance.ps1", "kps-ai-acceptance.php")) {
    $kpsFixtureText = git show "${kpsFixtureCommit}:tools/$kpsFixtureFile"
    if ($LASTEXITCODE -ne 0) { throw "Cannot read $kpsFixtureFile" }
    [IO.File]::WriteAllText((Join-Path $kpsFixtureHome $kpsFixtureFile), (($kpsFixtureText -join "`n") + "`n"), $kpsFixtureEncoding)
}
```

Đây chỉ tải source hai tool từ commit đã fetch vào thư mục local, không đổi branch đang checkout sang feature và không ghi DB. Nạp dữ liệu không cần merge/deploy ứng dụng mới. Khi tool được merge vào staging, có thể chạy trực tiếp `& .\tools\New-KpsAiAcceptance.ps1 ...` từ repo đã cập nhật.

## Xem kế hoạch rồi nạp

```powershell
& "$env:LOCALAPPDATA\KPS\AiAcceptanceTool\New-KpsAiAcceptance.ps1" -WeekEnd "2026-10-31"
```

Nhập mật khẩu TiDB staging khi được hỏi; input được che, đi qua stdin, không nằm trong command line hoặc receipt. Lần này chỉ SELECT và kiểm tra, trả `PLAN_OK` cùng số bản ghi. Nếu STOP thì xử lý mã lỗi trước, không đổi profile sang production hay reset dữ liệu để chạy tiếp.

Sau khi kế hoạch đúng:

```powershell
& "$env:LOCALAPPDATA\KPS\AiAcceptanceTool\New-KpsAiAcceptance.ps1" -WeekEnd "2026-10-31" -Apply
```

`-Apply` là thao tác ghi được yêu cầu rõ. Trong một transaction: khóa tổ chức, kiểm tra lại fingerprint, INSERT đủ bộ mẫu, đọc lại xác minh trước commit. Không UPDATE/DELETE nguồn hay cấu hình thật. Chạy lại cùng bộ mẫu chưa bị sửa trả `reused=True`, không thêm trùng. Namespace đã có một phần, dữ liệu bị thay đổi hoặc tuần khác trùng namespace thì STOP, không tự sửa/ghi đè. Chưa có tính năng xóa mẫu tự động; giữ lịch sử nghiệm thu và AI để truy nguồn.

Kết quả thành công là `FIXTURE_OK`, có department ID và receipt JSON tại `%LOCALAPPDATA%\KPS\AiAcceptance\`. Schema chưa đủ M27, ledger khác release hoặc target/TLS không đúng sẽ bị chặn. Tài khoản thiếu INSERT thì transaction rollback; không chạy lại bằng tài khoản production.

## Nghiệm thu trên web staging

1. Đăng nhập tài khoản quản lý staging hiện hữu; vào Phân tích AI → Theo ngày.
2. Chọn **29/10/2026** rồi **31/10/2026**. Mỗi ngày có 4 nhân viên bắt đầu bằng `[NGHIỆM THU AI]`. Chỉ tạo/mở tổng hợp cho 8 báo cáo mẫu này; không bấm tạo lại hàng loạt.
3. Chờ các bản ngày hoàn tất. Kiểm tra danh sách công việc trước nhận xét; kế hoạch tách riêng; nguồn minh chứng chưa xác minh. Gửi trạng thái/mã lỗi nếu có, không reset attempts trong DB.
4. Sau khi đủ bản ngày openai, bật adapter `WORK_REPORT_AI_USE_DAILY_DOCUMENTS=true` cho staging và deploy để nhận cấu hình. Không đổi driver tuần thật sang fake, không đưa bản ngày fake vào lượt tuần thật.
5. Theo kỳ/báo cáo tuần: kỳ 7 ngày, ngày kết thúc **31/10/2026**, chọn đúng phòng ban `[NGHIỆM THU AI] Báo cáo ngày và tuần`. Tạo lượt tuần mới; mở lịch sử cũ không chạy lại theo luồng mới.
6. Đối chiếu bảng kỳ vọng. Phần công việc cần được tổng hợp thành nhóm, có kết quả/tồn đọng và trích nguồn; sau đó mới nhận xét và đề xuất. Không đánh giá năng suất/KPI hoặc tăng trưởng MXH từ số đầu việc hay URL mẫu.

Test phần mềm không chứng nhận model luôn hiểu đúng: mục tiêu lượt staging là kiểm tra thực tế nội dung, độ đầy đủ và chi phí. Tool không bật auto-dispatch hoặc thay ngân sách/API key.
