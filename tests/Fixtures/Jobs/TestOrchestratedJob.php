<?php

declare(strict_types=1);

namespace Maestro\Workflow\Tests\Fixtures\Jobs;

use Maestro\Workflow\Application\Job\OrchestratedJob;
use Maestro\Workflow\Application\Output\StepOutputStore;
use Maestro\Workflow\Contracts\StepOutput;
use RuntimeException;

/**
 * Test orchestrated job for unit testing.
 */
final class TestOrchestratedJob extends OrchestratedJob
{
    public bool $executed = false;

    public bool $shouldFail = false;

    public ?StepOutput $outputToWrite = null;

    public string $failureMessage = 'Test job failed intentionally';

    public function getContext(): mixed
    {
        return $this->context();
    }

    public function getOutputStore(): mixed
    {
        return $this->outputs();
    }

    protected function execute(): void
    {
        $this->executed = true;

        if ($this->shouldFail) {
            throw new RuntimeException($this->failureMessage);
        }

        if ($this->outputToWrite instanceof StepOutput && $this->outputs() instanceof StepOutputStore) {
            $this->outputs()->write($this->outputToWrite);
        }
    }
}
