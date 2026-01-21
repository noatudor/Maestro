<?php

declare(strict_types=1);

namespace Maestro\Workflow\Tests\Fixtures\Jobs;

final class ProcessItemJob
{
    public function __construct(
        public readonly mixed $item,
    ) {}

    public function handle(): void {}
}
