<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

use Maestro\Workflow\ValueObjects\WorkflowId;

interface ContextLoader
{
    public function load(WorkflowId $workflowId): WorkflowContext;
}
