<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model\Transport;

use MageWatch\Agent\Model\Transport\ResponseMessageFormatter;
use PHPUnit\Framework\TestCase;

class ResponseMessageFormatterTest extends TestCase
{
    public function test_detects_cloudflare_challenge_html(): void
    {
        $body = '<!DOCTYPE html><html><title>Just a moment...</title></html>';

        $this->assertTrue(ResponseMessageFormatter::isCloudflareChallenge($body));
        $this->assertStringContainsString(
            'Cloudflare blocked the request',
            (string) ResponseMessageFormatter::forAdmin(403, $body)
        );
    }

    public function test_truncates_long_plain_body(): void
    {
        $body = str_repeat('x', 400);

        $message = ResponseMessageFormatter::forAdmin(500, $body);

        $this->assertNotNull($message);
        $this->assertLessThanOrEqual(290, strlen($message));
    }
}
