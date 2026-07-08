<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model;

use MageWatch\Agent\Model\MagentoErrorPageDetector;
use PHPUnit\Framework\TestCase;

class MagentoErrorPageDetectorTest extends TestCase
{
    public function testDetectsMagentoProductionErrorPage(): void
    {
        $detector = new MagentoErrorPageDetector;

        $this->assertTrue($detector->isErrorPage(
            'An error has happened during application run. See exception log for details.'
        ));
    }
}
