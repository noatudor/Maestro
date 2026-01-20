<?php

declare(strict_types=1);

namespace Maestro\Workflow\ValueObjects;

use Ramsey\Uuid\Uuid;

final readonly class WorkflowId
{
    private function __construct(
        public string $value,
    ) {}

    public static function generate(): self
    {
        return new self(Uuid::uuid7()->toString());
    }

    public static function fromString(string $value): self
    {
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

    public function __toString(): string
    {
        return $this->value;
    }
}
