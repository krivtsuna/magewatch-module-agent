<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Collector;

/**
 * Pulls human-readable exception messages from Magento exception.log chunks.
 */
class ExceptionLogParser
{
    public const MAX_MESSAGES = 25;

    public const MAX_MESSAGE_LENGTH = 240;

    /**
     * @return string[]
     */
    public static function extractMessages(string $chunk): array
    {
        if ($chunk === '') {
            return [];
        }

        $messages = [];

        foreach (preg_split('/\r\n|\r|\n/', $chunk) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $message = self::parseLine($line);
            if ($message !== null && $message !== '') {
                $messages[] = $message;
            }
        }

        return array_slice(array_values(array_unique($messages)), -self::MAX_MESSAGES);
    }

    public static function parseLine(string $line): ?string
    {
        if (str_starts_with($line, '{')) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $fromJson = self::parseExceptionObjectString((string) ($decoded['exception'] ?? ''));
                if ($fromJson !== null) {
                    return $fromJson;
                }

                foreach (['message', 'msg'] as $key) {
                    if (is_string($decoded[$key] ?? null) && trim($decoded[$key]) !== '') {
                        return mb_substr(trim($decoded[$key]), 0, self::MAX_MESSAGE_LENGTH);
                    }
                }
            }
        }

        if (preg_match('/Report ID:\s*[^;]+;\s*Message:\s*(.+)$/i', $line, $matches)) {
            return mb_substr(trim($matches[1]), 0, self::MAX_MESSAGE_LENGTH);
        }

        if (preg_match('/^\[[^\]]+\]\s+\S+\.(?:ERROR|CRITICAL|WARNING):\s*(.+)$/', $line, $matches)) {
            return mb_substr(trim($matches[1]), 0, self::MAX_MESSAGE_LENGTH);
        }

        if (preg_match('/^\[[^\]]+\]\s+\S+\.\S+:\s*(.+)$/', $line, $matches)) {
            return mb_substr(trim($matches[1]), 0, self::MAX_MESSAGE_LENGTH);
        }

        if (preg_match('/\(([^\)]+(?:Exception|Error)[^\)]*)\)/', $line, $matches)) {
            $parsed = self::parseExceptionObjectString('('.$matches[1].')');
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    private static function parseExceptionObjectString(string $exception): ?string
    {
        if ($exception === '') {
            return null;
        }

        if (preg_match('/\(([^\(]+)\(code:\s*\d+\):\s*(.+?)(?:\s+at\s+|\)$)/s', $exception, $matches)) {
            $class = trim($matches[1]);
            $message = trim($matches[2]);

            if ($message !== '') {
                $shortClass = str_contains($class, '\\') ? substr($class, (int) strrpos($class, '\\') + 1) : $class;

                return mb_substr("{$shortClass}: {$message}", 0, self::MAX_MESSAGE_LENGTH);
            }
        }

        if (preg_match('/(?:Exception|Error):\s*(.{10,200})/', $exception, $matches)) {
            return mb_substr(trim($matches[1]), 0, self::MAX_MESSAGE_LENGTH);
        }

        return null;
    }
}
