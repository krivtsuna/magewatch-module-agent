<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model\Attribution;

use MageWatch\Agent\Model\Attribution\AttributionCookie;
use PHPUnit\Framework\TestCase;

class AttributionCookieTest extends TestCase
{
    private AttributionCookie $cookie;

    protected function setUp(): void
    {
        $this->cookie = new AttributionCookie;
    }

    public function test_valid_cookie_returns_first_and_last(): void
    {
        $raw = json_encode([
            'v' => 1,
            'f' => ['source' => 'google', 'medium' => 'cpc'],
            'l' => ['source' => 'newsletter', 'medium' => 'email'],
        ], JSON_THROW_ON_ERROR);

        $parsed = $this->cookie->parse($raw);

        $this->assertNotNull($parsed);
        $this->assertSame('google', $parsed['first']['source']);
        $this->assertSame('newsletter', $parsed['last']['source']);
    }

    public function test_missing_first_falls_back_to_last(): void
    {
        $raw = json_encode([
            'v' => 1,
            'f' => null,
            'l' => ['source' => 'google'],
        ], JSON_THROW_ON_ERROR);

        $parsed = $this->cookie->parse($raw);

        $this->assertSame('google', $parsed['first']['source']);
        $this->assertSame('google', $parsed['last']['source']);
    }

    public function test_wrong_version_or_garbage_is_ignored(): void
    {
        $this->assertNull($this->cookie->parse(null));
        $this->assertNull($this->cookie->parse(''));
        $this->assertNull($this->cookie->parse('not-json'));
        $this->assertNull($this->cookie->parse('{"v":2,"f":{"source":"x"}}'));
        $this->assertNull($this->cookie->parse(str_repeat('x', 3000)));
    }

    public function test_non_scalar_touch_values_are_stripped(): void
    {
        $raw = json_encode([
            'v' => 1,
            'f' => ['source' => 'google', 'evil' => ['nested' => true]],
            'l' => ['source' => 'google'],
        ], JSON_THROW_ON_ERROR);

        $parsed = $this->cookie->parse($raw);

        $this->assertSame(['source' => 'google'], $parsed['first']);
    }
}
