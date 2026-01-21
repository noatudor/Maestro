<?php

declare(strict_types=1);

namespace Maestro\Workflow\Exceptions;

abstract class ConfigurationException extends MaestroException
{
    protected const int CODE_CONFIGURATION = 5000;
}
