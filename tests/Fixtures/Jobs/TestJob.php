<?php

declare(strict_types=1);

namespace Maestro\Workflow\Tests\Fixtures\Jobs;

use Maestro\Workflow\Application\Job\OrchestratedJob;

/**
 * Simple test job for unit testing.
 */
final class TestJob extends OrchestratedJob
{
    public bool $executed = false;

    protected function execute(): void
    {
        $this->executed = true;
    }
}
