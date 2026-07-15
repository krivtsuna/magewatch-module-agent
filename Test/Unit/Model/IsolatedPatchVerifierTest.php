<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model;

use MageWatch\Agent\Model\IsolatedPatchVerifier;
use PHPUnit\Framework\TestCase;

class IsolatedPatchVerifierTest extends TestCase
{
    private IsolatedPatchVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new IsolatedPatchVerifier();
    }

    public function testDetectsAppliedPatchWhenAllMarkerFilesExist(): void
    {
        $root = sys_get_temp_dir() . '/mw-patch-' . uniqid('', true);
        mkdir($root . '/vendor/magento/module-quote/Model/GuestCart', 0777, true);
        mkdir($root . '/vendor/magento/module-catalog-url-rewrite-graph-ql/Plugin/Model/Resolver', 0777, true);
        file_put_contents($root . '/vendor/magento/module-quote/Model/GuestCart/GetGuestCart.php', '<?php');
        file_put_contents(
            $root . '/vendor/magento/module-catalog-url-rewrite-graph-ql/Plugin/Model/Resolver/EntityUrlExcludeDisabledProductPlugin.php',
            '<?php'
        );

        $results = $this->verifier->verify($root, '2.4.7-p10', [[
            'patch_id' => '247p10-2026-07-001-CE',
            'bulletin_id' => 'APSB26-73',
            'isolated_base' => '2.4.7-p10',
            'marker_files' => [
                'vendor/magento/module-quote/Model/GuestCart/GetGuestCart.php',
                'vendor/magento/module-catalog-url-rewrite-graph-ql/Plugin/Model/Resolver/EntityUrlExcludeDisabledProductPlugin.php',
            ],
        ]]);

        $this->assertCount(1, $results);
        $this->assertSame('applied', $results[0]['status']);
        $this->assertSame('fingerprint', $results[0]['method']);
        $this->assertSame([], $results[0]['missing']);

        $this->removeDir($root);
    }

    public function testDetectsMissingPatchWhenMarkerFilesAbsent(): void
    {
        $root = sys_get_temp_dir() . '/mw-patch-' . uniqid('', true);
        mkdir($root, 0777, true);

        $results = $this->verifier->verify($root, '2.4.7-p10', [[
            'patch_id' => '247p10-2026-07-001-CE',
            'bulletin_id' => 'APSB26-73',
            'isolated_base' => '2.4.7-p10',
            'marker_files' => [
                'vendor/magento/module-quote/Model/GuestCart/GetGuestCart.php',
            ],
        ]]);

        $this->assertCount(1, $results);
        $this->assertSame('missing', $results[0]['status']);

        $this->removeDir($root);
    }

    public function testSkipsChecksForOtherMagentoVersions(): void
    {
        $results = $this->verifier->verify(sys_get_temp_dir(), '2.4.8-p5', [[
            'patch_id' => '247p10-2026-07-001-CE',
            'bulletin_id' => 'APSB26-73',
            'isolated_base' => '2.4.7-p10',
            'marker_files' => [
                'vendor/magento/module-quote/Model/GuestCart/GetGuestCart.php',
            ],
        ]]);

        $this->assertSame([], $results);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
