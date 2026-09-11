<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Attribution;

/**
 * Reads the `mw_attr` cookie written by view/frontend/web/js/attribution.js.
 *
 * The cookie is attacker-controlled, so everything here is defensive: bad JSON,
 * a wrong version, oversized values or unexpected types all degrade to "no
 * attribution" rather than throwing during checkout.
 */
class AttributionCookie
{
    public const NAME = 'mw_attr';

    private const VERSION = 1;

    private const MAX_RAW_LENGTH = 2048;

    private const MAX_DECODE_DEPTH = 4;

    /**
     * @return array{first: ?array<string, mixed>, last: ?array<string, mixed>}|null
     */
    public function parse(?string $raw): ?array
    {
        if ($raw === null || $raw === '' || strlen($raw) > self::MAX_RAW_LENGTH) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, self::MAX_DECODE_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }

        if (! is_array($decoded) || ($decoded['v'] ?? null) !== self::VERSION) {
            return null;
        }

        $first = $this->touch($decoded['f'] ?? null);
        $last = $this->touch($decoded['l'] ?? null);

        if ($first === null && $last === null) {
            return null;
        }

        return [
            // A cookie can legitimately carry only one side if it was written
            // by an older visit; fall back so the order still gets a source.
            'first' => $first ?? $last,
            'last' => $last ?? $first,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function touch(mixed $value): ?array
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        $clean = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && (is_string($item) || is_int($item))) {
                $clean[$key] = $item;
            }
        }

        return $clean === [] ? null : $clean;
    }
}
