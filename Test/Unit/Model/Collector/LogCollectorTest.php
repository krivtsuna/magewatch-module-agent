<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model\Collector;

use MageWatch\Agent\Model\Collector\LogCollector;
use MageWatch\Agent\Model\LogOffset\OffsetReaderInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LogCollectorTest extends TestCase
{
    private Filesystem&MockObject $filesystem;
    private OffsetReaderInterface&MockObject $offsetReader;
    private ReadInterface&MockObject $logDirectory;
    private LogCollector $collector;

    protected function setUp(): void
    {
        $this->filesystem = $this->createMock(Filesystem::class);
        $this->offsetReader = $this->createMock(OffsetReaderInterface::class);
        $this->logDirectory = $this->createMock(ReadInterface::class);

        $this->filesystem->method('getDirectoryRead')
            ->with(DirectoryList::LOG)
            ->willReturn($this->logDirectory);

        $this->collector = new LogCollector($this->filesystem, $this->offsetReader);
    }

    public function testGetCode(): void
    {
        $this->assertSame('log', $this->collector->getCode());
    }

    public function testCollectReturnsZerosWhenLogFilesMissing(): void
    {
        $this->logDirectory->method('isExist')->willReturn(false);

        $result = $this->collector->collect();

        $this->assertSame([
            'logs' => [
                'system_new_lines' => 0,
                'exception_new_lines' => 0,
                'payment_new_lines' => 0,
                'system_log_bytes' => 0,
                'exception_log_bytes' => 0,
                'payment_log_bytes' => 0,
                'recent_exceptions' => [],
                'recent_payment_errors' => [],
            ],
        ], $result);
    }
}
