<?php

declare(strict_types=1);

namespace Maestro\Workflow\Exceptions;

use Exception;

abstract class MaestroException extends Exception
{
    protected const int CODE_GENERIC = 1000;
}
