<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model;

use MageWatch\Agent\Model\PubTreeScanner;
use PHPUnit\Framework\TestCase;

class PubTreeScannerTest extends TestCase
{
    private PubTreeScanner $scanner;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scanner = new PubTreeScanner;
        $this->root = sys_get_temp_dir().'/mw-pub-tree-'.uniqid('', true);
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
        parent::tearDown();
    }

    public function test_finds_php_in_media_root_and_wysiwyg(): void
    {
        mkdir($this->root.'/wysiwyg', 0777, true);
        file_put_contents($this->root.'/shell.php', "<?php\n");
        file_put_contents($this->root.'/wysiwyg/drop.php', "<?php\n");
        file_put_contents($this->root.'/wysiwyg/photo.jpg', 'img');

        $found = $this->basenames($this->scanner->listPhpFiles($this->root));

        $this->assertEqualsCanonicalizing(['shell.php', 'drop.php'], $found);
    }

    public function test_skips_catalog_product_image_tree(): void
    {
        mkdir($this->root.'/catalog/product/cache/1', 0777, true);
        mkdir($this->root.'/wysiwyg', 0777, true);
        file_put_contents($this->root.'/catalog/product/cache/1/a.jpg', 'img');
        file_put_contents($this->root.'/catalog/product/hidden.php', "<?php\n");
        file_put_contents($this->root.'/wysiwyg/keep.php', "<?php\n");

        $found = $this->basenames($this->scanner->listPhpFiles($this->root));

        $this->assertSame(['keep.php'], $found);
    }

    public function test_skips_static_and_cache_dirs(): void
    {
        mkdir($this->root.'/static/frontend', 0777, true);
        mkdir($this->root.'/cache', 0777, true);
        mkdir($this->root.'/errors', 0777, true);
        file_put_contents($this->root.'/static/frontend/x.php', "<?php\n");
        file_put_contents($this->root.'/cache/y.php', "<?php\n");
        file_put_contents($this->root.'/errors/404.php', "<?php\n");

        $found = $this->basenames($this->scanner->listPhpFiles($this->root));

        $this->assertSame(['404.php'], $found);
    }

    public function test_inode_budget_does_not_enter_skipped_product_tree(): void
    {
        mkdir($this->root.'/catalog/product/a/b/c', 0777, true);
        for ($i = 0; $i < 200; $i++) {
            file_put_contents($this->root.'/catalog/product/a/b/c/'.$i.'.jpg', 'x');
        }
        file_put_contents($this->root.'/root.php', "<?php\n");

        $found = $this->scanner->listPhpFiles($this->root, maxInodes: 20);

        $this->assertCount(1, $found);
        $this->assertSame('root.php', basename($found[0]));
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function basenames(array $paths): array
    {
        return array_values(array_map('basename', $paths));
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
