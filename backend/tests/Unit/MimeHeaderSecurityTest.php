<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Component\Mime\Header\ParameterizedHeader;

final class MimeHeaderSecurityTest extends TestCase
{
    public function test_valid_attachment_parameters_remain_supported(): void
    {
        $header = new ParameterizedHeader('Content-Disposition', 'attachment', ['filename' => 'Bao cao.pdf']);
        $this->assertSame('Bao cao.pdf', $header->getParameter('filename'));
        $this->assertStringContainsString('filename=', $header->toString());
    }

    #[DataProvider('unsafeNames')]
    public function test_header_parameter_names_cannot_inject_or_split_headers(string $name): void
    {
        $this->expectException(RfcComplianceException::class);
        new ParameterizedHeader('Content-Disposition', 'attachment', [$name => 'value']);
    }

    public static function unsafeNames(): array
    {
        return [
            'CRLF injection' => ["filename\r\nX-Injected: yes"],
            'whitespace' => ['file name'],
            'delimiter' => ['filename;injected'],
        ];
    }
}
