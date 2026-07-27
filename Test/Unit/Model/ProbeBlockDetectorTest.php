<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model;

use MageWatch\Agent\Model\ProbeBlockDetector;
use PHPUnit\Framework\TestCase;

class ProbeBlockDetectorTest extends TestCase
{
    public function testDetectsCloudflareChallengeAndExcerpt(): void
    {
        $detector = new ProbeBlockDetector;
        $body = '<!DOCTYPE html><html><head><title>Just a moment...</title></head>'
            .'<body>Enable JavaScript and cookies to continue</body></html>';

        $result = $detector->analyze($body, [
            'server' => 'cloudflare',
            'cf-mitigated' => 'challenge',
            'cf-ray' => '9abc-AMS',
        ]);

        $this->assertSame('cloudflare_challenge', $result['kind']);
        $this->assertSame('9abc-AMS', $result['cf_ray']);
        $this->assertNotNull($result['excerpt']);
        $this->assertStringContainsString('Just a moment', (string) $result['excerpt']);
    }
}
