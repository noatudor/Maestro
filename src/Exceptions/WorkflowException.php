<?php

declare(strict_types=1);

namespace Maestro\Workflow\Exceptions;

abstract class WorkflowException extends MaestroException
{
    protected const int CODE_WORKFLOW = 2000;
}
