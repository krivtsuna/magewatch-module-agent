<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model;

use MageWatch\Agent\Model\PubPhpIntegrityChecker;
use PHPUnit\Framework\TestCase;

class PubPhpIntegrityCheckerTest extends TestCase
{
    private PubPhpIntegrityChecker $checker;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checker = new PubPhpIntegrityChecker;
        $this->root = sys_get_temp_dir().'/mw-pub-integrity-'.uniqid('', true);
        mkdir($this->root.'/pub', 0777, true);
        mkdir($this->root.'/vendor/magento/magento2-base/pub', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
        parent::tearDown();
    }

    public function test_cron_php_identical_to_base_is_not_flagged(): void
    {
        $content = "<?php\necho 'cron';\n";
        file_put_contents($this->root.'/pub/cron.php', $content);
        file_put_contents($this->root.'/vendor/magento/magento2-base/pub/cron.php', $content);

        $result = $this->checker->scan($this->root.'/pub', $this->root);

        $this->assertSame([], $result['unexpected_pub_php']);
        $this->assertSame([], $result['core_pub_php_modified']);
    }

    public function test_cron_php_with_injected_content_is_core_modified(): void
    {
        file_put_contents($this->root.'/pub/cron.php', "<?php\neval('bad');\n");
        file_put_contents($this->root.'/vendor/magento/magento2-base/pub/cron.php', "<?php\necho 'cron';\n");

        $result = $this->checker->scan($this->root.'/pub', $this->root);

        $this->assertSame([], $result['unexpected_pub_php']);
        $this->assertSame(['pub/cron.php'], $result['core_pub_php_modified']);
    }

    public function test_random_shell_php_is_unexpected(): void
    {
        file_put_contents($this->root.'/pub/shell.php', "<?php\necho 'shell';\n");

        $result = $this->checker->scan($this->root.'/pub', $this->root);

        $this->assertSame(['pub/shell.php'], $result['unexpected_pub_php']);
        $this->assertSame([], $result['core_pub_php_modified']);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
