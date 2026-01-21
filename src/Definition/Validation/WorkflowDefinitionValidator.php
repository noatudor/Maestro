<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition\Validation;

use Maestro\Workflow\Contracts\ContextLoader;
use Maestro\Workflow\Contracts\FanOutStep;
use Maestro\Workflow\Contracts\SingleJobStep;
use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\Definition\WorkflowDefinition;

/**
 * Validates workflow definitions for correctness.
 */
final readonly class WorkflowDefinitionValidator
{
    private bool $checkClassExistence;

    public function __construct(bool $checkClassExistence = true)
    {
        $this->checkClassExistence = $checkClassExistence;
    }

    public function validate(WorkflowDefinition $definition): ValidationResult
    {
        $errors = [];

        $errors = [...$errors, ...$this->validateNotEmpty($definition)];
        $errors = [...$errors, ...$this->validateUniqueStepKeys($definition)];
        $errors = [...$errors, ...$this->validateOutputDependencies($definition)];

        if ($this->checkClassExistence) {
            $errors = [...$errors, ...$this->validateJobClassesExist($definition)];
            $errors = [...$errors, ...$this->validateOutputClassesExist($definition)];
            $errors = [...$errors, ...$this->validateContextLoaderExists($definition)];
        }

        return $errors === [] ? ValidationResult::valid() : ValidationResult::invalid($errors);
    }

    /**
     * @return list<ValidationError>
     */
    private function validateNotEmpty(WorkflowDefinition $definition): array
    {
        if ($definition->steps()->isEmpty()) {
            return [ValidationError::emptyWorkflow()];
        }

        return [];
    }

    /**
     * @return list<ValidationError>
     */
    private function validateUniqueStepKeys(WorkflowDefinition $definition): array
    {
        $errors = [];
        $seenKeys = [];

        foreach ($definition->steps() as $step) {
            $keyString = $step->key()->toString();

            if (isset($seenKeys[$keyString])) {
                $errors[] = ValidationError::duplicateStepKey($step->key());
            }

            $seenKeys[$keyString] = true;
        }

        return $errors;
    }

    /**
     * @return list<ValidationError>
     */
    private function validateOutputDependencies(WorkflowDefinition $definition): array
    {
        $errors = [];

        /** @var list<class-string<StepOutput>> $availableOutputs */
        $availableOutputs = [];

        foreach ($definition->steps() as $step) {
            foreach ($step->requires() as $requiredOutput) {
                if (! in_array($requiredOutput, $availableOutputs, true)) {
                    $errors[] = ValidationError::missingRequiredOutput($step->key(), $requiredOutput);
                }
            }

            $producedOutput = $step->produces();
            if ($producedOutput !== null) {
                $availableOutputs[] = $producedOutput;
            }
        }

        return $errors;
    }

    /**
     * @return list<ValidationError>
     */
    private function validateJobClassesExist(WorkflowDefinition $definition): array
    {
        $errors = [];

        foreach ($definition->steps() as $step) {
            $jobClass = null;

            if ($step instanceof SingleJobStep) {
                $jobClass = $step->jobClass();
            } elseif ($step instanceof FanOutStep) {
                $jobClass = $step->jobClass();
            }

            if ($jobClass !== null && ! class_exists($jobClass)) {
                $errors[] = ValidationError::jobClassNotFound($step->key(), $jobClass);
            }
        }

        return $errors;
    }

    /**
     * @return list<ValidationError>
     */
    private function validateOutputClassesExist(WorkflowDefinition $definition): array
    {
        $errors = [];

        foreach ($definition->steps() as $step) {
            foreach ($step->requires() as $requiredOutput) {
                if (! class_exists($requiredOutput) && ! interface_exists($requiredOutput)) {
                    $errors[] = ValidationError::outputClassNotFound($step->key(), $requiredOutput);
                }
            }

            $producedOutput = $step->produces();
            if ($producedOutput !== null && ! class_exists($producedOutput) && ! interface_exists($producedOutput)) {
                $errors[] = ValidationError::outputClassNotFound($step->key(), $producedOutput);
            }
        }

        return $errors;
    }

    /**
     * @return list<ValidationError>
     */
    private function validateContextLoaderExists(WorkflowDefinition $definition): array
    {
        $loaderClass = $definition->contextLoaderClass();

        if ($loaderClass === null) {
            return [];
        }

        if (! class_exists($loaderClass) && ! interface_exists($loaderClass)) {
            return [ValidationError::contextLoaderNotFound($loaderClass)];
        }

        if (! is_a($loaderClass, ContextLoader::class, true)) {
            return [ValidationError::custom(
                'INVALID_CONTEXT_LOADER',
                "Context loader class '{$loaderClass}' does not implement ContextLoader interface",
            )];
        }

        return [];
    }
}
