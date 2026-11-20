<?php

namespace App\Services\AI;

use Illuminate\Support\Str;

/** Read-only weekly presentation. The detailed groups, observations and evidence remain available. */
final class WeeklyEmployeeBrief
{
    public static function present(array $employee, string $endDate): array
    {
        $employee = WeeklyRevenuePresentation::employee($employee, $endDate);
        if (($employee['result'] ?? null) !== null || ! empty($employee['work_groups']) || ! empty($employee['work_totals'])) {
            $employee['weekly_brief'] = self::build($employee);
        }

        return $employee;
    }

    public static function build(array $employee): array
    {
        $result = $employee['result'] ?? [];
        [$figures, $overlap] = self::figures($employee['work_totals'] ?? []);
        $results = self::lines([...($result['highlights'] ?? []), ...($result['overview'] ?? [])], 2);
        if (! $results) {
            $results = self::lines($employee['work_groups'] ?? [], 3);
        }
        $follow = [];
        $priorities = [
            'attention' => 'Vướng mắc', 'support' => 'Cần hỗ trợ', 'ongoing' => 'Đang thực hiện',
            'uncertainties' => 'Cần đối chiếu', 'recommendations' => 'Đề xuất',
        ];
        foreach ($priorities as $section => $label) {
            foreach (self::lines($result[$section] ?? [], 2) as $line) {
                if (! in_array($line, array_column($follow, 'text'), true)) {
                    $follow[] = ['label' => $label, 'text' => $line];
                    break;
                }
            }
            if (count($follow) === 2) {
                break;
            }
        }
        if ($overlap) {
            $follow[] = ['label' => 'Cần đối chiếu', 'text' => 'Một số chỉ tiêu đăng bài có phạm vi chồng lặp. Xem số liệu từng kênh trong phần chi tiết trên web.'];
        }

        return ['figures' => $figures, 'results' => $results, 'follow_up' => $follow];
    }

    /** Keep complete sentences, including an immediately following qualification. */
    private static function lines(array $items, int $limit): array
    {
        $lines = [];
        foreach ($items as $item) {
            $text = preg_replace('/^\s*(?:(?:Ghi nhận từ nguồn|Nhận xét có căn cứ|Đề xuất)\s*:\s*)+/iu', '', trim($item['text'] ?? ''));
            $sentences = preg_split('/\R+|(?<=[.!?])\h+(?=\p{Lu})/u', $text, 3);
            $line = Str::words(trim($sentences[0]), 60, '…');
            if ($line === '') {
                continue;
            }
            if (isset($sentences[1]) && preg_match('/^(?:Tuy nhiên|Dù vậy|Chưa (?:rõ|xác định|có)|Không (?:rõ|thể xác định)|Cần phân biệt|Riêng)\b/iu', $sentences[1])) {
                $line .= ' '.Str::words($sentences[1], 35, '…');
            }
            if (in_array($line, $lines, true)) {
                continue;
            }
            $lines[] = $line;
            if (count($lines) === $limit) {
                break;
            }
        }

        return $lines;
    }

    private static function figures(array $totals): array
    {
        $published = $others = $sources = $scoped = $names = [];
        $overlap = false;
        foreach ($totals as $total) {
            $metric = trim($total['metric']);
            if ($total['status'] !== 'confirmed' || ! is_numeric($total['total']) || $total['total'] < 0 || WeeklyRevenue::isMetric($metric)
                || preg_match('/chưa rõ|không rõ|chưa xác định|mục tiêu|dự kiến/iu', $metric)) {
                continue;
            }
            $post = self::publication($metric, $total['unit']);
            if ($post !== null) {
                [$platform, $channel, $kind] = $post;
                // Variant labels on the same channel may describe the same output.
                $names[$platform][mb_strtolower($channel)] = $channel;
                $channel = mb_strtolower($channel);
                if ($channel !== '') {
                    $scoped[self::family($kind)] = true;
                }
                if (array_key_exists($kind, $published[$platform][$channel] ?? [])) {
                    $published[$platform][$channel][$kind] = null;
                    $overlap = true;
                } else {
                    $published[$platform][$channel][$kind] = $total['total'];
                }
                if ($kind === 'video' || $kind === 'reel') {
                    foreach ($total['entries'] ?? [] as $entry) {
                        $key = $entry['report_id'].':'.$entry['item_id'].':'.$entry['quantity'].':'.trim($entry['quote']);
                        $sources[$platform][$channel][$kind][$key] = true;
                    }
                }

                continue;
            }
            $category = match (true) {
                preg_match('/khách hàng|khách (?:cũ|mới)/iu', $metric) === 1 => 'Chăm sóc khách hàng',
                preg_match('/\b(?:quay|dựng|biên tập)\b/iu', $metric) === 1 => 'Sản xuất nội dung',
                preg_match('/\btài liệu\b/iu', $metric) === 1 => 'Tài liệu',
                default => 'Kết quả khác',
            };
            $number = self::number($total['total']);
            $name = preg_replace_callback('/^\p{Lu}(?=\p{Ll})/u', fn ($m) => mb_strtolower($m[0]), $metric);
            $name = preg_replace('/\s+đã làm$/iu', '', $name);
            $unit = trim($total['unit']);
            if ($unit === 'khách hàng' && preg_match('/^khách hàng (mới|cũ)\s/u', $name)) {
                $name = str_replace(['khách hàng mới ghi nhận', 'khách hàng cũ đã hỏi thăm'], ['khách mới', 'khách cũ được hỏi thăm'], $name);
                $unit = str_starts_with($name, 'khách hàng ') ? $unit : 'khách';
            }
            $others[$category][] = preg_match('/^'.preg_quote($unit, '/').'(?:\s|$)/iu', $name)
                ? $number.' '.$name : $name.' ('.$number.' '.$unit.')';
        }

        // A week-wide total can repeat quantities already attributed to individual destinations.
        foreach (array_keys($published[''][''] ?? []) as $kind) {
            if (isset($scoped[self::family($kind)])) {
                unset($published[''][''][$kind]);
            }
        }
        if (empty($published[''][''])) {
            unset($published['']['']);
        }

        $figures = [];
        foreach (['', 'Facebook', 'TikTok', 'Zalo', 'Instagram', 'YouTube'] as $platform) {
            $channels = $published[$platform] ?? [];
            $sums = $formats = [];
            foreach ($channels as $channel => $counts) {
                $clips = array_intersect_key($counts, array_flip(['video', 'reel']));
                $formats += array_intersect_key($counts, array_flip(['video', 'reel', 'video/Reel']));
                if ($clips) {
                    $mixed = array_key_exists('video/Reel', $counts) || array_intersect_key($sources[$platform][$channel]['video'] ?? [], $sources[$platform][$channel]['reel'] ?? []);
                    $overlap = $overlap || (bool) $mixed;
                    $counts['video/Reel'] = $mixed || in_array(null, $clips, true) ? null : array_sum($clips);
                    unset($counts['video'], $counts['reel']);
                }
                foreach ($counts as $kind => $quantity) {
                    if ($quantity === null || (array_key_exists($kind, $sums) && $sums[$kind] === null)) {
                        $sums[$kind] = null;
                    } else {
                        $sums[$kind] = ($sums[$kind] ?? 0) + $quantity;
                    }
                }
            }
            $parts = [];
            foreach (['ảnh', 'video/Reel', 'bài ảnh', 'bài video', 'bài Reel', 'bài'] as $kind) {
                if (isset($sums[$kind])) {
                    $unit = $kind === 'video/Reel' && count($formats) === 1 ? array_key_first($formats) : $kind;
                    $parts[] = self::number($sums[$kind]).' '.($unit === 'reel' ? 'Reel' : $unit);
                }
            }
            if (! $parts) {
                continue;
            }
            $label = $platform === '' ? (isset($sums['ảnh']) || isset($sums['video/Reel']) ? 'Ảnh và video/Reel đã đăng' : 'Bài đã đăng') : 'Đã đăng trên '.$platform;
            $scope = count($channels) > 1 && ! isset($channels['']) ? ' ('.count($channels).' kênh)' : '';
            if ($platform === '' && count($channels) === 1 && ! isset($channels[''])) {
                $scope = ' ('.$names[$platform][array_key_first($channels)].')';
            }
            $figures[] = ['label' => $label, 'text' => implode(', ', $parts).$scope];
        }
        foreach (['Chăm sóc khách hàng', 'Sản xuất nội dung', 'Tài liệu', 'Kết quả khác'] as $category) {
            if (! empty($others[$category])) {
                $figures[] = ['label' => $category, 'text' => implode(' · ', array_slice(array_unique($others[$category]), 0, 3))];
            }
        }

        return [array_slice($figures, 0, 6), $overlap];
    }

    /** Group posting quantities by named destination, keeping production, formats and units distinct. */
    private static function publication(string $metric, string $unit): ?array
    {
        $metric = preg_replace('/\s+/u', ' ', trim($metric));
        $destination = '(?:\s+(?:trên|tại|lên)\s+(.+))?';
        if (preg_match('/^(.+?)\s+(?:đã\s+)?đăng(?:\s+tải)?'.$destination.'$/iu', $metric, $m)
            || preg_match('/^(?:đã\s+)?đăng(?:\s+tải)?\s+(.+?)'.$destination.'$/iu', $metric, $m)) {
            $item = mb_strtolower(trim($m[1]));
            $channel = trim($m[2] ?? '');
        } else {
            return null;
        }
        if (preg_match('/\b(?:các|nhóm|tổng|tất cả|nhiều|hai|ba|\d+)\s+kênh|\s+và\s+|[,;]/iu', $channel)) {
            return null;
        }
        $kind = match (mb_strtolower(trim($unit))) {
            'ảnh', 'hình' => in_array($item, ['ảnh', 'hình'], true) ? 'ảnh' : null,
            'video', 'clip', 'video/reel' => $item === 'video/reel' ? 'video/Reel' : (in_array($item, ['video', 'clip'], true) ? 'video' : null),
            'reel', 'reels' => in_array($item, ['reel', 'reels'], true) ? 'reel' : null,
            'bài' => match ($item) {
                'bài ảnh' => 'bài ảnh', 'bài video' => 'bài video', 'bài reel' => 'bài Reel',
                'bài', 'bài content', 'bài nội dung' => 'bài', default => null,
            },
            default => null,
        };
        if ($kind === null) {
            return null;
        }
        $platform = match (true) {
            preg_match('/^zalo/iu', $channel) === 1 => 'Zalo',
            preg_match('/^tik\s*tok\b/iu', $channel) === 1 => 'TikTok',
            preg_match('/^(?:facebook|fanpage)\b/iu', $channel) === 1 => 'Facebook',
            preg_match('/^instagram\b/iu', $channel) === 1 => 'Instagram',
            preg_match('/^youtube\b/iu', $channel) === 1 => 'YouTube',
            default => '',
        };

        return [$platform, $channel, $kind];
    }

    private static function family(string $kind): string
    {
        return in_array($kind, ['video', 'reel', 'video/Reel'], true) ? 'video/Reel' : $kind;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, ',', '.'), '0'), ',');
    }
}
