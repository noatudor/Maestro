<?php

declare(strict_types=1);

namespace Maestro\Workflow\ValueObjects;

use Maestro\Workflow\Exceptions\InvalidDefinitionVersionException;
use Stringable;

final readonly class DefinitionVersion implements Stringable
{
    private function __construct(
        public int $major,
        public int $minor,
        public int $patch,
    ) {}

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * @throws InvalidDefinitionVersionException
     */
    public static function fromString(string $value): self
    {
        if (trim($value) === '') {
            throw InvalidDefinitionVersionException::empty();
        }

        if (preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $value, $matches) !== 1) {
            throw InvalidDefinitionVersionException::invalidFormat($value);
        }

        return new self(
            major: (int) $matches[1],
            minor: (int) $matches[2],
            patch: (int) $matches[3],
        );
    }

    public static function create(int $major, int $minor, int $patch): self
    {
        return new self($major, $minor, $patch);
    }

    public static function initial(): self
    {
        return new self(1, 0, 0);
    }

    public function equals(self $other): bool
    {
        return $this->major === $other->major
            && $this->minor === $other->minor
            && $this->patch === $other->patch;
    }

    public function isNewerThan(self $other): bool
    {
        if ($this->major !== $other->major) {
            return $this->major > $other->major;
        }

        if ($this->minor !== $other->minor) {
            return $this->minor > $other->minor;
        }

        return $this->patch > $other->patch;
    }

    public function isCompatibleWith(self $other): bool
    {
        return $this->major === $other->major;
    }

    public function toString(): string
    {
        return sprintf('%d.%d.%d', $this->major, $this->minor, $this->patch);
    }
}
