<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Collector;

use MageWatch\Agent\Api\CollectorInterface;
use Magento\Framework\App\Cache\StateInterface as CacheStateInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\State;
use Magento\Framework\Filesystem;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Reports Magento/PHP version info, maintenance/deploy mode, disk space
 * for the Magento root filesystem, and disabled cache types.
 */
class SystemCollector implements CollectorInterface
{
    private const CODE = 'system';

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly ProductMetadataInterface $productMetadata,
        private readonly State $appState,
        private readonly MaintenanceMode $maintenanceMode,
        private readonly TypeListInterface $cacheTypeList,
        private readonly CacheStateInterface $cacheState,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function collect(): array
    {
        return [
            'magento' => [
                'version' => $this->productMetadata->getVersion(),
                'edition' => $this->productMetadata->getEdition(),
                'mode' => $this->appState->getMode(),
                'maintenance' => $this->maintenanceMode->isOn(),
                'php' => PHP_VERSION,
                'store_base_urls' => $this->getStoreBaseUrls(),
                'static_version' => $this->readStaticContentVersion(),
                'composer_lock_hash' => $this->readComposerLockHash(),
            ],
            'system' => $this->getDiskStats() + $this->getInodeStats() + ['disabled_caches' => $this->getDisabledCaches()],
        ];
    }

    /**
     * @return array{disk_free_bytes: int, disk_free_percent: float}
     */
    private function getDiskStats(): array
    {
        $rootPath = $this->filesystem->getDirectoryRead(DirectoryList::ROOT)->getAbsolutePath();
        $free = disk_free_space($rootPath);
        $total = disk_total_space($rootPath);

        if ($free === false || $total === false || $total <= 0) {
            return ['disk_free_bytes' => 0, 'disk_free_percent' => 0.0];
        }

        return [
            'disk_free_bytes' => (int) $free,
            'disk_free_percent' => round(($free / $total) * 100, 1),
        ];
    }

    /**
     * @return array{inode_free: ?int, inode_total: ?int, inode_free_percent: ?float}
     */
    private function getInodeStats(): array
    {
        $empty = [
            'inode_free' => null,
            'inode_total' => null,
            'inode_free_percent' => null,
        ];

        if (PHP_OS_FAMILY !== 'Linux' && PHP_OS_FAMILY !== 'Darwin') {
            return $empty;
        }

        if (! \function_exists('shell_exec')) {
            return $empty;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (\in_array('shell_exec', $disabled, true)) {
            return $empty;
        }

        $rootPath = $this->filesystem->getDirectoryRead(DirectoryList::ROOT)->getAbsolutePath();
        $escaped = escapeshellarg($rootPath);
        $output = @shell_exec("df -i {$escaped} 2>/dev/null | awk 'NR==2 {print $2, $3, $4}'");

        if (! is_string($output) || trim($output) === '') {
            return $empty;
        }

        $parts = preg_split('/\s+/', trim($output));
        if ($parts === false || count($parts) < 3) {
            return $empty;
        }

        $total = (int) $parts[0];
        $used = (int) $parts[1];
        $free = (int) $parts[2];

        if ($total <= 0) {
            return $empty;
        }

        return [
            'inode_free' => $free,
            'inode_total' => $total,
            'inode_free_percent' => round(($free / $total) * 100, 1),
        ];
    }

    /**
     * @return string[]
     */
    private function getDisabledCaches(): array
    {
        $disabled = [];
        foreach (array_keys($this->cacheTypeList->getTypes()) as $typeCode) {
            if (!$this->cacheState->isEnabled($typeCode)) {
                $disabled[] = $typeCode;
            }
        }

        return $disabled;
    }

    /**
     * @return string[]
     */
    private function getStoreBaseUrls(): array
    {
        $urls = [];
        foreach ($this->storeManager->getStores() as $store) {
            $urls[] = $store->getBaseUrl();
        }

        return array_values(array_unique($urls));
    }

    private function readStaticContentVersion(): ?string
    {
        $paths = [
            $this->filesystem->getDirectoryRead(DirectoryList::PUB)->getAbsolutePath().'/static/deployed_version.txt',
            $this->filesystem->getDirectoryRead(DirectoryList::ROOT)->getAbsolutePath().'/var/.regenerate.lock',
        ];

        foreach ($paths as $path) {
            if (! is_readable($path)) {
                continue;
            }

            $content = trim((string) @file_get_contents($path));
            if ($content !== '') {
                return $content;
            }
        }

        return null;
    }

    private function readComposerLockHash(): ?string
    {
        $lockPath = $this->filesystem->getDirectoryRead(DirectoryList::ROOT)->getAbsolutePath().'/composer.lock';
        if (! is_readable($lockPath)) {
            return null;
        }

        $hash = @hash_file('sha256', $lockPath);

        return is_string($hash) ? $hash : null;
    }
}
