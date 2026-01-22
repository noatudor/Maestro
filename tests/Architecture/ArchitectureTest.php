<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Maestro\Workflow\Application\Job\CompensationJob;
use Maestro\Workflow\Application\Job\OrchestratedJob;
use Maestro\Workflow\Application\Job\PollingJob;
use Maestro\Workflow\Contracts\IdempotencyKeyGenerator;
use Maestro\Workflow\Definition\Steps\AbstractStepDefinition;
use Maestro\Workflow\Domain\Collections\AbstractCollection;
use Maestro\Workflow\Exceptions\MaestroException;
use Maestro\Workflow\MaestroServiceProvider;

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
        AbstractStepDefinition::class,
        AbstractCollection::class,
        CompensationJob::class,
        OrchestratedJob::class,
        PollingJob::class,
    ]);

arch('contracts are interfaces')
    ->expect('Maestro\Workflow\Contracts')
    ->toBeInterfaces();

arch('exceptions extend base exception')
    ->expect('Maestro\Workflow\Exceptions')
    ->toExtend(MaestroException::class);

arch('enums are backed enums')
    ->expect('Maestro\Workflow\Enums')
    ->toBeEnums();

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
        'Maestro\Workflow\Application',
        'Maestro\Workflow\Infrastructure',
    ])
    ->ignoring([
        IdempotencyKeyGenerator::class,
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
    ->toExtend(Model::class);

arch('jobs are dispatchable')
    ->expect('Maestro\Workflow\Jobs')
    ->toImplement(ShouldQueue::class);

arch('events are readonly')
    ->expect('Maestro\Workflow\Events')
    ->toBeReadonly();

arch('repositories implement their contracts')
    ->expect('Maestro\Workflow\Infrastructure\Repositories')
    ->toImplement('Maestro\Workflow\Contracts');

arch('no use of facades')
    ->expect('Illuminate\Support\Facades')
    ->not->toBeUsed()
    ->ignoring([
        'Maestro\Workflow\Facades',
        MaestroServiceProvider::class,
        'Maestro\Workflow\Tests',
        'Workbench',
    ]);

arch('no helper functions used')
    ->expect(['app', 'resolve', 'config'])
    ->not->toBeUsed()
    ->ignoring([
        MaestroServiceProvider::class,
        'Maestro\Workflow\Tests',
    ]);
