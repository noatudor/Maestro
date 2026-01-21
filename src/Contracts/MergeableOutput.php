<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

interface MergeableOutput extends StepOutput
{
    public function mergeWith(self $other): self;
}
