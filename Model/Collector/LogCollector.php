<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Collector;

use MageWatch\Agent\Api\CollectorInterface;
use MageWatch\Agent\Model\LogOffset\OffsetReaderInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;

/**
 * Counts new lines appended to var/log/system.log and var/log/exception.log
 * since the previous cron run, and extracts recent exception messages.
 *
 * Only the delta since the last recorded offset is read, so cost stays
 * flat regardless of historical log size. Log rotation (current size <
 * stored offset) is detected and the offset resets to the start of the
 * new file.
 */
class LogCollector implements CollectorInterface
{
    private const CODE = 'log';

    private const SYSTEM_LOG = 'system.log';
    private const EXCEPTION_LOG = 'exception.log';

    private const MAX_EXCEPTION_MESSAGES = 5;
    private const MAX_MESSAGE_LENGTH = 200;

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

        return [
            'logs' => [
                'system_new_lines' => $systemNewLines,
                'exception_new_lines' => $exceptionNewLines,
                'recent_exceptions' => $this->extractExceptionMessages($exceptionChunk),
            ],
        ];
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
        $offset = $this->offsetReader->getOffset($absolutePath);

        if ($offset > $size) {
            // File was rotated/truncated since the last run.
            $offset = 0;
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

    /**
     * @return string[]
     */
    private function extractExceptionMessages(string $chunk): array
    {
        if ($chunk === '') {
            return [];
        }

        $messages = [];
        foreach (preg_split('/\r\n|\r|\n/', $chunk) as $line) {
            if (preg_match('/^\[[^\]]+\]\s+\S+\.\S+:\s*(.+)$/', $line, $matches)) {
                $message = trim($matches[1]);
                if ($message !== '') {
                    $messages[] = mb_substr($message, 0, self::MAX_MESSAGE_LENGTH);
                }
            }
        }

        return array_slice(array_values(array_unique($messages)), -self::MAX_EXCEPTION_MESSAGES);
    }
}
