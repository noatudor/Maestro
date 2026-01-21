<?php

declare(strict_types=1);

namespace Maestro\Workflow\Tests\Fixtures\Jobs;

final readonly class ProcessItemJob
{
    public function __construct(
        public mixed $item,
    ) {}
}
