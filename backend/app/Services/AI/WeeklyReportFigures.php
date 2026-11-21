<?php

namespace App\Services\AI;

use Illuminate\Support\Str;

/** Present saved quantities for one employee. Evidence and uncertain totals stay in work_totals. */
final class WeeklyReportFigures
{
    public static function build(array $totals, ?array $weeklyRevenue = null): array
    {
        $rows = $buckets = $blocked = $labels = $kinds = $evidence = [];
        foreach ($totals as $total) {
            $amount = $total['total'] ?? null;
            if (($total['status'] ?? null) !== 'confirmed' || ! is_numeric($amount) || ! is_finite((float) $amount) || $amount < 0) {
                continue;
            }
            if (self::duplicatesRevenue($total, $weeklyRevenue)) {
                continue;
            }
            $index = count($rows);
            $rows[] = ['label' => $total['metric'], 'text' => trim(self::number($amount).' '.$total['unit'])];
            $post = self::publication($total['metric'], $total['unit']);
            if ($post === null) {
                continue;
            }
            [$platform, $channel, $kind] = $post;
            if ($channel === '') {
                // A platform-wide or multi-channel total can cover any of its named destinations.
                $blocked[$platform] = true;

                continue;
            }
            $buckets[$platform][$index] = [$channel, $kind, $amount];
            $channelKey = Str::slug(preg_replace('/^(?:(?:facebook|fb|fanpage|zalo(?:video)?|tik\s*tok|instagram|youtube)\s+)?(?:kênh |trang )?/iu', '', $channel));
            if (isset($labels[$platform][$channelKey]) && $labels[$platform][$channelKey] !== $channel) {
                $blocked[$platform] = true;
            }
            if (in_array($platform, ['', 'Facebook'], true)) {
                $other = $platform === '' ? 'Facebook' : '';
                if (isset($labels[$other][$channelKey])) {
                    $blocked[$other] = $blocked[$platform] = true;
                }
            }
            $labels[$platform][$channelKey] = $channel;
            $previousKinds = $kinds[$platform][$channelKey] ?? [];
            $isPost = str_starts_with($kind, 'bài');
            $isGenericPost = in_array($kind, ['bài', 'bài content', 'bài nội dung'], true);
            if (isset($previousKinds[$kind]) || ($isPost && isset($previousKinds['generic post']))
                || ($isGenericPost && isset($previousKinds['any post']))) {
                // Aliases or a general post count and its subtypes can cover the same publication.
                $blocked[$platform] = true;
            }
            $kinds[$platform][$channelKey][$kind] = true;
            if ($isPost) {
                $kinds[$platform][$channelKey]['any post'] = true;
            }
            if ($isGenericPost) {
                $kinds[$platform][$channelKey]['generic post'] = true;
            }
            $family = in_array($kind, ['video', 'Reel'], true) ? 'video/Reel' : $kind;
            $references = self::references($total);
            if ($references === null) {
                $blocked[$platform] = true;

                continue;
            }
            foreach ($references as $reference) {
                $key = $family.'|'.$reference;
                if (isset($evidence[$key])) {
                    $blocked[$evidence[$key]] = $blocked[$platform] = true;
                } else {
                    $evidence[$key] = $platform;
                }
            }
        }
        foreach ($buckets as $platform => $posts) {
            if (isset($blocked[$platform]) || ($platform === '' && count($posts) === 1)) {
                continue;
            }
            $first = array_key_first($posts);
            $rows[$first] = self::summarize($platform, $posts);
            unset($posts[$first]);
            $rows = array_diff_key($rows, $posts);
        }

        return array_values($rows);
    }

    /** Each reference must identify a distinct, incremental publication in this unit. */
    private static function references(array $total): ?array
    {
        $sum = 0;
        $references = [];
        foreach ($total['entries'] ?? [] as $entry) {
            $quantity = $entry['quantity'] ?? null;
            if (($entry['basis'] ?? null) !== 'incremental' || empty($entry['report_id']) || empty($entry['item_id'])
                || ! is_numeric($quantity) || $quantity < 0 || floor($quantity) != $quantity) {
                return null;
            }
            $sum += $quantity;
            $references[] = 'item:'.$entry['report_id'].':'.$entry['item_id'];
            foreach ($entry['named_outputs'] ?? [] as $name) {
                $references[] = 'output:'.$name;
            }
        }

        return $references && $sum == $total['total'] ? $references : null;
    }

    private static function publication(string $metric, string $unit): ?array
    {
        $metric = Str::squish($metric);
        $destination = '(?:\s+(?:trên|tại|lên)\s+(.+))?';
        if (! preg_match('/^(.+?)\s+(?:đã\s+)?đăng(?:\s+tải)?'.$destination.'$/iu', $metric, $match)
            && ! preg_match('/^(?:đã\s+)?đăng(?:\s+tải)?\s+(.+?)'.$destination.'$/iu', $metric, $match)) {
            return null;
        }
        $kind = match (mb_strtolower($match[1]).'|'.mb_strtolower(trim($unit))) {
            'ảnh|ảnh', 'ảnh|hình', 'hình|hình', 'hình|ảnh' => 'ảnh',
            'video|video', 'clip|clip', 'clip|video', 'video|clip' => 'video',
            'reel|reel', 'reels|reels', 'reel|reels', 'reels|reel' => 'Reel',
            'bài ảnh|bài' => 'bài ảnh', 'bài video|bài' => 'bài video', 'bài reel|bài' => 'bài Reel',
            'bài content|bài' => 'bài content', 'bài nội dung|bài' => 'bài nội dung', 'bài|bài' => 'bài',
            default => null,
        };
        if ($kind === null && ! preg_match('/\b(?:ảnh|hình|video|clip|reels?|bài)\b/iu', $match[1])) {
            return null;
        }
        $channel = $match[2] ?? '';
        $platform = '';
        $name = $channel;
        if (preg_match('/^(facebook|fb|fanpage|tik\s*tok|zalo(?:video)?|instagram|youtube)\b\s*(.*)$/iu', $channel, $scope)) {
            $platform = match (mb_strtolower(preg_replace('/\s+/u', '', $scope[1]))) {
                'facebook', 'fb', 'fanpage' => 'Facebook', 'tiktok' => 'TikTok', 'zalo', 'zalovideo' => 'Zalo',
                'youtube' => 'YouTube', 'instagram' => 'Instagram',
            };
            $name = $scope[2];
        }
        if ($kind === null || ! preg_match('/^\p{Lu}[\p{L}\p{M}\p{N} ._-]*$/u', $name)
            || preg_match('/^(?:kênh|trang)$|\s-\s|\b(?:và|hoặc|các|nhiều|tổng|chung|riêng|khác|toàn|nhóm|từng|mọi|chưa rõ|không rõ|chưa xác định|vệ tinh)\b/iu', $name)) {
            // Unknown media formats/units or ambiguous destinations remain as originally reported.
            return [$platform, '', $kind];
        }

        return [$platform, $channel, $kind];
    }

    private static function summarize(string $platform, array $posts): array
    {
        $quantities = $channels = [];
        foreach ($posts as [$channel, $kind, $amount]) {
            $family = in_array($kind, ['video', 'Reel'], true) ? 'video/Reel' : $kind;
            $channels[$family][$channel] = true;
            $quantities[$family][$kind] = ($quantities[$family][$kind] ?? 0) + $amount;
        }
        $scopes = [];
        foreach ($quantities as $family => $counts) {
            $parts = [];
            foreach ($counts as $kind => $amount) {
                $parts[] = self::number($amount).' '.$kind;
            }
            $value = count($parts) === 1 ? $parts[0] : self::number(array_sum($counts)).' '.$family.' ('.implode(', ', $parts).')';
            $names = array_keys($channels[$family]);
            $scopeKey = $names;
            sort($scopeKey);
            $key = implode('|', $scopeKey);
            $scopes[$key]['names'] ??= $names;
            $scopes[$key]['values'][] = $value;
        }
        $details = [];
        foreach ($scopes as $scope) {
            $details[] = self::join($scope['values']).' trên '.self::join($scope['names']);
        }
        $label = $platform !== '' ? 'Đã đăng trên '.$platform : (isset($quantities['ảnh']) || isset($quantities['video/Reel']) ? 'Ảnh và video/Reel đã đăng' : 'Bài đã đăng');

        return ['label' => $label, 'text' => implode('; ', $details)];
    }

    private static function duplicatesRevenue(array $total, ?array $card): bool
    {
        if (! isset($card['amount']) || ! preg_match('/^doanh (?:thu|số)(?: (?:cá nhân|thực nhận|đã ghi nhận|ghi nhận))?(?: (?:trong |cả )?tuần)?$/iu', trim($total['metric']))) {
            return false;
        }
        if (! preg_match('/^(?:(triệu|tr|nghìn|ngàn|k|tỷ|tỉ)\s*(?:đồng|vnd|vnđ|đ|₫)?|đồng|vnd|vnđ|đ|₫)$/iu', trim($total['unit']), $unit)) {
            return false;
        }
        $scale = match (mb_strtolower($unit[1] ?? '')) {
            'triệu', 'tr' => 1_000_000, 'nghìn', 'ngàn', 'k' => 1000, 'tỷ', 'tỉ' => 1_000_000_000, default => 1,
        };

        return abs($total['total'] * $scale - $card['amount']) < 0.000001;
    }

    private static function join(array $parts): string
    {
        $last = array_pop($parts);

        return $parts ? implode(', ', $parts).' và '.$last : $last;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, ',', '.'), '0'), ',');
    }
}
