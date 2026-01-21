<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition\Config;

/**
 * N-of-M success criteria for fan-out steps.
 * Specifies a minimum number of successful jobs required.
 */
final readonly class NOfMCriteria
{
    private function __construct(
        public int $minimumRequired,
    ) {}

    public static function create(int $minimumRequired): self
    {
        return new self(max(1, $minimumRequired));
    }

    public static function atLeast(int $count): self
    {
        return new self(max(1, $count));
    }

    public function evaluate(int $succeeded, int $total): bool
    {
        if ($total === 0) {
            return true;
        }

        return $succeeded >= min($this->minimumRequired, $total);
    }

    public function equals(self $other): bool
    {
        return $this->minimumRequired === $other->minimumRequired;
    }
}
