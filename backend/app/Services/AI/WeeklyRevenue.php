<?php

namespace App\Services\AI;

/** VND actually reported in the frozen weekly inventory. Missing days are not zeroes. */
final class WeeklyRevenue
{
    public const METRIC = 'doanh\s+(?:thu|số)';

    private const PLAN = 'mục tiêu|chỉ tiêu|dự kiến|kế hoạch|ước tính|dự báo';

    private const CARRY = 'lũy kế|luỹ kế|lủy kế|cộng dồn|tính đến|từ đầu (?:tháng|năm|tuần)|nhắc lại|đã báo(?: cáo)?|mang sang|tháng trước|tuần trước|hôm qua';

    private const NUMBER = '\d+(?:[.,]\d+)*';

    private const UNIT = '(?:triệu|tr|nghìn|ngàn|k|tỷ|tỉ)(?:\s*(?:đồng|vnđ|vnd|đ|₫))?|đồng|vnđ|vnd|₫|đ';

    public static function isMetric(string $text): bool
    {
        return preg_match('/'.self::METRIC.'/iu', $text) === 1;
    }

    public static function employee(array $employee, string $endDate): ?array
    {
        $structured = [];
        foreach ($employee['work_totals'] ?? [] as $group) {
            if (! self::isMetric($group['metric'])) {
                continue;
            }
            foreach ($group['entries'] as $entry) {
                $structured[$entry['report_id'].':'.$entry['item_id']][] = $entry;
            }
        }

        $days = $closing = $seen = [];
        $uncertain = $carry = false;
        foreach ($employee['work_inventory'] ?? [] as $group) {
            foreach ($group['observations'] as $row) {
                $id = $row['report_id'].':'.$row['item_id'];
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                foreach (self::points($group['content'], $row, $structured[$id] ?? [], $endDate) as [$kind, $amount]) {
                    if ($kind === 'uncertain') {
                        $uncertain = true;
                    } elseif ($kind === 'carry') {
                        $carry = true;
                    } elseif ($kind === 'week') {
                        $closing[] = $amount;
                    } else {
                        $days[$row['date']][$kind][] = $amount;
                    }
                }
            }
        }

        $sum = 0;
        foreach ($days as $day) {
            $totals = array_unique($day['day'] ?? []);
            $entries = array_sum($day['entry'] ?? []);
            if (count($totals) > 1 || ($totals && $entries > reset($totals))) {
                $uncertain = true;
            }
            $sum += $totals ? reset($totals) : $entries;
        }
        $closing = array_values(array_unique($closing));
        if (count($closing) > 1 || ($closing && $closing[0] < $sum)) {
            $uncertain = true;
        }
        if ($uncertain || ($carry && ! $days && ! $closing)) {
            return self::value(null);
        }

        return $closing || $days ? self::value($closing[0] ?? $sum) : null;
    }

    public static function value(?int $amount): array
    {
        return ['amount' => $amount, 'display' => $amount === null ? 'Cần đối chiếu số liệu nguồn' : number_format($amount, 0, ',', '.').' đồng'];
    }

    /** A daily total supersedes its component orders. A closing week total supersedes daily totals. */
    private static function points(string $content, array $row, array $entries, string $endDate): array
    {
        $metricTitle = self::isMetric($content);
        $points = [];
        foreach ([$content, $row['result'] ?? '', $row['note'] ?? ''] as $field) {
            $inRevenue = $metricTitle;
            foreach (preg_split('/[;\n]+|(?<=[.!?])\s+|,\s+(?!\d{3}\b)|\s+và\s+(?=(?:lũy kế|luỹ kế|chi phí|mục tiêu))/iu', (string) $field) as $part) {
                if (self::isMetric($part)) {
                    $inRevenue = true;
                } elseif (preg_match('/chi phí|công nợ|thu hồi nợ|thu hộ|giá vốn|lợi nhuận|chiết khấu|phí ship/iu', $part)) {
                    $inRevenue = false;
                }
                if (! $inRevenue || trim($part) === '') {
                    continue;
                }
                $context = $part !== $content && ($metricTitle || ! self::isMetric($part)) ? $content.' '.$part : $part;
                if ($field !== $content && preg_match('/'.self::PLAN.'/iu', $content)) {
                    continue;
                }
                if (preg_match('/'.self::PLAN.'/iu', $context)) {
                    continue;
                }
                // Take currency only from the revenue clause. Prices, fees and order counts may share the item.
                $tail = self::isMetric($part) ? preg_split('/'.self::METRIC.'/iu', $part, 2)[1] : $part;
                $tail = preg_split('/(?:chi phí|công nợ|thu hồi nợ|giá vốn|lợi nhuận|chiết khấu|đơn giá|giá bán|mục tiêu|chỉ tiêu|phí ship)/iu', $tail, 2)[0];
                preg_match_all('/(?<![\d.,])('.self::NUMBER.')\s*('.self::UNIT.')(?!\pL)/iu', $tail, $matches, PREG_SET_ORDER);
                $numbers = array_map(fn ($m) => self::amount($m[1], $m[2]), $matches);
                // Legacy daily reports often omit the currency after an explicit revenue label.
                if (! $numbers && preg_match('/^\s*[:=]?\s*(?:(?:trong ngày|hôm nay|ngày)\s+)?(?:(?:đạt|là|ghi nhận)\s+)?(0|\d{4,}|\d{1,3}(?:[.,]\d{3})+)[.!]?\s*$/iu', $tail, $m)) {
                    $numbers[] = self::amount($m[1], 'đồng');
                }
                if (! $numbers && preg_match('/(?:không|chưa) (?:có|phát sinh) doanh (?:thu|số)/iu', $part)) {
                    $numbers[] = 0;
                }
                if (preg_match('/\d\s*(?:USD|EUR|\$|€)|(?:USD|EUR|\$|€)\s*\d/iu', $tail)) {
                    $points[] = ['uncertain', null];

                    continue;
                }
                if (! $numbers) {
                    continue;
                }
                $kind = self::kind($context, $row['date'], $endDate);
                if (in_array(null, $numbers, true) || count(array_unique($numbers)) > 1) {
                    $kind = 'uncertain';
                }
                $points[] = [$kind, $numbers[0]];
            }
        }
        // Older analyses can also contain validated, source-bound revenue observations.
        // Never add the same monetary observation a second time to a value read directly above.
        if (! $points) {
            foreach ($entries as $entry) {
                $context = implode(' ', [$content, $row['result'] ?? '', $row['note'] ?? '', $entry['metric']]);
                if (preg_match('/'.self::PLAN.'/iu', $context)) {
                    continue;
                }
                $kind = self::kind($context, $row['date'], $endDate);
                $amount = self::scaled((float) $entry['quantity'], $entry['unit']);
                if ($entry['basis'] !== 'incremental' && $kind !== 'week') {
                    $kind = $entry['basis'] === 'cumulative' ? 'carry' : 'uncertain';
                }
                $points[] = [$amount === null ? 'uncertain' : $kind, $amount];
            }
        }

        return array_unique($points, SORT_REGULAR);
    }

    private static function kind(string $text, string $date, string $endDate): string
    {
        if (preg_match('/toàn (?:công ty|bộ phận|nhóm)|cả (?:nhóm|phòng)|doanh (?:thu|số) (?:của )?(?:team|nhóm|phòng|công ty)|doanh (?:thu|số) (?:tăng|giảm|bình quân|trung bình)|(?:âm\s+|(?<!\d)[-−]\s*)\d/iu', $text)) {
            return 'uncertain';
        }
        if (preg_match('/(?:tổng (?:doanh (?:thu|số) )?(?:cả )?tuần|doanh (?:thu|số) (?:cả |trong )?tuần)/iu', $text)
            && $date === $endDate && ! preg_match('/tháng|tuần trước/iu', $text)) {
            return 'week';
        }
        if (preg_match('/'.self::CARRY.'|doanh (?:thu|số) (?:cả |trong )?(?:tháng|tuần)/iu', $text)) {
            return 'carry';
        }
        preg_match_all('~(?<![\d/])(\d{1,2})/(\d{1,2})(?:/(\d{4}))?(?![\d/])~u', $text, $dates, PREG_SET_ORDER);
        foreach ($dates as $reference) {
            if (sprintf('%02d-%02d', $reference[2], $reference[1]) !== substr($date, 5) || (isset($reference[3]) && $reference[3] !== substr($date, 0, 4))) {
                return 'uncertain';
            }
        }

        return preg_match('/(?:theo|từ|của|cho) (?:đơn|giao dịch)\b/iu', $text) ? 'entry' : 'day';
    }

    private static function amount(string $number, string $unit): ?int
    {
        if (preg_match('/^\d{1,3}(?:\.\d{3})+(?:,\d+)?$/', $number)) {
            $number = str_replace('.', '', $number);
        } elseif (preg_match('/^\d{1,3}(?:,\d{3}){2,}$/', $number)) {
            $number = str_replace(',', '', $number);
        }
        $number = str_replace(',', '.', $number);

        return is_numeric($number) ? self::scaled((float) $number, $unit) : null;
    }

    private static function scaled(float $quantity, string $unit): ?int
    {
        $unit = mb_strtolower(trim($unit));
        $scale = match (true) {
            in_array($unit, ['đồng', 'vnd', 'vnđ', 'đ', '₫'], true) => 1,
            preg_match('/^(nghìn|ngàn|k)(?:\s*(?:đồng|vnd|vnđ|đ|₫))?$/u', $unit) === 1 => 1000,
            preg_match('/^(triệu|tr)(?:\s*(?:đồng|vnd|vnđ|đ|₫))?$/u', $unit) === 1 => 1000000,
            preg_match('/^(tỷ|tỉ)(?:\s*(?:đồng|vnd|vnđ|đ|₫))?$/u', $unit) === 1 => 1000000000,
            default => 0,
        };
        $amount = $quantity * $scale;

        return $scale && is_finite($amount) && $amount >= 0 && $amount <= 1e15 && abs($amount - round($amount)) < 0.00001 ? (int) round($amount) : null;
    }
}
