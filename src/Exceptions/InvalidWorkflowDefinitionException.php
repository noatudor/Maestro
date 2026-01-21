<?php

declare(strict_types=1);

namespace Maestro\Workflow\Exceptions;

use Maestro\Workflow\Definition\Validation\ValidationResult;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\StepKey;

final class InvalidWorkflowDefinitionException extends ConfigurationException
{
    private const int CODE = 5003;

    public static function fromValidationResult(ValidationResult $result): self
    {
        $messages = $result->errorMessages();
        $message = count($messages) === 1
            ? $messages[0]
            : 'Workflow definition validation failed: '.implode('; ', $messages);

        return new self(
            message: $message,
            code: self::CODE,
        );
    }

    public static function duplicateStepKey(DefinitionKey $definitionKey, StepKey $stepKey): self
    {
        return new self(
            message: sprintf("Workflow '%s' contains duplicate step key: %s", $definitionKey->value, $stepKey->value),
            code: self::CODE,
        );
    }

    public static function circularDependency(DefinitionKey $definitionKey, StepKey $stepKey): self
    {
        return new self(
            message: sprintf("Workflow '%s' contains circular dependency at step: %s", $definitionKey->value, $stepKey->value),
            code: self::CODE,
        );
    }

    public static function emptySteps(DefinitionKey $definitionKey): self
    {
        return new self(
            message: sprintf("Workflow '%s' must have at least one step", $definitionKey->value),
            code: self::CODE,
        );
    }

    /**
     * @param class-string $outputClass
     */
    public static function unresolvedDependency(DefinitionKey $definitionKey, StepKey $stepKey, string $outputClass): self
    {
        return new self(
            message: sprintf("Step '%s' in workflow '%s' requires output '%s' which no prior step produces", $stepKey->value, $definitionKey->value, $outputClass),
            code: self::CODE,
        );
    }

    /**
     * @param class-string $jobClass
     */
    public static function invalidJobClass(StepKey $stepKey, string $jobClass): self
    {
        return new self(
            message: sprintf("Step '%s' references non-existent job class: %s", $stepKey->value, $jobClass),
            code: self::CODE,
        );
    }
}
