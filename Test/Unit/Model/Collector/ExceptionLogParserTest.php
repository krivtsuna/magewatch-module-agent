<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model\Collector;

use MageWatch\Agent\Model\Collector\ExceptionLogParser;
use PHPUnit\Framework\TestCase;

class ExceptionLogParserTest extends TestCase
{
    public function test_parses_monolog_line(): void
    {
        $message = ExceptionLogParser::parseLine(
            '[2026-07-09T07:03:12.345678+00:00] main.CRITICAL: Payment gateway timeout'
        );

        $this->assertSame('Payment gateway timeout', $message);
    }

    public function test_parses_json_exception_line(): void
    {
        $line = '{"exception":"[object] (Magento\\\\Framework\\\\Exception\\\\LocalizedException(code: 0): Unable to place order at \\/var\\/www\\/html\\/vendor\\/magento\\/module-quote\\/Model\\/QuoteManagement.php:123)"}';
        $message = ExceptionLogParser::parseLine($line);

        $this->assertStringContainsString('LocalizedException', (string) $message);
        $this->assertStringContainsString('Unable to place order', (string) $message);
    }
}
