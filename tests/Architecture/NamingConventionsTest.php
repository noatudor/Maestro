<?php

declare(strict_types=1);

arch('exceptions have Exception suffix')
    ->expect('Maestro\Workflow\Exceptions')
    ->toHaveSuffix('Exception');

arch('service providers have ServiceProvider suffix')
    ->expect('Maestro\Workflow')
    ->classes()
    ->toHaveSuffix('ServiceProvider')
    ->ignoring([
        'Maestro\Workflow\Domain',
        'Maestro\Workflow\Application',
        'Maestro\Workflow\Infrastructure',
        'Maestro\Workflow\Contracts',
        'Maestro\Workflow\Enums',
        'Maestro\Workflow\Events',
        'Maestro\Workflow\Exceptions',
        'Maestro\Workflow\Facades',
        'Maestro\Workflow\Models',
        'Maestro\Workflow\Jobs',
        'Maestro\Workflow\ValueObjects',
        'Maestro\Workflow\Commands',
        'Maestro\Workflow\Maestro',
    ]);

arch('commands have Command suffix')
    ->expect('Maestro\Workflow\Commands')
    ->toHaveSuffix('Command');
