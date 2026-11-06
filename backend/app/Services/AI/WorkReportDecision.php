<?php

namespace App\Services\AI;

use RuntimeException;

/** Management proposals remain suggestions, never assigned tasks or verified outcomes. */
final class WorkReportDecision
{
    public const FIELDS = ['rationale', 'impact', 'action', 'expected_outcome', 'verification', 'decision_needed'];

    public static function schema(array $claim): array
    {
        $claim['properties']['priority'] = ['type' => 'string', 'enum' => ['high', 'normal']];
        foreach (self::FIELDS as $field) {
            $claim['properties'][$field] = ['type' => 'string'];
        }
        $claim['required'] = array_keys($claim['properties']);

        return $claim;
    }

    public static function validate(array $claim, bool $currentContract): void
    {
        $fields = ['text', 'evidence', 'priority', ...self::FIELDS];
        // Historic results stay readable/validatable without inventing missing reasoning.
        if (! $currentContract && count($claim) === 2 && ! array_diff(array_keys($claim), ['text', 'evidence'])) {
            return;
        }
        if (array_diff(array_keys($claim), $fields) || array_diff($fields, array_keys($claim))
            || ! in_array($claim['priority'] ?? null, ['high', 'normal'], true)
            || ! str_starts_with($claim['text'] ?? '', 'Đề xuất: ')) {
            throw new RuntimeException('AI_INVALID_SCHEMA');
        }
        foreach (self::FIELDS as $field) {
            if (! is_string($claim[$field]) || strlen($claim[$field]) > 2000
                || ($field !== 'decision_needed' && trim($claim[$field]) === '')) {
                throw new RuntimeException('AI_INVALID_SCHEMA');
            }
        }
    }

    public static function fake(array $claim): array
    {
        return [...$claim, 'priority' => 'normal',
            'rationale' => 'Nguồn báo cáo ghi nhận công việc đang vướng mắc.',
            'impact' => 'Chưa đủ thông tin để xác định mức ảnh hưởng của vướng mắc.',
            'action' => 'Trao đổi với người báo cáo về nguyên nhân và hỗ trợ cần thiết.',
            'expected_outcome' => 'Làm rõ trở ngại và bước xử lý phù hợp.',
            'verification' => 'Đối chiếu câu trả lời với cập nhật tiếp theo của chính công việc này.',
            'decision_needed' => ''];
    }
}
