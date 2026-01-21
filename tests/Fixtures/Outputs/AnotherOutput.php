<?php

declare(strict_types=1);

namespace Maestro\Workflow\Tests\Fixtures\Outputs;

use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\ValueObjects\StepKey;

final readonly class AnotherOutput implements StepOutput
{
    public function __construct(
        public int $count = 0,
    ) {}

    public function stepKey(): StepKey
    {
        return StepKey::fromString('another-step');
    }
}
