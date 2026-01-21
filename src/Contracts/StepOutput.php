<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

use Maestro\Workflow\ValueObjects\StepKey;

interface StepOutput
{
    public function stepKey(): StepKey;
}
