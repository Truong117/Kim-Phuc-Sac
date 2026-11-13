<?php

namespace App\Services;

/** Conservative literal enumeration, never an estimated count or a provider call. */
final class NamedWorkOutputs
{
    public static function identity(string $name): string
    {
        $name = class_exists(\Normalizer::class) ? \Normalizer::normalize($name, \Normalizer::FORM_KC) : $name;

        return hash('sha256', mb_strtolower(preg_replace('/\s+/u', ' ', trim($name))));
    }

    public static function videos(string $text): array
    {
        if (! preg_match('/hoàn thành|hoàn thiện|đã (?:dựng|edit|biên tập)/iu', $text)
            || preg_match('/chưa|không|dự kiến|kế hoạch|sẽ |đang |cần |chờ |lũy kế|luỹ kế|nhắc lại|đã báo cáo/iu', $text)) {
            return [];
        }
        $quoted = '(?:"[^"\r\n]{1,200}"|“[^”\r\n]{1,200}”|\x{2018}[^\x{2019}\r\n]{1,200}\x{2019})';
        preg_match_all('/(?:hoàn thành|hoàn thiện|đã (?:dựng|edit|biên tập))\s+(?:xong\s+)?(?:các\s+)?(?:\d+\s+)?(?:video|clip)\s*(?::\s*(?:(?:video|clip)\s+)?)?('.$quoted.'(?:\s*(?:,|;|và|&)\s*(?:(?:video|clip)\s*)?'.$quoted.')*)/iu', $text, $matches);
        $names = [];
        foreach ($matches[1] as $list) {
            preg_match_all('/"([^"\r\n]+)"|“([^”\r\n]+)”|\x{2018}([^\x{2019}\r\n]+)\x{2019}/u', $list, $quotedNames, PREG_SET_ORDER);
            foreach ($quotedNames as $match) {
                $name = trim(collect(array_slice($match, 1))->first(fn ($value) => $value !== '') ?? '');
                if ($name !== '' && ! preg_match('~https?://~iu', $name)) {
                    $names[self::identity($name)] = $name;
                }
            }
        }

        return array_values($names);
    }
}
