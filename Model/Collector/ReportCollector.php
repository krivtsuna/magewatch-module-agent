<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Collector;

use MageWatch\Agent\Api\CollectorInterface;
use MageWatch\Agent\Model\LogOffset\OffsetReaderInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;

/**
 * Sends summaries of the newest files in Magento var/report (error report dumps).
 */
class ReportCollector implements CollectorInterface
{
    private const CODE = 'report';

    private const WATERMARK_KEY = 'magewatch::reports_watermark';

    private const MAX_RECENT = 5;

    private const MAX_BYTES_PER_FILE = 65_536;

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly OffsetReaderInterface $offsetReader,
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function collect(): array
    {
        $reportDirectory = $this->getReportDirectory();
        $watermark = $this->offsetReader->getOffset(self::WATERMARK_KEY);
        $files = $this->listReportFiles($reportDirectory);

        if ($files === []) {
            return [
                'reports' => [
                    'new_since_last' => 0,
                    'recent' => [],
                ],
            ];
        }

        usort($files, fn (array $a, array $b) => $b['mtime'] <=> $a['mtime']);
        $files = array_slice($files, 0, self::MAX_RECENT);

        $newSinceLast = 0;
        $maxMtime = $watermark;
        $recent = [];

        foreach ($files as $file) {
            if ($file['mtime'] > $watermark) {
                $newSinceLast++;
            }
            $maxMtime = max($maxMtime, $file['mtime']);

            $content = $this->readReportContent($reportDirectory, $file['name']);
            $parsed = ReportFileParser::parse($content);

            $recent[] = [
                'id' => $file['name'],
                'mtime' => gmdate('c', $file['mtime']),
                'message' => $parsed['message'],
                'url' => $parsed['url'],
                'class' => $parsed['class'],
            ];
        }

        if ($maxMtime > $watermark) {
            $this->offsetReader->setOffset(self::WATERMARK_KEY, $maxMtime);
        }

        return [
            'reports' => [
                'new_since_last' => $newSinceLast,
                'recent' => $recent,
            ],
        ];
    }

    /**
     * @return array<int, array{name: string, mtime: int}>
     */
    private function listReportFiles(ReadInterface $reportDirectory): array
    {
        if (! $reportDirectory->isExist('.')) {
            return [];
        }

        $files = [];

        foreach ($reportDirectory->search('*', '.') as $relativePath) {
            if (str_contains($relativePath, '/')) {
                continue;
            }

            if (! preg_match('/^[a-zA-Z0-9_-]+$/', $relativePath)) {
                continue;
            }

            if (! $reportDirectory->isFile($relativePath)) {
                continue;
            }

            $stat = $reportDirectory->stat($relativePath);
            $mtime = (int) ($stat['mtime'] ?? 0);
            $size = (int) ($stat['size'] ?? 0);

            if ($size <= 0 || $size > 5_000_000) {
                continue;
            }

            $files[] = [
                'name' => $relativePath,
                'mtime' => $mtime,
            ];
        }

        return $files;
    }

    private function readReportContent(ReadInterface $reportDirectory, string $relativePath): string
    {
        $file = $reportDirectory->openFile($relativePath);
        $content = (string) $file->read(self::MAX_BYTES_PER_FILE);
        $file->close();

        return $content;
    }

    private function getReportDirectory(): ReadInterface
    {
        if (defined(DirectoryList::class.'::REPORT')) {
            return $this->filesystem->getDirectoryRead(DirectoryList::REPORT);
        }

        $varDirectory = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);

        return $this->filesystem->getDirectoryReadByPath($varDirectory->getAbsolutePath().'/report');
    }
}
