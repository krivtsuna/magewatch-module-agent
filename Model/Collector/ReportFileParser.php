<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Collector;

/**
 * Parses Magento var/report files (JSON array written by the exception handler).
 */
class ReportFileParser
{
    public const MAX_MESSAGE_LENGTH = 300;

    /**
     * @return array{message: string, url: ?string, class: ?string}
     */
    public static function parse(string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return ['message' => '', 'url' => null, 'class' => null];
        }

        $data = json_decode($content, true);
        if (! is_array($data)) {
            return [
                'message' => mb_substr($content, 0, self::MAX_MESSAGE_LENGTH),
                'url' => null,
                'class' => self::extractClass($content),
            ];
        }

        $message = (string) ($data[0] ?? $data['exception'] ?? $data['message'] ?? '');
        if ($message === '' && isset($data[1]) && is_string($data[1])) {
            $message = mb_substr(trim(explode("\n", $data[1])[0]), 0, self::MAX_MESSAGE_LENGTH);
        }

        $url = self::extractUrl($data);
        $class = self::extractClass($message !== '' ? $message : $content);

        return [
            'message' => mb_substr($message, 0, self::MAX_MESSAGE_LENGTH),
            'url' => is_string($url) && $url !== '' ? $url : null,
            'class' => $class,
        ];
    }

    /**
     * @param  array<int|string, mixed>  $data
     */
    private static function extractUrl(array $data): ?string
    {
        if (isset($data['url']) && is_string($data['url'])) {
            return $data['url'];
        }

        $context = $data[2] ?? null;
        if (is_string($context)) {
            $decoded = json_decode($context, true);
            if (is_array($decoded) && isset($decoded['url']) && is_string($decoded['url'])) {
                return $decoded['url'];
            }
        }

        if (is_array($context) && isset($context['url']) && is_string($context['url'])) {
            return $context['url'];
        }

        return null;
    }

    private static function extractClass(string $line): ?string
    {
        if (preg_match('/([A-Za-z0-9_\\\\]+(?:Exception|Error|TypeError|ValueError))/', $line, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
