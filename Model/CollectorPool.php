<?php

declare(strict_types=1);

namespace MageWatch\Agent\Model;

use MageWatch\Agent\Api\CollectorInterface;

/**
 * Holds the set of registered collectors, injected via di.xml.
 */
class CollectorPool
{
    /**
     * @param CollectorInterface[] $collectors Keyed by collector code.
     */
    public function __construct(private readonly array $collectors = [])
    {
    }

    /**
     * @return CollectorInterface[]
     */
    public function getCollectors(): array
    {
        return $this->collectors;
    }
}
