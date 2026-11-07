<?php

namespace App\Services\Mail;

/** Versioned, server-owned layout. User input is always plain text, never HTML. */
final class MailPresentation
{
    public const LAYOUT = 'readable_v1';

    public static function render(string $text): string
    {
        $blocks = [];
        $paragraph = [];
        $items = [];
        $flush = function () use (&$blocks, &$paragraph, &$items): void {
            if ($paragraph !== []) {
                $blocks[] = '<p style="margin:0 0 16px;">'.self::inline(implode("\n", $paragraph)).'</p>';
                $paragraph = [];
            }
            if ($items !== []) {
                $blocks[] = '<ul style="margin:0 0 22px;padding-left:24px;">'.implode('', $items).'</ul>';
                $items = [];
            }
        };
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            if (trim($line) === '') {
                $flush();
            } elseif (preg_match('/^[1-4]\. (?:Kết quả trong kỳ|Công việc đang tiếp tục|Vướng mắc cần theo dõi|Việc cần xem xét và quyết định)$/u', $line)) {
                $flush();
                $blocks[] = '<h2 style="margin:28px 0 14px;padding-bottom:10px;border-bottom:1px solid #e5e7eb;color:#8b633a;font-size:19px;line-height:1.5;">'.self::escape($line).'</h2>';
            } elseif (preg_match('/^(?:BÁO CÁO|KẾ HOẠCH)\b/u', $line)) {
                $flush();
                $blocks[] = '<h1 style="margin:8px 0 20px;color:#1f2937;font-size:24px;line-height:1.4;">'.self::escape($line).'</h1>';
            } elseif (str_starts_with($line, '- ')) {
                if ($paragraph !== []) {
                    $flush();
                }
                $content = substr($line, 2);
                $topic = explode(' — ', $content, 2);
                $formatted = count($topic) === 2 && mb_strlen($topic[0]) <= 100
                    ? '<strong>'.self::inline($topic[0]).'</strong> — '.self::inline($topic[1])
                    : self::inline($content);
                $items[] = '<li style="margin:0 0 14px;padding-left:4px;">'.$formatted.'</li>';
            } else {
                if ($items !== []) {
                    $flush();
                }
                $paragraph[] = $line;
            }
        }
        $flush();

        // Inline CSS and presentation tables work in Gmail and do not fetch fonts/images.
        // Preserve this renderer for messages already queued with readable_v1.
        return '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; base-uri \'none\'; form-action \'none\'">'
            .'</head><body style="margin:0;padding:0;background:#f8f9fb;color:#253044;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.7;">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;"><tr><td align="center" style="padding:16px 8px;">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;max-width:760px;border-collapse:collapse;background:#ffffff;border-top:4px solid #8b633a;"><tr>'
            .'<td style="padding:24px 20px;overflow-wrap:anywhere;word-break:break-word;">'.implode('', $blocks).'</td>'
            .'</tr></table></td></tr></table></body></html>';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function inline(string $text): string
    {
        return implode('', array_map(function (string $part): string {
            $escaped = self::escape($part);
            if (str_starts_with($part, 'https://') && filter_var($part, FILTER_VALIDATE_URL)) {
                return '<a href="'.$escaped.'" target="_blank" rel="noopener noreferrer" style="color:#8b633a;text-decoration:underline;overflow-wrap:anywhere;">'.$escaped.'</a>';
            }

            return str_replace("\n", '<br>', $escaped);
        }, preg_split('/(https:\/\/[^\s<>]+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE)));
    }
}
