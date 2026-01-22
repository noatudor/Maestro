<?php

declare(strict_types=1);

namespace Maestro\Workflow\ValueObjects;

use Maestro\Workflow\Exceptions\InvalidBranchKeyException;
use Stringable;

/**
 * Identifies a branch within a workflow.
 */
final readonly class BranchKey implements Stringable
{
    private function __construct(
        public string $value,
    ) {}

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * @throws InvalidBranchKeyException
     */
    public static function fromString(string $value): self
    {
        if (trim($value) === '') {
            throw InvalidBranchKeyException::empty();
        }

        if (preg_match('/^[a-z][a-z0-9-]*$/', $value) !== 1) {
            throw InvalidBranchKeyException::invalidFormat($value);
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function toString(): string
    {
        return $this->value;
    }
}
