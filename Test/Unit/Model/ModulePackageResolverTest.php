<?php

declare(strict_types=1);

namespace MageWatch\Agent\Test\Unit\Model;

use MageWatch\Agent\Model\ModulePackageResolver;
use PHPUnit\Framework\TestCase;

class ModulePackageResolverTest extends TestCase
{
    public function test_resolves_amasty_advanced_review_package(): void
    {
        $resolver = new ModulePackageResolver;

        $this->assertContains('amasty/advanced-review', $resolver->packageCandidates('Amasty_AdvancedReview'));
    }
}
