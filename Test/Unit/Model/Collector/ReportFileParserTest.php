<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model\Collector;

use MageWatch\Agent\Model\Collector\ReportFileParser;
use PHPUnit\Framework\TestCase;

class ReportFileParserTest extends TestCase
{
    public function test_parses_standard_magento_json_report(): void
    {
        $content = json_encode([
            0 => 'Magento\\Framework\\Exception\\LocalizedException: Something went wrong',
            1 => "#0 /var/www/html/vendor/magento/framework/App/Http.php(120)",
            2 => json_encode(['url' => 'https://shop.example.com/checkout']),
        ], JSON_THROW_ON_ERROR);

        $parsed = ReportFileParser::parse($content);

        $this->assertStringContainsString('Something went wrong', $parsed['message']);
        $this->assertSame('https://shop.example.com/checkout', $parsed['url']);
        $this->assertStringContainsString('LocalizedException', (string) $parsed['class']);
    }

    public function test_parses_plain_text_fallback(): void
    {
        $parsed = ReportFileParser::parse('RuntimeException: Connection refused');

        $this->assertSame('RuntimeException: Connection refused', $parsed['message']);
        $this->assertSame('RuntimeException', $parsed['class']);
    }
}
