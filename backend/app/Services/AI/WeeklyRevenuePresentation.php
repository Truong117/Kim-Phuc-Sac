<?php

namespace App\Services\AI;

/** Render historical and new weeks alike, without modifying the stored AI result or its evidence. */
final class WeeklyRevenuePresentation
{
    public static function employee(array $employee, string $endDate): array
    {
        $revenue = self::forDisplay(WeeklyRevenue::employee($employee, $endDate), $employee);
        if ($revenue === null) {
            return $employee;
        }
        $employee['weekly_revenue'] = $revenue;
        $employee['work_totals'] = array_values(array_filter($employee['work_totals'] ?? [], fn ($t) => ! WeeklyRevenue::isMetric($t['metric'])));
        $groups = [];
        foreach ($employee['work_groups'] ?? [] as $group) {
            $context = WeeklyRevenue::isMetric($group['title']);
            $group['text'] = self::narrative($group['text'], $context);
            if ($group['text'] !== '') {
                $group['title'] = self::narrative($group['title']) ?: 'Kết quả bán hàng';
                $groups[] = $group;
            }
        }
        $employee['work_groups'] = $groups;
        $employee['result'] = self::result($employee['result'] ?? null);

        return $employee;
    }

    public static function forDisplay(?array $revenue, array $report): ?array
    {
        if ($revenue !== null) {
            return $revenue;
        }
        // Some historical prose mentions money that cannot be reconstructed from the saved items.
        foreach ($report['work_groups'] ?? [] as $group) {
            if (self::narrative($group['text'], WeeklyRevenue::isMetric($group['title'])) !== $group['text']) {
                return WeeklyRevenue::value(null);
            }
        }
        foreach (WorkReportAnalysisOutput::SECTIONS as $section) {
            foreach ($report['result'][$section] ?? [] as $claim) {
                if (self::narrative($claim['text']) !== $claim['text']) {
                    return WeeklyRevenue::value(null);
                }
            }
        }

        return null;
    }

    public static function result(?array $result): ?array
    {
        if ($result === null) {
            return null;
        }
        foreach (WorkReportAnalysisOutput::SECTIONS as $section) {
            $claims = [];
            foreach ($result[$section] ?? [] as $claim) {
                foreach (['text', 'rationale', 'impact', 'action', 'expected_outcome', 'verification', 'decision_needed'] as $field) {
                    if (isset($claim[$field])) {
                        $claim[$field] = self::narrative($claim[$field]);
                    }
                }
                if ($claim['text'] !== '') {
                    $claims[] = $claim;
                }
            }
            $result[$section] = $claims;
        }

        return $result;
    }

    private static function narrative(string $text, bool $revenueContext = false): string
    {
        if (! $revenueContext && ! WeeklyRevenue::isMetric($text)) {
            return $text;
        }
        // Keep neighboring order counts and cost/profit/target clauses in mixed sales summaries.
        $parts = preg_split('/([;\n]+|(?<=[.!?])\s+|,\s+(?!\d{3}\b)|\s+(?:với|và|mang về)\s+(?=doanh (?:thu|số))|\s+và\s+(?=(?:ngày|\d{1,2}\/|(?:đã\s+)?(?:lập|in|xử lý|bán|chốt|giao)|chi phí|lợi nhuận|mục tiêu)))/iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $output = '';
        $inRevenue = $revenueContext;
        $removed = false;
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1 || trim($part) === '') {
                continue;
            }
            $hasMetric = WeeklyRevenue::isMetric($part);
            $money = preg_match('/\d(?:[\d.,]*\d)?\s*(?:triệu|nghìn|ngàn|tỷ|tỉ|tr|k|đồng|vnd|vnđ|đ|₫|usd|eur|\$|€)(?!\pL)|[\$€]\s*\d|\d{1,3}(?:[.,]\d{3})+|(?:doanh (?:thu|số)\s*(?:là|đạt|[:=])?\s*|^)\d{4,}\b|doanh (?:thu|số)\s*[:=]?\s*0\b/iu', $part);
            $otherMetric = preg_match('/chi phí|lợi nhuận|công nợ|giá vốn|đơn giá|giá bán|chiết khấu|phí (?:ship|vận chuyển)|mục tiêu|chỉ tiêu|dự kiến|kế hoạch/iu', $part);
            $continuation = $inRevenue && preg_match('/^\s*(?:ngày|hôm|đạt|ghi nhận|là|từ|lũy kế|luỹ kế|doanh|[\d$€])/iu', $part);
            if ($money && ! $otherMetric && ($hasMetric || $continuation)) {
                $removed = $inRevenue = true;

                continue;
            }
            if ($removed && preg_match('/^(?:các )?số liệu (?:được giữ theo|theo từng)|^(?:không|chưa) quy (?:thành|về) tổng kỳ/iu', trim($part))) {
                continue;
            }
            $inRevenue = $hasMetric || ($revenueContext && ! $otherMetric);
            $separator = $parts[$i - 1] ?? ' ';
            if ($removed && $output !== '' && trim($separator) === '' && preg_match('/[.!?]$/u', $parts[$i - 2] ?? '') && ! preg_match('/[.!?]$/u', $output)) {
                $separator = '. ';
            }
            $output .= ($output === '' ? '' : $separator).$part;
        }
        if (! $removed) {
            return $text;
        }
        $output = trim($output, " \n\t.,;");

        return $output === '' ? '' : mb_strtoupper(mb_substr($output, 0, 1)).mb_substr($output, 1).(preg_match('/[!?…]$/u', $output) ? '' : '.');
    }
}
