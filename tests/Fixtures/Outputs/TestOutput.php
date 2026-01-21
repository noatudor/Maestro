<?php

declare(strict_types=1);

namespace Maestro\Workflow\Tests\Fixtures\Outputs;

use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\ValueObjects\StepKey;

final readonly class TestOutput implements StepOutput
{
    public function __construct(
        public string $value = 'test',
    ) {}

    public function stepKey(): StepKey
    {
        return StepKey::fromString('test-step');
    }
}
