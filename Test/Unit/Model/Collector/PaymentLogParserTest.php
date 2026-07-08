<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model\Collector;

use MageWatch\Agent\Model\Collector\PaymentLogParser;
use PHPUnit\Framework\TestCase;

class PaymentLogParserTest extends TestCase
{
    public function test_extracts_error_level_lines(): void
    {
        $chunk = <<<'LOG'
[2026-07-08 10:00:01] report.INFO: Payment authorized
[2026-07-08 10:00:02] report.ERROR: Stripe gateway error: card declined
[2026-07-08 10:00:03] report.CRITICAL: Capture failed for order 1001
LOG;

        $messages = PaymentLogParser::extractMessages($chunk);

        $this->assertCount(2, $messages);
        $this->assertStringContainsString('card declined', $messages[0]);
        $this->assertStringContainsString('Capture failed', $messages[1]);
    }

    public function test_extracts_decline_keywords_without_error_level(): void
    {
        $chunk = "[2026-07-08 10:00:00] Payment declined: insufficient funds\n";

        $messages = PaymentLogParser::extractMessages($chunk);

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('insufficient funds', $messages[0]);
    }

    public function test_ignores_benign_info_lines(): void
    {
        $chunk = <<<'LOG'
[2026-07-08 10:00:01] report.INFO: Payment method saved
[2026-07-08 10:00:02] report.DEBUG: Request payload sent to gateway
LOG;

        $this->assertSame([], PaymentLogParser::extractMessages($chunk));
    }
}
