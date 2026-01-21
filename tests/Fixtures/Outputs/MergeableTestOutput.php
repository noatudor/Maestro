<?php

declare(strict_types=1);

namespace Maestro\Workflow\Tests\Fixtures\Outputs;

use Maestro\Workflow\Contracts\MergeableOutput;
use Maestro\Workflow\ValueObjects\StepKey;

final readonly class MergeableTestOutput implements MergeableOutput
{
    /**
     * @param list<string> $items
     */
    public function __construct(
        public array $items = [],
    ) {}

    public function stepKey(): StepKey
    {
        return StepKey::fromString('mergeable-step');
    }

    public function mergeWith(MergeableOutput $mergeableOutput): MergeableOutput
    {
        if (! $mergeableOutput instanceof self) {
            return $this;
        }

        return new self([...$this->items, ...$mergeableOutput->items]);
    }
}
