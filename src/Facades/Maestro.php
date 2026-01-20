<?php

declare(strict_types=1);

namespace Maestro\Workflow\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Maestro\Workflow\Maestro
 */
final class Maestro extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Maestro\Workflow\Maestro::class;
    }
}
