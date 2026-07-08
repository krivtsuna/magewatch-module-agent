<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Collector;

/**
 * Filters Magento var/log/payment.log lines down to payment failures and declines.
 */
class PaymentLogParser
{
    public const MAX_MESSAGES = 5;

    public const MAX_MESSAGE_LENGTH = 200;

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
            if ($line === '' || ! self::isInterestingLine($line)) {
                continue;
            }

            $message = self::normalizeLine($line);
            if ($message !== '') {
                $messages[] = $message;
            }
        }

        return array_slice(array_values(array_unique($messages)), -self::MAX_MESSAGES);
    }

    public static function isInterestingLine(string $line): bool
    {
        if (preg_match('/\.(ERROR|CRITICAL):/i', $line)) {
            return true;
        }

        $lower = strtolower($line);

        foreach ([
            'declined',
            'payment failed',
            'payment failure',
            'failed payment',
            'rejected',
            'denied',
            'timeout',
            'chargeback',
            'fraud',
            'authentication required',
            'insufficient funds',
            'invalid card',
            'card error',
            'gateway error',
            'capture failed',
            'refund failed',
        ] as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }

        return false;
    }

    public static function normalizeLine(string $line): string
    {
        if (preg_match('/^\[[^\]]+\]\s+\S+\.(?:ERROR|CRITICAL|WARNING|INFO|DEBUG):\s*(.+)$/', $line, $matches)) {
            return mb_substr(trim($matches[1]), 0, self::MAX_MESSAGE_LENGTH);
        }

        if (preg_match('/^\[[^\]]+\]\s+(.+)$/', $line, $matches)) {
            return mb_substr(trim($matches[1]), 0, self::MAX_MESSAGE_LENGTH);
        }

        return mb_substr($line, 0, self::MAX_MESSAGE_LENGTH);
    }
}
