<?php

namespace App\Services\Mail;

use App\Models\WorkReportAnalysis;
use Illuminate\Support\Str;

/** A short view of validated company claims. No additional AI call or invented totals. */
final class ReportAutomationMail
{
    public function build(WorkReportAnalysis $run, string $kind): array
    {
        $origin = rtrim((string) config('kps_mail.frontend_url'), '/');
        abort_unless(filter_var($origin, FILTER_VALIDATE_URL) && parse_url($origin, PHP_URL_SCHEME) === 'https', 409, 'REPORT_AUTOMATION_FRONTEND_URL_REQUIRED');
        $end = $run->end_date->format('d/m/Y');
        $period = $kind === 'weekly' ? $run->start_date->format('d/m/Y').' – '.$end : $end;
        $result = $run->result;
        $chunks = ['Kính gửi anh/chị,', 'BÁO CÁO TỔNG HỢP '.($kind === 'weekly' ? 'TUẦN' : 'NGÀY').' '.$period];
        if ($run->analysis_config['driver'] === 'fake') {
            $chunks[] = 'Dữ liệu phân tích mô phỏng, chỉ phục vụ kiểm thử.';
        }
        $people = array_column($run->snapshot['employees'], 'name', 'employee_id');
        $authors = array_column($run->snapshot['reports'], 'employee_id', 'report_id');
        $sections = [
            ['1. Kết quả trong kỳ', [...($result['overview'] ?? []), ...($result['highlights'] ?? [])], 5],
            ['2. Công việc đang tiếp tục', $result['ongoing'] ?? [], 3],
            ['3. Vướng mắc cần theo dõi', [...($result['attention'] ?? []), ...($result['support'] ?? []), ...($result['uncertainties'] ?? [])], 3],
            ['4. Việc cần xem xét và quyết định', $result['recommendations'] ?? [], 3],
        ];
        foreach ($sections as [$title, $claims, $limit]) {
            $lines = [];
            // Prefer urgent claims while preserving order within each priority.
            $claims = collect($claims)->sortByDesc(fn ($c) => ($c['priority'] ?? '') === 'high')->all();
            foreach ($claims as $claim) {
                $text = trim(preg_replace('/\s+/u', ' ', $claim['text']) ?? '');
                if ($text === '' || isset($lines[$text])) {
                    continue;
                }
                $detail = $title === '4. Việc cần xem xét và quyết định' ? trim((string) ($claim['action'] ?? '')) : '';
                $names = array_values(array_unique(array_filter(array_map(fn ($ref) => $people[$authors[$ref['report_id']] ?? 0] ?? null, $claim['evidence'] ?? []))));
                $label = $names !== [] && ! collect($names)->every(fn ($name) => str_contains($text, $name)) ? implode(', ', $names).' — ' : '';
                $decision = $detail === '' ? '' : trim((string) ($claim['decision_needed'] ?? ''));
                $lines[$text] = '- '.Str::limit($label.$text, 420, '…').($detail !== '' && $detail !== $text ? ' '.Str::limit($detail, 240, '…') : '')
                    .($decision !== '' ? ' Cần quyết định: '.Str::limit($decision, 160, '…') : '');
                if (count($lines) >= $limit) {
                    break;
                }
            }
            if ($lines !== []) {
                $chunks[] = $title."\n".implode("\n", $lines);
            }
        }
        $chunks[] = 'Xem bản phân tích đầy đủ và báo cáo nguồn: '.$origin.'/ai/insights?'.http_build_query(['analysis' => $run->id, 'days' => $run->days, 'end' => $run->end_date->toDateString()]);
        $chunks[] = 'AI tổng hợp từ báo cáo đã khóa. Trạng thái hoàn thành do nhân viên tự báo cáo, chưa đồng nghĩa nghiệm thu. Bản email được chốt tại thời điểm gửi; báo cáo bổ sung sau đó được cập nhật trong hệ thống.';
        $chunks[] = app(MailConfiguration::class)->fromName();

        return ['subject' => '[KPS] Báo cáo '.($kind === 'weekly' ? 'tuần' : 'ngày').' '.$period, 'body' => implode("\n\n", $chunks)];
    }
}
