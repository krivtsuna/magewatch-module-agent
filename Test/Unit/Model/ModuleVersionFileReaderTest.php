<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model;

use MageWatch\Agent\Model\ModuleVersionFileReader;
use PHPUnit\Framework\TestCase;

class ModuleVersionFileReaderTest extends TestCase
{
    public function test_reads_mirasvit_version_json(): void
    {
        $dir = sys_get_temp_dir().'/mw-mirasvit-'.uniqid('', true);
        mkdir($dir, 0777, true);

        file_put_contents($dir.'/version.json', json_encode([
            'package_name' => 'mirasvit/module-core',
            'version' => '1.7.12',
        ], JSON_THROW_ON_ERROR));

        $meta = (new ModuleVersionFileReader())->readFromPath($dir);

        $this->assertSame('1.7.12', $meta['version']);
        $this->assertSame('mirasvit/module-core', $meta['package']);
        $this->assertSame('version.json', $meta['source']);

        unlink($dir.'/version.json');
        rmdir($dir);
    }

    public function test_reads_registration_docblock_version(): void
    {
        $dir = sys_get_temp_dir().'/mw-reg-'.uniqid('', true);
        mkdir($dir, 0777, true);

        file_put_contents($dir.'/registration.php', <<<'PHP'
<?php
/**
 * @version 2.4.1
 */
PHP);

        $meta = (new ModuleVersionFileReader())->readFromPath($dir);

        $this->assertSame('2.4.1', $meta['version']);
        $this->assertSame('registration.php', $meta['source']);

        unlink($dir.'/registration.php');
        rmdir($dir);
    }
}
