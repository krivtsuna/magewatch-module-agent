<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model\Collector;

use MageWatch\Agent\Api\CollectorInterface;
use MageWatch\Agent\Model\Clock;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Filesystem;
use Throwable;

/**
 * Lightweight malware & security signals: unexpected PHP in pub/, suspicious
 * code patterns in recently modified files, and new admin users.
 */
class SecurityCollector implements CollectorInterface
{
    private const CODE = 'security';

  /** @var list<string> */
    private const ALLOWED_PUB_PHP = [
        'index.php',
        'static.php',
        'get.php',
        'health_check.php',
    ];

  /** @var list<string> */
    private const SUSPICIOUS_PATTERNS = [
        'eval\s*\(',
        'base64_decode\s*\(',
        'gzinflate\s*\(',
        'str_rot13\s*\(',
        'shell_exec\s*\(',
        'passthru\s*\(',
        'proc_open\s*\(',
    ];

    private const MAX_PATTERN_FILES = 25;

    private const FILE_READ_BYTES = 8192;

    private const ADMIN_LOOKBACK_DAYS = 7;

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly ResourceConnection $resourceConnection,
        private readonly Clock $clock,
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function collect(): array
    {
        $pubPath = rtrim(
            $this->filesystem->getDirectoryRead(DirectoryList::PUB)->getAbsolutePath(),
            DIRECTORY_SEPARATOR
        ).DIRECTORY_SEPARATOR;

        return [
            'security' => [
                'unexpected_pub_php' => $this->findUnexpectedPubPhp($pubPath),
                'suspicious_patterns' => $this->scanSuspiciousPatterns($pubPath),
                'new_admin_users' => $this->findNewAdminUsers(),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function findUnexpectedPubPhp(string $pubPath): array
    {
        $unexpected = [];

        foreach ($this->listPhpFilesInDirectory($pubPath) as $file) {
            $basename = basename($file);
            if (! in_array($basename, self::ALLOWED_PUB_PHP, true)) {
                $unexpected[] = $this->relativePubPath($pubPath, $file);
            }
        }

        $mediaPath = $pubPath.'media'.DIRECTORY_SEPARATOR;
        if (is_dir($mediaPath)) {
            foreach ($this->listPhpFilesInDirectory($mediaPath) as $file) {
                $unexpected[] = $this->relativePubPath($pubPath, $file);
            }
        }

        return array_values(array_unique($unexpected));
    }

    /**
     * @return list<array{path: string, pattern: string}>
     */
    private function scanSuspiciousPatterns(string $pubPath): array
    {
        $cutoff = $this->clock->now()->modify('-'.self::ADMIN_LOOKBACK_DAYS.' days')->getTimestamp();
        $matches = [];
        $scanned = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pubPath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (! $fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
                continue;
            }

            if ($fileInfo->getMTime() < $cutoff) {
                continue;
            }

            $relative = $this->relativePubPath($pubPath, $fileInfo->getPathname());
            $chunk = @file_get_contents($fileInfo->getPathname(), false, null, 0, self::FILE_READ_BYTES);

            if ($chunk === false) {
                continue;
            }

            foreach (self::SUSPICIOUS_PATTERNS as $pattern) {
                if (preg_match('/'.$pattern.'/i', $chunk) === 1) {
                    $matches[] = ['path' => $relative, 'pattern' => $pattern];
                    break;
                }
            }

            if (++$scanned >= self::MAX_PATTERN_FILES) {
                break;
            }
        }

        return $matches;
    }

    /**
     * @return list<array{username: string, created_at: string}>
     */
    private function findNewAdminUsers(): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('admin_user');
            $since = $this->clock->now()->modify('-'.self::ADMIN_LOOKBACK_DAYS.' days')->format('Y-m-d H:i:s');

            $rows = $this->fetchAdminUsers($connection, $table, $since);

            return array_map(
                fn (array $row) => [
                    'username' => (string) ($row['username'] ?? ''),
                    'created_at' => (string) ($row['created'] ?? ''),
                ],
                $rows
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAdminUsers(AdapterInterface $connection, string $table, string $since): array
    {
        $select = $connection->select()
            ->from($table, ['username', 'created'])
            ->where('created >= ?', $since)
            ->order('created DESC')
            ->limit(10);

        return $connection->fetchAll($select);
    }

    /**
     * @return list<string>
     */
    private function listPhpFilesInDirectory(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = glob($directory.'*.php') ?: [];

        return array_values(array_filter($files, 'is_file'));
    }

    private function relativePubPath(string $pubPath, string $absolutePath): string
    {
        $relative = ltrim(str_replace($pubPath, '', $absolutePath), DIRECTORY_SEPARATOR);

        return 'pub/'.$relative;
    }
}
