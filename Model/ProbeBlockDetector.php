<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model;

/**
 * Classifies WAF/Cloudflare challenge pages and extracts a short safe excerpt for SaaS UI.
 */
class ProbeBlockDetector
{
    private const EXCERPT_MAX = 280;

    /**
     * @param  array<string, string>  $headers  lower-case header name => value
     * @return array{kind: ?string, excerpt: ?string, cf_ray: ?string}
     */
    public function analyze(?string $body, array $headers = []): array
    {
        $cfRay = $headers['cf-ray'] ?? null;
        if (is_string($cfRay)) {
            $cfRay = trim($cfRay) !== '' ? trim($cfRay) : null;
        } else {
            $cfRay = null;
        }

        $kind = $this->detectKind($body, $headers);
        $excerpt = $kind !== null ? $this->excerpt($body) : null;

        return [
            'kind' => $kind,
            'excerpt' => $excerpt,
            'cf_ray' => $cfRay,
        ];
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function detectKind(?string $body, array $headers = []): ?string
    {
        $normalizedBody = is_string($body) ? strtolower($body) : '';
        $server = strtolower($headers['server'] ?? '');
        $cfMitigated = strtolower($headers['cf-mitigated'] ?? '');

        if (
            $cfMitigated !== ''
            || isset($headers['cf-ray'])
            || str_contains($server, 'cloudflare')
            || str_contains($normalizedBody, 'just a moment')
            || str_contains($normalizedBody, 'attention required')
            || str_contains($normalizedBody, 'cf-browser-verification')
            || str_contains($normalizedBody, 'challenge-platform')
            || str_contains($normalizedBody, 'cloudflare')
        ) {
            if (
                $cfMitigated === 'challenge'
                || str_contains($normalizedBody, 'just a moment')
                || str_contains($normalizedBody, 'cf-browser-verification')
                || str_contains($normalizedBody, 'challenge-platform')
            ) {
                return 'cloudflare_challenge';
            }

            return 'cloudflare';
        }

        if ($normalizedBody !== '' && (
            str_contains($normalizedBody, 'access denied')
            || str_contains($normalizedBody, 'request blocked')
            || str_contains($normalizedBody, 'forbidden')
        )) {
            return 'waf';
        }

        return null;
    }

    public function excerpt(?string $body): ?string
    {
        if ($body === null || $body === '') {
            return null;
        }

        $title = null;
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $matches) === 1) {
            $title = html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
        }

        $plain = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain);

        $text = $title !== null && $title !== ''
            ? ($plain !== '' && ! str_contains(strtolower($plain), strtolower($title))
                ? $title.' — '.$plain
                : $title)
            : $plain;

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > self::EXCERPT_MAX) {
            return rtrim(mb_substr($text, 0, self::EXCERPT_MAX - 1)).'…';
        }

        return $text;
    }
}
