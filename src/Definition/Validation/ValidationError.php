<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition\Validation;

use Maestro\Workflow\ValueObjects\StepKey;

/**
 * Represents a single validation error in a workflow definition.
 */
final readonly class ValidationError
{
    private function __construct(
        public string $code,
        public string $message,
        public ?StepKey $stepKey,
    ) {}

    public static function duplicateStepKey(StepKey $key): self
    {
        return new self(
            code: 'DUPLICATE_STEP_KEY',
            message: "Duplicate step key: {$key->toString()}",
            stepKey: $key,
        );
    }

    public static function missingRequiredOutput(StepKey $stepKey, string $outputClass): self
    {
        return new self(
            code: 'MISSING_REQUIRED_OUTPUT',
            message: "Step '{$stepKey->toString()}' requires output '{$outputClass}' but no prior step produces it",
            stepKey: $stepKey,
        );
    }

    public static function jobClassNotFound(StepKey $stepKey, string $jobClass): self
    {
        return new self(
            code: 'JOB_CLASS_NOT_FOUND',
            message: "Step '{$stepKey->toString()}' references non-existent job class: {$jobClass}",
            stepKey: $stepKey,
        );
    }

    public static function outputClassNotFound(StepKey $stepKey, string $outputClass): self
    {
        return new self(
            code: 'OUTPUT_CLASS_NOT_FOUND',
            message: "Step '{$stepKey->toString()}' references non-existent output class: {$outputClass}",
            stepKey: $stepKey,
        );
    }

    public static function contextLoaderNotFound(string $loaderClass): self
    {
        return new self(
            code: 'CONTEXT_LOADER_NOT_FOUND',
            message: "Context loader class not found: {$loaderClass}",
            stepKey: null,
        );
    }

    public static function emptyWorkflow(): self
    {
        return new self(
            code: 'EMPTY_WORKFLOW',
            message: 'Workflow definition has no steps',
            stepKey: null,
        );
    }

    public static function custom(string $code, string $message, ?StepKey $stepKey = null): self
    {
        return new self($code, $message, $stepKey);
    }

    public function hasStepContext(): bool
    {
        return $this->stepKey !== null;
    }
}
