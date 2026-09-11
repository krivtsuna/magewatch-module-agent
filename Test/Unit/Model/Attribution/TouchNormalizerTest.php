<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model\Attribution;

use MageWatch\Agent\Model\Attribution\TouchNormalizer;
use PHPUnit\Framework\TestCase;

class TouchNormalizerTest extends TestCase
{
    private TouchNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new TouchNormalizer;
    }

    public function test_utm_source_wins_over_click_id_and_referrer(): void
    {
        $row = $this->normalizer->normalize([
            'source' => 'Newsletter',
            'medium' => 'Email',
            'campaign' => 'Spring Sale',
            'ci' => 'gclid',
            'cv' => 'abc',
            'r' => 'google.com',
        ]);

        $this->assertSame('newsletter', $row['source']);
        $this->assertSame('email', $row['medium']);
        $this->assertSame('spring sale', $row['campaign']);
        $this->assertSame('gclid', $row['click_id_type']);
        $this->assertSame('abc', $row['click_id']);
    }

    public function test_utm_source_without_medium_uses_click_id_medium(): void
    {
        $row = $this->normalizer->normalize([
            'source' => 'google',
            'ci' => 'gclid',
            'cv' => 'xyz',
        ]);

        $this->assertSame('google', $row['source']);
        $this->assertSame('cpc', $row['medium']);
    }

    public function test_gclid_without_utm_is_google_cpc(): void
    {
        $row = $this->normalizer->normalize([
            'ci' => 'gclid',
            'cv' => 'EAIaIQobChMI',
            'r' => 'facebook.com',
        ]);

        $this->assertSame('google', $row['source']);
        $this->assertSame('cpc', $row['medium']);
        $this->assertSame(TouchNormalizer::NOT_SET, $row['campaign']);
    }

    public function test_fbclid_maps_to_facebook_social(): void
    {
        $row = $this->normalizer->normalize(['ci' => 'fbclid', 'cv' => 'IwAR']);

        $this->assertSame('facebook', $row['source']);
        $this->assertSame('social', $row['medium']);
    }

    public function test_search_engine_referrer_is_organic(): void
    {
        $row = $this->normalizer->normalize(['r' => 'www.google.co.uk']);

        $this->assertSame('google', $row['source']);
        $this->assertSame('organic', $row['medium']);
        $this->assertSame('google.co.uk', $row['referrer_host']);
    }

    public function test_social_referrer_and_unknown_host(): void
    {
        $social = $this->normalizer->normalize(['r' => 'www.instagram.com']);
        $this->assertSame('instagram', $social['source']);
        $this->assertSame('social', $social['medium']);

        $referral = $this->normalizer->normalize(['r' => 'blog.example.com']);
        $this->assertSame('blog.example.com', $referral['source']);
        $this->assertSame('referral', $referral['medium']);
    }

    public function test_empty_touch_is_direct(): void
    {
        $row = $this->normalizer->normalize(['d' => 1, 'lp' => '/']);

        $this->assertSame(TouchNormalizer::DIRECT_SOURCE, $row['source']);
        $this->assertSame(TouchNormalizer::NO_MEDIUM, $row['medium']);
        $this->assertSame('/', $row['landing_path']);
    }

    public function test_implausible_timestamp_is_dropped(): void
    {
        $row = $this->normalizer->normalize(['ts' => 100]);

        $this->assertNull($row['touched_at']);
    }

    public function test_plausible_timestamp_is_utc(): void
    {
        $row = $this->normalizer->normalize(['ts' => 1710000000]);

        $this->assertSame('2024-03-09 16:00:00', $row['touched_at']);
    }
}
