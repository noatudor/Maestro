<?php

declare(strict_types=1);

arch('interfaces do not have I prefix')
    ->expect('Maestro\Workflow\Contracts')
    ->not->toHaveNameStartingWith('I');

arch('interfaces do not have Interface suffix')
    ->expect('Maestro\Workflow\Contracts')
    ->not->toHaveNameEndingWith('Interface');

arch('interfaces do not have Contract suffix')
    ->expect('Maestro\Workflow\Contracts')
    ->not->toHaveNameEndingWith('Contract');

arch('exceptions have Exception suffix')
    ->expect('Maestro\Workflow\Exceptions')
    ->toHaveSuffix('Exception');

arch('events have past tense names')
    ->expect('Maestro\Workflow\Events')
    ->toHaveNameEndingWith('ed')
    ->or->toHaveNameEndingWith('Failed')
    ->or->toHaveNameEndingWith('Cancelled');

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
    ]);

arch('commands have Command suffix')
    ->expect('Maestro\Workflow\Commands')
    ->toHaveSuffix('Command');
