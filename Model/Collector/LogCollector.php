<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Collector;

use MageWatch\Agent\Api\CollectorInterface;
use MageWatch\Agent\Model\LogOffset\InitialLogOffset;
use MageWatch\Agent\Model\LogOffset\OffsetReaderInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;

/**
 * Counts new lines appended to var/log/system.log, var/log/exception.log,
 * and var/log/payment.log
 * since the previous cron run, and extracts recent exception messages.
 *
 * Only the delta since the last recorded offset is read, so cost stays
 * flat regardless of historical log size. On first sight of a file (offset 0),
 * reading starts near the last ~7 days instead of byte zero. Log rotation
 * (current size < stored offset) is detected and the bootstrap runs again.
 */
class LogCollector implements CollectorInterface
{
    private const CODE = 'log';

    private const SYSTEM_LOG = 'system.log';
    private const EXCEPTION_LOG = 'exception.log';
    private const PAYMENT_LOG = 'payment.log';

    private const MAX_BYTES_PER_RUN = 5_000_000;

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly OffsetReaderInterface $offsetReader
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function collect(): array
    {
        $logDirectory = $this->filesystem->getDirectoryRead(DirectoryList::LOG);

        [$systemNewLines,] = $this->readDelta($logDirectory, self::SYSTEM_LOG);
        [$exceptionNewLines, $exceptionChunk] = $this->readDelta($logDirectory, self::EXCEPTION_LOG);
        [$paymentNewLines, $paymentChunk] = $this->readDelta($logDirectory, self::PAYMENT_LOG);

        return [
            'logs' => [
                'system_new_lines' => $systemNewLines,
                'exception_new_lines' => $exceptionNewLines,
                'payment_new_lines' => $paymentNewLines,
                'system_log_bytes' => $this->logFileBytes($logDirectory, self::SYSTEM_LOG),
                'exception_log_bytes' => $this->logFileBytes($logDirectory, self::EXCEPTION_LOG),
                'payment_log_bytes' => $this->logFileBytes($logDirectory, self::PAYMENT_LOG),
                'recent_exceptions' => ExceptionLogParser::extractMessages($exceptionChunk),
                'recent_payment_errors' => PaymentLogParser::extractMessages($paymentChunk),
            ],
        ];
    }

    private function logFileBytes(ReadInterface $logDirectory, string $relativePath): int
    {
        if (!$logDirectory->isExist($relativePath)) {
            return 0;
        }

        return (int) ($logDirectory->stat($relativePath)['size'] ?? 0);
    }

    /**
     * @return array{0: int, 1: string} [new line count, raw new content]
     */
    private function readDelta(ReadInterface $logDirectory, string $relativePath): array
    {
        if (!$logDirectory->isExist($relativePath)) {
            return [0, ''];
        }

        $stat = $logDirectory->stat($relativePath);
        $size = (int) ($stat['size'] ?? 0);

        $absolutePath = $logDirectory->getAbsolutePath($relativePath);
        $storedOffset = $this->offsetReader->getOffset($absolutePath);
        $offset = $storedOffset;

        if ($offset > $size) {
            // File was rotated/truncated since the last run.
            $offset = 0;
        }

        $resolvedOffset = InitialLogOffset::resolve(
            $offset,
            $size,
            (int) ($stat['mtime'] ?? time()),
        );

        if ($resolvedOffset > $storedOffset) {
            // Skip ancient log bytes we will never report (first install or post-rotation).
            $this->offsetReader->setOffset($absolutePath, $resolvedOffset);
            $offset = $resolvedOffset;
        } else {
            $offset = $resolvedOffset;
        }

        if ($offset >= $size) {
            return [0, ''];
        }

        $bytesToRead = min($size - $offset, self::MAX_BYTES_PER_RUN);

        $file = $logDirectory->openFile($relativePath);
        $file->seek($offset);
        $chunk = (string) $file->read($bytesToRead);
        $file->close();

        $lastNewlinePos = strrpos($chunk, "\n");
        if ($lastNewlinePos === false) {
            // No complete line yet; leave the offset alone for next run.
            return [0, ''];
        }

        $completeChunk = substr($chunk, 0, $lastNewlinePos + 1);
        $newLines = substr_count($completeChunk, "\n");

        $this->offsetReader->setOffset($absolutePath, $offset + strlen($completeChunk));

        return [$newLines, $completeChunk];
    }
}
