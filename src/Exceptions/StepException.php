<?php

declare(strict_types=1);

namespace Maestro\Workflow\Exceptions;

abstract class StepException extends MaestroException
{
    protected const int CODE_STEP = 3000;
}
