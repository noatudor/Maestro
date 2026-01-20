<?php

declare(strict_types=1);

arch('source files use strict types')
    ->expect('Maestro\Workflow')
    ->toUseStrictTypes();

arch('source files are final by default')
    ->expect('Maestro\Workflow')
    ->classes()
    ->toBeFinal()
    ->ignoring([
        'Maestro\Workflow\Exceptions',
        'Maestro\Workflow\Tests',
    ]);

arch('contracts are interfaces')
    ->expect('Maestro\Workflow\Contracts')
    ->toBeInterfaces();

arch('exceptions extend base exception')
    ->expect('Maestro\Workflow\Exceptions')
    ->toExtend('Maestro\Workflow\Exceptions\MaestroException');

arch('enums are backed by string')
    ->expect('Maestro\Workflow\Enums')
    ->toBeEnums()
    ->toHaveAttribute('BackedEnum');

arch('value objects are readonly')
    ->expect('Maestro\Workflow\ValueObjects')
    ->toBeReadonly();

arch('domain does not depend on infrastructure')
    ->expect('Maestro\Workflow\Domain')
    ->not->toUse('Maestro\Workflow\Infrastructure');

arch('domain does not depend on application')
    ->expect('Maestro\Workflow\Domain')
    ->not->toUse('Maestro\Workflow\Application');

arch('contracts have no dependencies on implementation')
    ->expect('Maestro\Workflow\Contracts')
    ->not->toUse([
        'Maestro\Workflow\Domain',
        'Maestro\Workflow\Application',
        'Maestro\Workflow\Infrastructure',
    ]);

arch('no debugging functions are used')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();

arch('no die or exit statements')
    ->expect(['die', 'exit'])
    ->not->toBeUsed();

arch('no env() calls outside config files')
    ->expect('env')
    ->not->toBeUsed()
    ->ignoring('Maestro\Workflow\Tests');

arch('models extend eloquent model')
    ->expect('Maestro\Workflow\Models')
    ->toExtend('Illuminate\Database\Eloquent\Model');

arch('jobs are dispatchable')
    ->expect('Maestro\Workflow\Jobs')
    ->toImplement('Illuminate\Contracts\Queue\ShouldQueue');

arch('events are readonly')
    ->expect('Maestro\Workflow\Events')
    ->toBeReadonly();

arch('no static methods in domain services')
    ->expect('Maestro\Workflow\Domain\Services')
    ->not->toHaveMethod(fn (string $method) => str_starts_with($method, 'static'));

arch('repositories implement their contracts')
    ->expect('Maestro\Workflow\Infrastructure\Repositories')
    ->toImplement('Maestro\Workflow\Contracts');

arch('no use of facades')
    ->expect('Illuminate\Support\Facades')
    ->not->toBeUsed()
    ->ignoring([
        'Maestro\Workflow\Facades',
        'Maestro\Workflow\Tests',
    ]);

arch('no helper functions used')
    ->expect(['app', 'resolve', 'config'])
    ->not->toBeUsed()
    ->ignoring([
        'Maestro\Workflow\MaestroServiceProvider',
        'Maestro\Workflow\Tests',
    ]);
