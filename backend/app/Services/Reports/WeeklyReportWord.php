<?php

namespace App\Services\Reports;

use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use ZipArchive;

final class WeeklyReportWord
{
    private const BULLET_LIST = 1;

    public function download(array $period, array $employees, ?array $company = null, ?string $context = null): BinaryFileResponse
    {
        $date = fn ($s) => implode('/', array_reverse(explode('-', $s)));
        $dates = $date($period['start_date']).' – '.$date($period['end_date']);
        $body = '';
        $lastListId = self::BULLET_LIST;
        if ($company !== null) {
            $body = $this->heading('Tổng kết công việc tuần', $dates);
            if ($context !== null) {
                $body .= $this->p($context);
            }
            $result = $company['result'] ?? [];
            $body .= $this->claimSection('1. Kết quả trong kỳ', [...($result['overview'] ?? []), ...($result['highlights'] ?? [])], 'Chưa có kết quả được tổng hợp trong bản này.')
                .$this->claimSection('2. Công việc đang tiếp tục', $result['ongoing'] ?? [], 'Chưa ghi nhận công việc đang tiếp tục trong bản này.')
                .$this->claimSection('3. Vướng mắc cần theo dõi', [...($result['attention'] ?? []), ...($result['support'] ?? [])], 'Chưa ghi nhận vướng mắc cần theo dõi trong bản này.')
                .$this->clarifications($result['uncertainties'] ?? [], [])
                .$this->decisions($result['recommendations'] ?? [], ++$lastListId);
        }
        foreach ($employees as $person) {
            $body .= $this->heading('Báo cáo tuần của '.$person['name'], $dates, newPage: $body !== '');
            if ($company === null && $context !== null) {
                $body .= $this->p($context);
            }
            if ($person['department_name'] ?? null) {
                $body .= $this->p($person['department_name']);
            }
            if (isset($person['weekly_revenue'])) {
                $body .= $this->p('Doanh thu cá nhân trong tuần', 'H2').$this->p($person['weekly_revenue']['display']);
            }
            if (($person['status'] ?? null) !== 'completed') {
                $body .= $this->p($this->status($person));
            }
            if (($person['result'] ?? null) !== null || ! empty($person['work_groups']) || ! empty($person['work_totals'])) {
                $body .= $this->employeeContent($person, ++$lastListId);
            } elseif (($person['status'] ?? null) === 'completed') {
                $body .= $this->p($this->status($person));
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'kps-weekly-word-');
        if ($path === false) {
            throw new RuntimeException('Không thể tạo tệp Word.');
        }
        try {
            $zip = new ZipArchive;
            if (! copy(resource_path('weekly-report/template.docx'), $path) || $zip->open($path) !== true) {
                throw new RuntimeException('Không thể mở mẫu Word.');
            }
            $this->fillPart($zip, 'word/document.xml', '<!--REPORT_BODY-->', $body);
            $lists = '';
            for ($id = self::BULLET_LIST + 1; $id <= $lastListId; $id++) {
                $lists .= '<w:num w:numId="'.$id.'"><w:abstractNumId w:val="1"/><w:lvlOverride w:ilvl="0"><w:startOverride w:val="1"/></w:lvlOverride></w:num>';
            }
            $this->fillPart($zip, 'word/numbering.xml', '<!--DECISION_LISTS-->', $lists);
            if (! $zip->close()) {
                throw new RuntimeException('Không thể ghi tệp Word.');
            }
            $name = 'KPS_Bao_cao_tuan_'.$period['start_date'].'_'.$period['end_date'].'.docx';

            return response()->download($path, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
        } catch (Throwable $e) {
            @unlink($path);
            throw $e;
        }
    }

    private function fillPart(ZipArchive $zip, string $part, string $marker, string $replacement): void
    {
        $template = $zip->getFromName($part);
        if ($template === false || ! str_contains($template, $marker)
            || ! $zip->addFromString($part, str_replace($marker, $replacement, $template))) {
            throw new RuntimeException('Không thể ghi tệp Word.');
        }
    }

    private function heading(string $title, string $period, bool $newPage = false): string
    {
        return $this->p('KIM PHỤC SẮC', 'Brand', pageBreakBefore: $newPage).$this->p($title, 'Title').$this->p($period);
    }

    private function status(array $employee): string
    {
        return match ($employee['status'] ?? null) {
            'no_data' => 'Không có báo cáo trong kỳ.',
            'completed' => 'Chưa ghi nhận kết quả nổi bật.',
            default => 'Phần tổng hợp của nhân viên chưa hoàn tất.',
        };
    }

    private function quantityTable(array $rows): string
    {
        if (! $rows) {
            return '';
        }
        $labelWidth = 6200;
        $valueWidth = 9386 - $labelWidth;
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="9386" w:type="dxa"/><w:tblBorders>'
            .'<w:bottom w:val="single" w:sz="4" w:color="D9D9D9"/><w:insideH w:val="single" w:sz="4" w:color="D9D9D9"/></w:tblBorders>'
            .'<w:tblCellMar><w:top w:w="50" w:type="dxa"/><w:left w:w="100" w:type="dxa"/><w:bottom w:w="30" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar>'
            .'</w:tblPr><w:tblGrid><w:gridCol w:w="'.$labelWidth.'"/><w:gridCol w:w="'.$valueWidth.'"/></w:tblGrid>';
        array_unshift($rows, [$this->p('Tổng số lượng từ báo cáo', 'TableHeader'), $this->p('Số lượng', 'TableHeader')]);
        foreach ($rows as $index => $row) {
            $xml .= '<w:tr>'.($index === 0 ? '<w:trPr><w:tblHeader/></w:trPr>' : '');
            foreach ($row as $column => $paragraph) {
                $width = $column === 0 ? $labelWidth : $valueWidth;
                $color = $index === 0 ? '<w:shd w:val="clear" w:fill="8B633A"/>' : '';
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="'.$width.'" w:type="dxa"/>'.$color.'</w:tcPr>'.$paragraph.'</w:tc>';
            }
            $xml .= '</w:tr>';
        }

        return $xml.'</w:tbl>';
    }

    private function employeeContent(array $person, int $decisionList): string
    {
        $result = $person['result'] ?? [];
        $groups = $person['work_groups'] ?? [];
        $xml = $this->p('Tổng hợp từ báo cáo', 'H2');
        foreach ($groups as $group) {
            $xml .= $this->p($group['title'], 'GroupTitle').$this->p($group['text'], 'GroupBody');
        }
        $rows = $unclear = [];
        foreach ($person['work_totals'] ?? [] as $metric) {
            $value = $metric['total'] ?? null;
            if (($metric['status'] ?? null) !== 'confirmed' || ! is_numeric($value) || ! is_finite((float) $value) || $value < 0) {
                $unclear[] = $metric;

                continue;
            }
            $amount = rtrim(rtrim(number_format($value, 6, ',', '.'), '0'), ',');
            $rows[] = [$this->p($metric['metric']), $this->p(trim($amount.' '.$metric['unit']))];
        }
        $xml .= $this->quantityTable($rows);
        if (($person['status'] ?? null) !== 'completed' && ($rows || $unclear)) {
            $xml .= $this->p('Số tổng hiện chỉ dùng các phần AI đã đọc thành công; chưa đại diện đầy đủ cho kỳ này.');
        }

        return $xml.$this->clarifications($result['uncertainties'] ?? [], $unclear)
            .$this->claimSection('Nhận xét', [...($result['overview'] ?? []), ...($result['highlights'] ?? []),
                ...($result['ongoing'] ?? []), ...($result['attention'] ?? []), ...($result['support'] ?? [])], 'Chưa có nhận xét AI cho bản tổng hợp này.')
            .$this->decisions($result['recommendations'] ?? [], $decisionList);
    }

    private function clarifications(array $claims, array $metrics): string
    {
        $xml = $this->p('Cần làm rõ', 'H2');
        foreach ($metrics as $metric) {
            $xml .= $this->p($metric['metric'].($metric['unit'] === '' ? '' : ' ('.$metric['unit'].')'), 'ClaimBullet', listId: self::BULLET_LIST);
        }

        return $xml.$this->claimList($claims).(! $claims && ! $metrics ? $this->p('Chưa ghi nhận nội dung cần làm rõ trong kết quả này.') : '');
    }

    private function claimSection(string $heading, array $claims, string $empty): string
    {
        return $this->p($heading, 'H2').($claims ? $this->claimList($claims) : $this->p($empty));
    }

    private function claimList(array $claims): string
    {
        $xml = '';
        foreach ($claims as $claim) {
            $xml .= $this->p($claim['text'], 'ClaimBullet', listId: self::BULLET_LIST);
        }

        return $xml;
    }

    private function decisions(array $claims, int $decisionList): string
    {
        $xml = $this->p('4. Việc cần xem xét và quyết định', 'H2');
        if (! $claims) {
            return $xml.$this->p('Chưa có đề xuất hỗ trợ AI cho bản tổng hợp này.');
        }
        foreach ($claims as $claim) {
            $fields = '';
            foreach (['rationale' => 'Căn cứ', 'impact' => 'Ảnh hưởng', 'action' => 'Hành động đề xuất',
                'expected_outcome' => 'Kết quả mong đợi', 'verification' => 'Cách kiểm chứng', 'decision_needed' => 'Quyết định cần quản lý'] as $field => $label) {
                if (isset($claim[$field]) && $claim[$field] !== '') {
                    $fields .= $this->p($claim[$field], 'DecisionField', label: $label, listId: self::BULLET_LIST);
                }
            }
            $hasPriority = isset($claim['priority']);
            $xml .= $this->p($claim['text'], 'DecisionTitle', keepNext: $hasPriority || $fields !== '', listId: $decisionList);
            if ($hasPriority) {
                $xml .= $this->p($claim['priority'] === 'high' ? 'Ưu tiên cao' : 'Ưu tiên bình thường', 'DecisionPriority', keepNext: $fields !== '');
            }
            $xml .= $fields;
        }

        return $xml;
    }

    private function p(string $text, string $style = 'Normal', ?string $label = null, bool $keepNext = false, bool $pageBreakBefore = false, ?int $listId = null): string
    {
        $prefix = $label === null ? '' : '<w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">'.$this->escape($label).' · </w:t></w:r>';
        $lines = preg_split('/\R/u', $text);
        $runs = array_map(fn ($line) => '<w:t xml:space="preserve">'.$this->escape($line).'</w:t>', $lines);

        $pagination = ($keepNext ? '<w:keepNext/>' : '').($pageBreakBefore ? '<w:pageBreakBefore/>' : '');

        $level = $style === 'DecisionField' ? 1 : 0;
        $numbering = $listId === null ? '' : '<w:numPr><w:ilvl w:val="'.$level.'"/><w:numId w:val="'.$listId.'"/></w:numPr>';

        return '<w:p><w:pPr><w:pStyle w:val="'.$style.'"/>'.$pagination.$numbering.'</w:pPr>'.$prefix.'<w:r>'
            .implode('<w:br/>', $runs).'</w:r></w:p>';
    }

    private function escape(string $text): string
    {
        return htmlspecialchars(preg_replace('/[^\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}\t\r\n]/u', '', $text), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
