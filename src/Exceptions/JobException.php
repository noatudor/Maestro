<?php

declare(strict_types=1);

namespace Maestro\Workflow\Exceptions;

abstract class JobException extends MaestroException
{
    protected const int CODE_JOB = 4000;
}
