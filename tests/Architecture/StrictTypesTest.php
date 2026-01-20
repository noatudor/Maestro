<?php

declare(strict_types=1);

arch('all source files have declare strict_types')
    ->expect('Maestro\Workflow')
    ->toUseStrictTypes();

arch('all test files have declare strict_types')
    ->expect('Maestro\Workflow\Tests')
    ->toUseStrictTypes();
