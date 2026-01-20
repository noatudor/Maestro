<?php

declare(strict_types=1);

namespace Maestro\Workflow\ValueObjects;

use Maestro\Workflow\Exceptions\InvalidStepKeyException;
use Stringable;

final readonly class StepKey implements Stringable
{
    private function __construct(
        public string $value,
    ) {}

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * @throws InvalidStepKeyException
     */
    public static function fromString(string $value): self
    {
        if (trim($value) === '') {
            throw InvalidStepKeyException::empty();
        }

        if (preg_match('/^[a-z][a-z0-9-]*$/', $value) !== 1) {
            throw InvalidStepKeyException::invalidFormat($value);
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
