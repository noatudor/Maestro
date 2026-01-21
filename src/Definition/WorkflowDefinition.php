<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition;

use Maestro\Workflow\Contracts\ContextLoader;
use Maestro\Workflow\Contracts\StepDefinition;
use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\StepKey;

/**
 * A complete workflow definition specifying all steps, their order, and configuration.
 */
final readonly class WorkflowDefinition
{
    /**
     * @param class-string<ContextLoader>|null $contextLoaderClass
     */
    private function __construct(
        private DefinitionKey $key,
        private DefinitionVersion $version,
        private string $displayName,
        private StepCollection $steps,
        private ?string $contextLoaderClass,
    ) {}

    /**
     * @param class-string<ContextLoader>|null $contextLoaderClass
     */
    public static function create(
        DefinitionKey $key,
        DefinitionVersion $version,
        string $displayName,
        StepCollection $steps,
        ?string $contextLoaderClass = null,
    ): self {
        return new self($key, $version, $displayName, $steps, $contextLoaderClass);
    }

    public function key(): DefinitionKey
    {
        return $this->key;
    }

    public function version(): DefinitionVersion
    {
        return $this->version;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function steps(): StepCollection
    {
        return $this->steps;
    }

    /**
     * @return class-string<ContextLoader>|null
     */
    public function contextLoaderClass(): ?string
    {
        return $this->contextLoaderClass;
    }

    public function hasContextLoader(): bool
    {
        return $this->contextLoaderClass !== null;
    }

    public function getStep(StepKey $key): ?StepDefinition
    {
        return $this->steps->findByKey($key);
    }

    public function hasStep(StepKey $key): bool
    {
        return $this->steps->hasKey($key);
    }

    public function getFirstStep(): ?StepDefinition
    {
        return $this->steps->first();
    }

    public function getNextStep(StepKey $currentKey): ?StepDefinition
    {
        return $this->steps->getNextStep($currentKey);
    }

    public function isLastStep(StepKey $key): bool
    {
        return $this->steps->isLastStep($key);
    }

    public function stepCount(): int
    {
        return $this->steps->count();
    }

    /**
     * Get all output classes that will be available after the given step completes.
     *
     * @return list<class-string<StepOutput>>
     */
    public function getAvailableOutputsAfterStep(StepKey $key): array
    {
        $outputs = [];

        foreach ($this->steps as $step) {
            $producedOutput = $step->produces();
            if ($producedOutput !== null) {
                $outputs[] = $producedOutput;
            }

            if ($step->key()->equals($key)) {
                break;
            }
        }

        return $outputs;
    }

    /**
     * Get all output classes that are available before the given step starts.
     *
     * @return list<class-string<StepOutput>>
     */
    public function getAvailableOutputsBeforeStep(StepKey $key): array
    {
        $outputs = [];

        foreach ($this->steps as $step) {
            if ($step->key()->equals($key)) {
                break;
            }

            $producedOutput = $step->produces();
            if ($producedOutput !== null) {
                $outputs[] = $producedOutput;
            }
        }

        return $outputs;
    }

    /**
     * Check if a step's requirements are satisfied by prior steps.
     */
    public function areRequirementsSatisfied(StepKey $key): bool
    {
        $step = $this->getStep($key);
        if ($step === null) {
            return false;
        }

        $availableOutputs = $this->getAvailableOutputsBeforeStep($key);
        $requiredOutputs = $step->requires();

        foreach ($requiredOutputs as $required) {
            if (! in_array($required, $availableOutputs, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get a unique identifier combining key and version.
     */
    public function identifier(): string
    {
        return $this->key->toString() . ':' . $this->version->toString();
    }
}
