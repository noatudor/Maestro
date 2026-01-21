<?php

declare(strict_types=1);

namespace Maestro\Workflow\ValueObjects;

use Maestro\Workflow\Exceptions\InvalidDefinitionKeyException;
use Stringable;

final readonly class DefinitionKey implements Stringable
{
    private function __construct(
        public string $value,
    ) {}

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * @throws InvalidDefinitionKeyException
     */
    public static function fromString(string $value): self
    {
        if (trim($value) === '') {
            throw InvalidDefinitionKeyException::empty();
        }

        if (preg_match('/^[a-z][a-z0-9-]*$/', $value) !== 1) {
            throw InvalidDefinitionKeyException::invalidFormat($value);
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
