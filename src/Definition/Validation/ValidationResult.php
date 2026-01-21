<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition\Validation;

use Maestro\Workflow\Exceptions\InvalidWorkflowDefinitionException;

/**
 * Result of workflow definition validation.
 */
final readonly class ValidationResult
{
    /**
     * @param list<ValidationError> $errors
     */
    private function __construct(
        private array $errors,
    ) {}

    public static function valid(): self
    {
        return new self([]);
    }

    /**
     * @param list<ValidationError> $errors
     */
    public static function invalid(array $errors): self
    {
        return new self($errors);
    }

    public static function withError(ValidationError $error): self
    {
        return new self([$error]);
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function isInvalid(): bool
    {
        return ! $this->isValid();
    }

    /**
     * @return list<ValidationError>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function errorCount(): int
    {
        return count($this->errors);
    }

    public function firstError(): ?ValidationError
    {
        return $this->errors[0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function errorMessages(): array
    {
        return array_map(
            static fn (ValidationError $error): string => $error->message,
            $this->errors,
        );
    }

    /**
     * @return list<string>
     */
    public function errorCodes(): array
    {
        return array_map(
            static fn (ValidationError $error): string => $error->code,
            $this->errors,
        );
    }

    public function merge(self $other): self
    {
        return new self([...$this->errors, ...$other->errors]);
    }

    public function addError(ValidationError $error): self
    {
        return new self([...$this->errors, $error]);
    }

    /**
     * @throws InvalidWorkflowDefinitionException
     */
    public function throwIfInvalid(): void
    {
        if ($this->isInvalid()) {
            throw InvalidWorkflowDefinitionException::fromValidationResult($this);
        }
    }
}
