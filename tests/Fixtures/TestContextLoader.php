<?php

declare(strict_types=1);

namespace Maestro\Workflow\Tests\Fixtures;

use Maestro\Workflow\Contracts\ContextLoader;
use Maestro\Workflow\Contracts\WorkflowContext;
use Maestro\Workflow\ValueObjects\WorkflowId;

final readonly class TestContextLoader implements ContextLoader
{
    public function load(WorkflowId $workflowId): WorkflowContext
    {
        return new TestWorkflowContext();
    }
}
