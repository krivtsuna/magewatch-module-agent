<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model\Attribution;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\CookieManagerInterface;
use MageWatch\Agent\Model\Attribution\AttributionCookie;
use MageWatch\Agent\Model\Attribution\OrderAttributionRecorder;
use MageWatch\Agent\Model\Attribution\TouchNormalizer;
use MageWatch\Agent\Model\Config;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OrderAttributionRecorderTest extends TestCase
{
    private Config&MockObject $config;

    private CookieManagerInterface&MockObject $cookieManager;

    private AdapterInterface&MockObject $connection;

    private LoggerInterface&MockObject $logger;

    private OrderAttributionRecorder $recorder;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->cookieManager = $this->createMock(CookieManagerInterface::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->recorder = new OrderAttributionRecorder(
            $this->config,
            $this->cookieManager,
            new AttributionCookie,
            new TouchNormalizer,
            $resource,
            $this->logger
        );
    }

    public function test_skips_when_attribution_disabled(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isAttributionEnabled')->willReturn(false);
        $this->connection->expects($this->never())->method('insert');

        $this->recorder->recordForOrder(10, '100000010', 1);
    }

    public function test_skips_when_cookie_missing(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isAttributionEnabled')->willReturn(true);
        $this->connection->method('isTableExists')->willReturn(true);
        $this->cookieManager->method('getCookie')->willReturn(null);
        $this->connection->expects($this->never())->method('insert');

        $this->recorder->recordForOrder(10, '100000010', 1);
    }

    public function test_inserts_normalized_first_and_last_touch(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isAttributionEnabled')->willReturn(true);
        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->method('fetchOne')->willReturn(false);
        $this->cookieManager->method('getCookie')->with(AttributionCookie::NAME)->willReturn(json_encode([
            'v' => 1,
            'f' => ['source' => 'google', 'medium' => 'cpc', 'campaign' => 'brand', 'ci' => 'gclid', 'cv' => 'AAA'],
            'l' => ['source' => 'newsletter', 'medium' => 'email', 'campaign' => 'spring'],
        ], JSON_THROW_ON_ERROR));

        $this->connection->expects($this->once())
            ->method('insert')
            ->with(
                OrderAttributionRecorder::TABLE,
                $this->callback(function (array $row): bool {
                    $this->assertSame(42, $row['order_id']);
                    $this->assertSame('100000042', $row['increment_id']);
                    $this->assertSame(1, $row['store_id']);
                    $this->assertSame('google', $row['first_source']);
                    $this->assertSame('cpc', $row['first_medium']);
                    $this->assertSame('brand', $row['first_campaign']);
                    $this->assertSame('newsletter', $row['last_source']);
                    $this->assertSame('email', $row['last_medium']);
                    $this->assertSame('spring', $row['last_campaign']);
                    $this->assertNull($row['click_id_type']);
                    $this->assertNull($row['click_id']);

                    return true;
                })
            );

        $this->recorder->recordForOrder(42, '100000042', 1);
    }

    public function test_existing_row_is_left_alone(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isAttributionEnabled')->willReturn(true);
        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->method('fetchOne')->willReturn('42');
        $this->cookieManager->method('getCookie')->willReturn(json_encode([
            'v' => 1,
            'f' => ['source' => 'google'],
            'l' => ['source' => 'google'],
        ], JSON_THROW_ON_ERROR));
        $this->connection->expects($this->never())->method('insert');

        $this->recorder->recordForOrder(42, '100000042', 1);
    }

    public function test_insert_failure_is_logged_not_thrown(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isAttributionEnabled')->willReturn(true);
        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->method('fetchOne')->willReturn(false);
        $this->cookieManager->method('getCookie')->willReturn(json_encode([
            'v' => 1,
            'f' => ['d' => 1],
            'l' => ['d' => 1],
        ], JSON_THROW_ON_ERROR));
        $this->connection->method('insert')->willThrowException(new \RuntimeException('deadlock'));
        $this->logger->expects($this->once())->method('warning');

        $this->recorder->recordForOrder(42, '100000042', 1);
    }
}
