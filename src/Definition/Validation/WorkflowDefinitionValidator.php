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
    public function __construct(private bool $checkClassExistence = true) {}

    public function validate(WorkflowDefinition $workflowDefinition): ValidationResult
    {
        $errors = [];

        $errors = [...$errors, ...$this->validateNotEmpty($workflowDefinition)];
        $errors = [...$errors, ...$this->validateUniqueStepKeys($workflowDefinition)];
        $errors = [...$errors, ...$this->validateOutputDependencies($workflowDefinition)];

        if ($this->checkClassExistence) {
            $errors = [...$errors, ...$this->validateJobClassesExist($workflowDefinition)];
            $errors = [...$errors, ...$this->validateOutputClassesExist($workflowDefinition)];
            $errors = [...$errors, ...$this->validateContextLoaderExists($workflowDefinition)];
        }

        return $errors === [] ? ValidationResult::valid() : ValidationResult::invalid($errors);
    }

    /**
     * @return list<ValidationError>
     */
    private function validateNotEmpty(WorkflowDefinition $workflowDefinition): array
    {
        if ($workflowDefinition->steps()->isEmpty()) {
            return [ValidationError::emptyWorkflow()];
        }

        return [];
    }

    /**
     * @return list<ValidationError>
     */
    private function validateUniqueStepKeys(WorkflowDefinition $workflowDefinition): array
    {
        $errors = [];
        $seenKeys = [];

        foreach ($workflowDefinition->steps() as $step) {
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
    private function validateOutputDependencies(WorkflowDefinition $workflowDefinition): array
    {
        $errors = [];

        /** @var list<class-string<StepOutput>> $availableOutputs */
        $availableOutputs = [];

        foreach ($workflowDefinition->steps() as $step) {
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
    private function validateJobClassesExist(WorkflowDefinition $workflowDefinition): array
    {
        $errors = [];

        foreach ($workflowDefinition->steps() as $step) {
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
    private function validateOutputClassesExist(WorkflowDefinition $workflowDefinition): array
    {
        $errors = [];

        foreach ($workflowDefinition->steps() as $step) {
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
    private function validateContextLoaderExists(WorkflowDefinition $workflowDefinition): array
    {
        $loaderClass = $workflowDefinition->contextLoaderClass();

        if ($loaderClass === null) {
            return [];
        }

        if (! class_exists($loaderClass) && ! interface_exists($loaderClass)) {
            return [ValidationError::contextLoaderNotFound($loaderClass)];
        }

        if (! is_a($loaderClass, ContextLoader::class, true)) {
            return [ValidationError::custom(
                'INVALID_CONTEXT_LOADER',
                sprintf("Context loader class '%s' does not implement ContextLoader interface", $loaderClass),
            )];
        }

        return [];
    }
}
