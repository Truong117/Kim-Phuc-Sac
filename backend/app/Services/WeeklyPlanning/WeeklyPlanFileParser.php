<?php

namespace App\Services\WeeklyPlanning;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

class WeeklyPlanFileParser
{
    /** @var array<string, list<string>> */
    private const HEADER_ALIASES = [
        'employee_identifier' => ['email', 'email nhan vien', 'email hoac ma nhan vien', 'ma nhan vien', 'ma nv', 'nhan vien phu trach'],
        'employee_name' => ['ho ten', 'ho ten nhan vien', 'ten nhan vien', 'nhan vien'],
        'department_name' => ['phong ban', 'bo phan', 'don vi'],
        'title' => ['noi dung cong viec', 'cong viec', 'dau viec', 'tieu de'],
        'expected_result' => ['ket qua can dat', 'ket qua mong doi', 'ket qua'],
        'start_date' => ['ngay bat dau', 'bat dau'],
        'due_date' => ['han hoan thanh', 'deadline', 'ngay hoan thanh'],
        'priority' => ['muc do uu tien', 'muc uu tien', 'uu tien'],
        'note' => ['ghi chu', 'note'],
    ];

    /**
     * @param  array<string, string>  $mapping
     * @return array{headers: list<string>, rows: list<array<string, mixed>>, errors: list<string>, needs_mapping: bool}
     */
    public function parse(string $path, string $extension, array $mapping = []): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Không thể mở tệp OOXML.');
        }

        try {
            $this->guardArchive($zip);
            $this->guardPackageSignature($zip, $extension);

            return match ($extension) {
                'xlsx' => $this->parseXlsx($zip, $mapping),
                'docx' => $this->parseDocx($zip, $mapping),
                default => throw new RuntimeException('Định dạng tệp không được hỗ trợ.'),
            };
        } finally {
            $zip->close();
        }
    }

    private function guardPackageSignature(ZipArchive $zip, string $extension): void
    {
        $requiredParts = match ($extension) {
            'xlsx' => ['[Content_Types].xml', 'xl/workbook.xml'],
            'docx' => ['[Content_Types].xml', 'word/document.xml'],
            default => throw new RuntimeException('Định dạng tệp không được hỗ trợ.'),
        };

        foreach ($requiredParts as $part) {
            if ($zip->locateName($part, ZipArchive::FL_NOCASE) === false) {
                throw new RuntimeException('Chữ ký gói OOXML không hợp lệ với phần mở rộng tệp.');
            }
        }
    }

    private function guardArchive(ZipArchive $zip): void
    {
        $maxEntries = (int) config('weekly_plans.max_archive_entries', 250);
        $maxBytes = (int) config('weekly_plans.max_uncompressed_bytes', 52428800);
        $maxRatio = (int) config('weekly_plans.max_compression_ratio', 100);

        if ($zip->numFiles <= 0 || $zip->numFiles > $maxEntries) {
            throw new RuntimeException('Tệp nén có số lượng thành phần không hợp lệ.');
        }

        $totalBytes = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if (! is_array($stat)) {
                throw new RuntimeException('Không thể kiểm tra thành phần trong tệp.');
            }

            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            if ($name === '' || str_starts_with($name, '/') || str_contains($name, '../')) {
                throw new RuntimeException('Tệp chứa đường dẫn không an toàn.');
            }

            $lowerName = mb_strtolower($name);
            if (str_contains($lowerName, 'vbaproject.bin') || str_contains($lowerName, 'externallinks/')) {
                throw new RuntimeException('Tệp chứa macro hoặc liên kết ngoài không được hỗ trợ.');
            }

            $size = (int) ($stat['size'] ?? 0);
            $compressed = (int) ($stat['comp_size'] ?? 0);
            $totalBytes += $size;

            if ($totalBytes > $maxBytes || ($size > 0 && $compressed === 0)) {
                throw new RuntimeException('Dung lượng giải nén của tệp vượt giới hạn.');
            }

            if ($compressed > 0 && ($size / $compressed) > $maxRatio) {
                throw new RuntimeException('Tệp có tỷ lệ nén không an toàn.');
            }

            if (str_ends_with($lowerName, '.xml') || str_ends_with($lowerName, '.rels')) {
                $contents = $zip->getFromIndex($index);
                if (! is_string($contents)) {
                    throw new RuntimeException('Không thể đọc nội dung XML.');
                }

                if (stripos($contents, '<!DOCTYPE') !== false
                    || preg_match("/TargetMode\\s*=\\s*[\"']External[\"']/i", $contents) === 1) {
                    throw new RuntimeException('Tệp chứa XML hoặc liên kết ngoài không an toàn.');
                }
            }
        }
    }

    /**
     * @param  array<string, string>  $mapping
     * @return array{headers: list<string>, rows: list<array<string, mixed>>, errors: list<string>, needs_mapping: bool}
     */
    private function parseXlsx(ZipArchive $zip, array $mapping): array
    {
        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($sharedXml)) {
            $xpath = $this->xpath($sharedXml);
            foreach ($xpath->query('//*[local-name()="si"]') ?: [] as $node) {
                $parts = [];
                foreach ($xpath->query('.//*[local-name()="t"]', $node) ?: [] as $textNode) {
                    $parts[] = $textNode->textContent;
                }
                $sharedStrings[] = implode('', $parts);
            }
        }

        $worksheets = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            if (preg_match('#^xl/worksheets/sheet\\d+\\.xml$#', $name) === 1) {
                $worksheets[] = $name;
            }
        }
        sort($worksheets);

        if ($worksheets === [] || count($worksheets) > (int) config('weekly_plans.max_sheets', 10)) {
            throw new RuntimeException('Số sheet trong tệp không hợp lệ.');
        }

        $allRows = [];
        $detectedHeaders = [];
        $errors = [];
        $remainingRows = (int) config('weekly_plans.max_rows', 1000);

        foreach ($worksheets as $worksheet) {
            $xml = $zip->getFromName($worksheet);
            if (! is_string($xml)) {
                continue;
            }
            if (preg_match('/<[^>]*:?f(?:\\s|>)/i', $xml) === 1) {
                throw new RuntimeException('Công thức Excel không được hỗ trợ trong tệp nhập.');
            }

            $matrix = $this->xlsxMatrix($xml, $sharedStrings);
            if ($matrix === []) {
                continue;
            }

            $headers = array_map(fn (mixed $value): string => trim((string) $value), array_shift($matrix));
            $detectedHeaders = $detectedHeaders === [] ? array_values($headers) : $detectedHeaders;
            $columnMap = $this->resolveColumns($headers, $mapping);

            if (! isset($columnMap['title'], $columnMap['due_date'])) {
                $errors[] = "Không nhận diện được cột công việc hoặc hạn hoàn thành trong {$worksheet}.";

                continue;
            }

            foreach ($matrix as $offset => $row) {
                if ($this->isBlankRow($row)) {
                    continue;
                }
                if (--$remainingRows < 0) {
                    throw new RuntimeException('Tệp vượt quá số dòng cho phép.');
                }
                $allRows[] = $this->canonicalRow($row, $columnMap, $offset + 2);
            }
        }

        return [
            'headers' => $detectedHeaders,
            'rows' => $allRows,
            'errors' => $errors,
            'needs_mapping' => $allRows === [] && $errors !== [],
        ];
    }

    /** @return list<array<int, string>> */
    private function xlsxMatrix(string $xml, array $sharedStrings): array
    {
        $xpath = $this->xpath($xml);
        $matrix = [];

        foreach ($xpath->query('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $rowNode) {
            $row = [];
            foreach ($xpath->query('./*[local-name()="c"]', $rowNode) ?: [] as $cell) {
                if (! $cell instanceof DOMElement) {
                    continue;
                }
                $reference = $cell->getAttribute('r');
                preg_match('/^[A-Z]+/', strtoupper($reference), $matches);
                $column = $this->columnIndex($matches[0] ?? 'A');
                $type = $cell->getAttribute('t');
                $valueNode = $xpath->query('./*[local-name()="v"]', $cell)?->item(0);
                $value = $valueNode?->textContent ?? '';

                if ($type === 's') {
                    $value = $sharedStrings[(int) $value] ?? '';
                } elseif ($type === 'inlineStr') {
                    $parts = [];
                    foreach ($xpath->query('.//*[local-name()="t"]', $cell) ?: [] as $textNode) {
                        $parts[] = $textNode->textContent;
                    }
                    $value = implode('', $parts);
                }
                $row[$column] = trim((string) $value);
            }
            if ($row !== []) {
                $max = max(array_keys($row));
                $matrix[] = array_map(
                    fn (int $column): string => $row[$column] ?? '',
                    range(0, $max),
                );
            }
        }

        return $matrix;
    }

    /**
     * @param  array<string, string>  $mapping
     * @return array{headers: list<string>, rows: list<array<string, mixed>>, errors: list<string>, needs_mapping: bool}
     */
    private function parseDocx(ZipArchive $zip, array $mapping): array
    {
        $xml = $zip->getFromName('word/document.xml');
        if (! is_string($xml)) {
            throw new RuntimeException('Không tìm thấy nội dung Word.');
        }

        $xpath = $this->xpath($xml);
        $rows = [];
        $headers = [];
        $errors = [];
        $remainingRows = (int) config('weekly_plans.max_rows', 1000);

        foreach ($xpath->query('//*[local-name()="tbl"]') ?: [] as $table) {
            $matrix = [];
            foreach ($xpath->query('./*[local-name()="tr"]', $table) ?: [] as $rowNode) {
                $row = [];
                foreach ($xpath->query('./*[local-name()="tc"]', $rowNode) ?: [] as $cellNode) {
                    $parts = [];
                    foreach ($xpath->query('.//*[local-name()="t"]', $cellNode) ?: [] as $textNode) {
                        $parts[] = $textNode->textContent;
                    }
                    $row[] = trim(implode(' ', $parts));
                }
                if (! $this->isBlankRow($row)) {
                    $matrix[] = $row;
                }
            }
            if ($matrix === []) {
                continue;
            }

            $tableHeaders = array_map('trim', array_shift($matrix));
            $headers = $headers === [] ? $tableHeaders : $headers;
            $columnMap = $this->resolveColumns($tableHeaders, $mapping);
            if (! isset($columnMap['title'], $columnMap['due_date'])) {
                $errors[] = 'Không nhận diện được cột công việc hoặc hạn hoàn thành trong bảng Word.';

                continue;
            }
            foreach ($matrix as $offset => $row) {
                if (--$remainingRows < 0) {
                    throw new RuntimeException('Tệp vượt quá số dòng cho phép.');
                }
                $rows[] = $this->canonicalRow($row, $columnMap, $offset + 2);
            }
        }

        if ($headers === []) {
            $errors[] = 'Không tìm thấy bảng có cấu trúc trong tài liệu Word.';
        }

        return [
            'headers' => array_values($headers),
            'rows' => $rows,
            'errors' => $errors,
            'needs_mapping' => $rows === [] && $errors !== [],
        ];
    }

    /** @param list<string> $headers @param array<string, string> $mapping @return array<string, int> */
    private function resolveColumns(array $headers, array $mapping): array
    {
        $normalizedHeaders = array_map($this->normalize(...), $headers);
        $resolved = [];

        foreach (self::HEADER_ALIASES as $canonical => $aliases) {
            $requested = isset($mapping[$canonical]) ? $this->normalize($mapping[$canonical]) : null;
            $candidates = $requested ? [$requested] : $aliases;

            foreach ($candidates as $candidate) {
                $index = array_search($this->normalize($candidate), $normalizedHeaders, true);
                if ($index !== false) {
                    $resolved[$canonical] = $index;
                    break;
                }
            }
        }

        return $resolved;
    }

    /** @param array<int, string> $row @param array<string, int> $columnMap @return array<string, mixed> */
    private function canonicalRow(array $row, array $columnMap, int $sourceRow): array
    {
        $value = fn (string $key): ?string => isset($columnMap[$key])
            ? (($trimmed = trim((string) ($row[$columnMap[$key]] ?? ''))) === '' ? null : $trimmed)
            : null;

        return [
            'source_row' => $sourceRow,
            'employee_identifier' => $value('employee_identifier'),
            'employee_name' => $value('employee_name'),
            'department_name' => $value('department_name'),
            'title' => $value('title'),
            'content' => $value('title'),
            'expected_result' => $value('expected_result'),
            'start_date' => $this->normalizeDate($value('start_date')),
            'due_date' => $this->normalizeDate($value('due_date')),
            'priority' => $this->normalizePriority($value('priority')),
            'note' => $value('note'),
        ];
    }

    private function normalizeDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value) === 1) {
            return $value;
        }
        if (preg_match('#^(\\d{1,2})[/-](\\d{1,2})[/-](\\d{4})$#', $value, $matches) === 1) {
            return sprintf('%04d-%02d-%02d', $matches[3], $matches[2], $matches[1]);
        }
        if (is_numeric($value)) {
            return CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'UTC')
                ->addDays((int) floor((float) $value))
                ->toDateString();
        }

        return $value;
    }

    private function normalizePriority(?string $value): string
    {
        $normalized = $this->normalize($value ?? '');

        return match ($normalized) {
            'thap', 'low' => 'LOW',
            'cao', 'high' => 'HIGH',
            'khan cap', 'urgent' => 'URGENT',
            default => 'MEDIUM',
        };
    }

    private function xpath(string $xml): DOMXPath
    {
        $document = new DOMDocument;
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException('XML trong tệp không hợp lệ.');
        }

        return new DOMXPath($document);
    }

    private function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    /** @param array<int, mixed> $row */
    private function isBlankRow(array $row): bool
    {
        return count(array_filter($row, fn (mixed $value): bool => trim((string) $value) !== '')) === 0;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'á' => 'a', 'à' => 'a', 'ả' => 'a', 'ã' => 'a', 'ạ' => 'a',
            'ă' => 'a', 'ắ' => 'a', 'ằ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a', 'ặ' => 'a',
            'â' => 'a', 'ấ' => 'a', 'ầ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a', 'ậ' => 'a',
            'đ' => 'd', 'é' => 'e', 'è' => 'e', 'ẻ' => 'e', 'ẽ' => 'e', 'ẹ' => 'e',
            'ê' => 'e', 'ế' => 'e', 'ề' => 'e', 'ể' => 'e', 'ễ' => 'e', 'ệ' => 'e',
            'í' => 'i', 'ì' => 'i', 'ỉ' => 'i', 'ĩ' => 'i', 'ị' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ỏ' => 'o', 'õ' => 'o', 'ọ' => 'o',
            'ô' => 'o', 'ố' => 'o', 'ồ' => 'o', 'ổ' => 'o', 'ỗ' => 'o', 'ộ' => 'o',
            'ơ' => 'o', 'ớ' => 'o', 'ờ' => 'o', 'ở' => 'o', 'ỡ' => 'o', 'ợ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ủ' => 'u', 'ũ' => 'u', 'ụ' => 'u',
            'ư' => 'u', 'ứ' => 'u', 'ừ' => 'u', 'ử' => 'u', 'ữ' => 'u', 'ự' => 'u',
            'ý' => 'y', 'ỳ' => 'y', 'ỷ' => 'y', 'ỹ' => 'y', 'ỵ' => 'y',
        ]);

        return trim((string) preg_replace('/[^a-z0-9]+/u', ' ', $value));
    }
}
