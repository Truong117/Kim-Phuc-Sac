<?php

namespace App\Services\Reports;

use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use ZipArchive;

final class WeeklyReportWord
{
    private const SECTIONS = [
        'overview' => 'Tình hình chung', 'highlights' => 'Kết quả nổi bật', 'ongoing' => 'Công việc đang thực hiện',
        'attention' => 'Vấn đề cần theo dõi', 'support' => 'Nhu cầu hỗ trợ', 'recommendations' => 'Đề xuất',
        'uncertainties' => 'Thông tin cần đối chiếu',
    ];

    public function download(array $period, array $employees, ?array $company = null, ?string $context = null): BinaryFileResponse
    {
        $date = fn ($s) => implode('/', array_reverse(explode('-', $s)));
        $dates = $date($period['start_date']).' – '.$date($period['end_date']);
        $body = '';
        if ($company !== null) {
            $body = $this->heading('Tổng kết công việc tuần', $dates);
            if ($context !== null) {
                $body .= $this->p($context);
            }
            $body .= $this->revenue($company);
            $rows = [];
            foreach ($company['employees'] as $employee) {
                $id = (int) $employee['employee_id'];
                $label = $employees
                    ? '<w:p><w:hyperlink w:anchor="Employee_'.$id.'"><w:r><w:rPr><w:color w:val="195B59"/><w:u w:val="single"/></w:rPr><w:t>'.$this->escape($employee['name']).'</w:t></w:r></w:hyperlink></w:p>'
                    : $this->p($employee['name']);
                $lines = [];
                if (isset($employee['weekly_revenue'])) {
                    $lines[] = 'Doanh thu ghi nhận trong tuần · '.$employee['weekly_revenue']['display'];
                }
                $highlight = $employee['work_groups'][0]['text'] ?? $employee['result']['highlights'][0]['text'] ?? $employee['result']['overview'][0]['text'] ?? null;
                if ($highlight) {
                    $lines[] = $highlight;
                }
                $rows[] = [$label, $this->p($lines ? implode("\n", $lines) : $this->status($employee))];
            }
            $body .= $this->table('Nhân viên', 'Kết quả chính trong kỳ', $rows).$this->claims($company['result'] ?? []);
        }
        foreach ($employees as $person) {
            if ($body !== '') {
                $body .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
            }
            $id = (int) $person['employee_id'];
            $body .= '<w:bookmarkStart w:id="'.$id.'" w:name="Employee_'.$id.'"/>'
                .$this->heading('Báo cáo tuần của '.$person['name'], $dates)
                .'<w:bookmarkEnd w:id="'.$id.'"/>';
            if ($company === null && $context !== null) {
                $body .= $this->p($context);
            }
            if ($person['department_name'] ?? null) {
                $body .= $this->p($person['department_name']);
            }
            $rows = [];
            if (isset($person['weekly_revenue'])) {
                $rows[] = [$this->p('Doanh thu ghi nhận trong tuần'), $this->p($person['weekly_revenue']['display'])];
            }
            foreach ($person['work_totals'] ?? [] as $total) {
                if ($total['status'] === 'confirmed' && $total['total'] !== null) {
                    $quantity = rtrim(rtrim(number_format($total['total'], 6, ',', '.'), '0'), ',');
                    $rows[] = [$this->p($total['metric']), $this->p($quantity.' '.$total['unit'])];
                }
            }
            foreach ($person['work_groups'] ?? [] as $group) {
                $rows[] = [$this->p($group['title']), $this->p($group['text'])];
            }
            $body .= $this->table('Đầu việc', 'Kết quả ghi nhận', $rows);
            if (($person['status'] ?? null) !== 'completed') {
                $body .= $this->p($this->status($person));
            }
            $body .= $this->claims($person['result'] ?? []);
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
            $template = $zip->getFromName('word/document.xml');
            $marker = '<!--REPORT_BODY-->';
            if ($template === false || ! str_contains($template, $marker)
                || ! $zip->addFromString('word/document.xml', str_replace($marker, $body, $template)) || ! $zip->close()) {
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

    private function heading(string $title, string $period): string
    {
        return $this->p('KIM PHỤC SẮC', 'Brand').$this->p($title, 'Title').$this->p($period);
    }

    private function revenue(array $report): string
    {
        return isset($report['weekly_revenue']) ? $this->p('Doanh thu ghi nhận trong tuần', 'H2').$this->p($report['weekly_revenue']['display']) : '';
    }

    private function status(array $employee): string
    {
        return match ($employee['status'] ?? null) {
            'no_data' => 'Không có báo cáo trong kỳ.',
            'completed' => 'Chưa ghi nhận kết quả nổi bật.',
            default => 'Phần tổng hợp của nhân viên chưa hoàn tất.',
        };
    }

    /** All cells are escaped paragraphs, including optional internal employee links. */
    private function table(string $left, string $right, array $rows): string
    {
        if (! $rows) {
            return '';
        }
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="9386" w:type="dxa"/><w:tblBorders>'
            .'<w:bottom w:val="single" w:sz="4" w:color="CDDAD8"/><w:insideH w:val="single" w:sz="4" w:color="CDDAD8"/></w:tblBorders>'
            .'<w:tblCellMar><w:top w:w="50" w:type="dxa"/><w:left w:w="100" w:type="dxa"/><w:bottom w:w="30" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar>'
            .'</w:tblPr><w:tblGrid><w:gridCol w:w="2850"/><w:gridCol w:w="6536"/></w:tblGrid>';
        array_unshift($rows, [$this->p($left), $this->p($right)]);
        foreach ($rows as $index => $row) {
            $xml .= '<w:tr>'.($index === 0 ? '<w:trPr><w:tblHeader/></w:trPr>' : '');
            foreach ($row as $column => $paragraph) {
                $width = $column === 0 ? 2850 : 6536;
                $color = $index === 0 ? '<w:shd w:val="clear" w:fill="DCECE9"/>' : '';
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="'.$width.'" w:type="dxa"/>'.$color.'</w:tcPr>'.$paragraph.'</w:tc>';
            }
            $xml .= '</w:tr>';
        }

        return $xml.'</w:tbl>';
    }

    private function claims(array $result): string
    {
        $xml = '';
        foreach (self::SECTIONS as $key => $heading) {
            if (empty($result[$key])) {
                continue;
            }
            $xml .= $this->p($heading, 'H2');
            foreach ($result[$key] as $claim) {
                $xml .= $this->p($claim['text']);
                foreach (['action' => 'Hành động', 'decision_needed' => 'Quyết định cần chốt', 'expected_outcome' => 'Kết quả dự kiến',
                    'rationale' => 'Căn cứ', 'impact' => 'Tác động', 'verification' => 'Cách kiểm tra'] as $field => $label) {
                    if (! empty($claim[$field]) && $claim[$field] !== $claim['text']) {
                        $xml .= $this->p($label.' · '.$claim[$field]);
                    }
                }
            }
        }

        return $xml;
    }

    private function p(string $text, string $style = 'Normal'): string
    {
        $lines = preg_split('/\R/u', $text);
        $runs = array_map(fn ($line) => '<w:t xml:space="preserve">'.$this->escape($line).'</w:t>', $lines);

        return '<w:p><w:pPr><w:pStyle w:val="'.$style.'"/></w:pPr><w:r>'.implode('<w:br/>', $runs).'</w:r></w:p>';
    }

    private function escape(string $text): string
    {
        return htmlspecialchars(preg_replace('/[^\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}\t\r\n]/u', '', $text), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
