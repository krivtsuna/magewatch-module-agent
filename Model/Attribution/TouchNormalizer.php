<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Attribution;

/**
 * Turns one raw browser touch into the source/medium/campaign triple merchants
 * recognise from their ad reporting.
 *
 * Kept server-side on purpose: the capture script only records what it saw, so
 * this mapping can be corrected in a module release without asking every store
 * to redeploy static content.
 */
class TouchNormalizer
{
    public const DIRECT_SOURCE = '(direct)';

    public const NO_MEDIUM = '(none)';

    public const NOT_SET = '(not set)';

    private const MAX_SHORT = 100;

    private const MAX_LONG = 255;

    /** Click-ID parameter => [source, medium]. The network is unambiguous, so it beats a guess from the referrer. */
    private const CLICK_ID_SOURCES = [
        'gclid' => ['google', 'cpc'],
        'gbraid' => ['google', 'cpc'],
        'wbraid' => ['google', 'cpc'],
        'dclid' => ['google', 'display'],
        'msclkid' => ['bing', 'cpc'],
        'yclid' => ['yandex', 'cpc'],
        'fbclid' => ['facebook', 'social'],
        'igshid' => ['instagram', 'social'],
        'ttclid' => ['tiktok', 'social'],
        'twclid' => ['twitter', 'social'],
        'epik' => ['pinterest', 'social'],
        'irclickid' => ['impact', 'affiliate'],
    ];

    /** Host label => canonical source name. Matched against every dot-separated label, so google.co.uk works. */
    private const SEARCH_ENGINES = [
        'google' => 'google',
        'bing' => 'bing',
        'duckduckgo' => 'duckduckgo',
        'yahoo' => 'yahoo',
        'yandex' => 'yandex',
        'ecosia' => 'ecosia',
        'baidu' => 'baidu',
        'qwant' => 'qwant',
        'startpage' => 'startpage',
        'seznam' => 'seznam',
        'naver' => 'naver',
        'brave' => 'brave',
    ];

    private const SOCIAL_NETWORKS = [
        'facebook' => 'facebook',
        'instagram' => 'instagram',
        'linkedin' => 'linkedin',
        'pinterest' => 'pinterest',
        'tiktok' => 'tiktok',
        'reddit' => 'reddit',
        'youtube' => 'youtube',
        'twitter' => 'twitter',
        'snapchat' => 'snapchat',
        'telegram' => 'telegram',
        'whatsapp' => 'whatsapp',
    ];

    /** Hosts too short to be matched by label. */
    private const EXACT_HOSTS = [
        't.co' => ['twitter', 'social'],
        'x.com' => ['twitter', 'social'],
        'lm.facebook.com' => ['facebook', 'social'],
    ];

    /**
     * @param  array<string, mixed>|null  $touch
     * @return array{
     *     source: string, medium: string, campaign: string, term: ?string, content: ?string,
     *     referrer_host: ?string, landing_path: ?string, touched_at: ?string,
     *     click_id_type: ?string, click_id: ?string
     * }
     */
    public function normalize(?array $touch): array
    {
        $touch ??= [];

        $clickIdType = $this->short($touch['ci'] ?? null);
        $clickId = $this->long($touch['cv'] ?? null);
        $referrerHost = $this->host($touch['r'] ?? null);

        [$source, $medium] = $this->resolveSourceAndMedium($touch, $clickIdType, $referrerHost);

        return [
            'source' => $source,
            'medium' => $medium,
            'campaign' => $this->long($touch['campaign'] ?? null) ?? self::NOT_SET,
            'term' => $this->long($touch['term'] ?? null),
            'content' => $this->long($touch['content'] ?? null),
            'referrer_host' => $referrerHost,
            'landing_path' => $this->long($touch['lp'] ?? null),
            'touched_at' => $this->timestamp($touch['ts'] ?? null),
            'click_id_type' => $clickIdType !== null && isset(self::CLICK_ID_SOURCES[$clickIdType]) ? $clickIdType : null,
            'click_id' => $clickId,
        ];
    }

    /**
     * @param  array<string, mixed>  $touch
     * @return array{0: string, 1: string}
     */
    private function resolveSourceAndMedium(array $touch, ?string $clickIdType, ?string $referrerHost): array
    {
        $utmSource = $this->short($touch['source'] ?? null);
        $utmMedium = $this->short($touch['medium'] ?? null);

        // An explicit utm_source is the merchant's own tagging — always respect it.
        if ($utmSource !== null) {
            return [$utmSource, $utmMedium ?? $this->clickIdMedium($clickIdType) ?? self::NO_MEDIUM];
        }

        if ($clickIdType !== null && isset(self::CLICK_ID_SOURCES[$clickIdType])) {
            [$source, $medium] = self::CLICK_ID_SOURCES[$clickIdType];

            return [$source, $utmMedium ?? $medium];
        }

        if ($referrerHost !== null) {
            [$source, $medium] = $this->classifyReferrer($referrerHost);

            return [$source, $utmMedium ?? $medium];
        }

        return [self::DIRECT_SOURCE, $utmMedium ?? self::NO_MEDIUM];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function classifyReferrer(string $host): array
    {
        if (isset(self::EXACT_HOSTS[$host])) {
            return self::EXACT_HOSTS[$host];
        }

        $labels = explode('.', $host);

        foreach ($labels as $label) {
            if (isset(self::SEARCH_ENGINES[$label])) {
                return [self::SEARCH_ENGINES[$label], 'organic'];
            }
            if (isset(self::SOCIAL_NETWORKS[$label])) {
                return [self::SOCIAL_NETWORKS[$label], 'social'];
            }
        }

        return [$host, 'referral'];
    }

    private function clickIdMedium(?string $clickIdType): ?string
    {
        return $clickIdType !== null ? (self::CLICK_ID_SOURCES[$clickIdType][1] ?? null) : null;
    }

    private function host(mixed $value): ?string
    {
        $host = $this->short($value);
        if ($host === null) {
            return null;
        }

        $host = ltrim(preg_replace('/^www\./', '', $host) ?? $host, '.');

        return preg_match('/^[a-z0-9.-]+$/', $host) === 1 ? $host : null;
    }

    private function short(mixed $value): ?string
    {
        return $this->clean($value, self::MAX_SHORT);
    }

    private function long(mixed $value): ?string
    {
        return $this->clean($value, self::MAX_LONG);
    }

    private function clean(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = trim(preg_replace('/[\x00-\x1f\x7f]+/u', '', $value) ?? '');
        $clean = mb_strtolower(preg_replace('/\s+/', ' ', $clean) ?? $clean);

        if ($clean === '') {
            return null;
        }

        return mb_substr($clean, 0, $maxLength);
    }

    /**
     * Cookie timestamps are client clocks — accept only plausible values.
     */
    private function timestamp(mixed $value): ?string
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        $ts = (int) $value;
        if ($ts < 1577836800 || $ts > time() + 86400) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $ts);
    }
}
