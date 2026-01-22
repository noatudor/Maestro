<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition;

use Maestro\Workflow\Contracts\ContextLoader;
use Maestro\Workflow\Contracts\StepDefinition;
use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\Definition\Config\FailureResolutionConfig;
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
        private DefinitionKey $definitionKey,
        private DefinitionVersion $definitionVersion,
        private string $displayName,
        private StepCollection $stepCollection,
        private ?string $contextLoaderClass,
        private FailureResolutionConfig $failureResolutionConfig,
    ) {}

    /**
     * @param class-string<ContextLoader>|null $contextLoaderClass
     */
    public static function create(
        DefinitionKey $definitionKey,
        DefinitionVersion $definitionVersion,
        string $displayName,
        StepCollection $stepCollection,
        ?string $contextLoaderClass = null,
        ?FailureResolutionConfig $failureResolutionConfig = null,
    ): self {
        return new self(
            $definitionKey,
            $definitionVersion,
            $displayName,
            $stepCollection,
            $contextLoaderClass,
            $failureResolutionConfig ?? FailureResolutionConfig::default(),
        );
    }

    public function key(): DefinitionKey
    {
        return $this->definitionKey;
    }

    public function version(): DefinitionVersion
    {
        return $this->definitionVersion;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function steps(): StepCollection
    {
        return $this->stepCollection;
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

    public function failureResolution(): FailureResolutionConfig
    {
        return $this->failureResolutionConfig;
    }

    public function getStep(StepKey $stepKey): ?StepDefinition
    {
        return $this->stepCollection->findByKey($stepKey);
    }

    public function hasStep(StepKey $stepKey): bool
    {
        return $this->stepCollection->hasKey($stepKey);
    }

    public function getFirstStep(): ?StepDefinition
    {
        return $this->stepCollection->first();
    }

    public function getNextStep(StepKey $stepKey): ?StepDefinition
    {
        return $this->stepCollection->getNextStep($stepKey);
    }

    public function isLastStep(StepKey $stepKey): bool
    {
        return $this->stepCollection->isLastStep($stepKey);
    }

    public function stepCount(): int
    {
        return $this->stepCollection->count();
    }

    /**
     * Get all output classes that will be available after the given step completes.
     *
     * @return list<class-string<StepOutput>>
     */
    public function getAvailableOutputsAfterStep(StepKey $stepKey): array
    {
        $outputs = [];

        foreach ($this->stepCollection as $step) {
            $producedOutput = $step->produces();
            if ($producedOutput !== null) {
                $outputs[] = $producedOutput;
            }

            if ($step->key()->equals($stepKey)) {
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
    public function getAvailableOutputsBeforeStep(StepKey $stepKey): array
    {
        $outputs = [];

        foreach ($this->stepCollection as $step) {
            if ($step->key()->equals($stepKey)) {
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
    public function areRequirementsSatisfied(StepKey $stepKey): bool
    {
        $step = $this->getStep($stepKey);
        if (! $step instanceof StepDefinition) {
            return false;
        }

        $availableOutputs = $this->getAvailableOutputsBeforeStep($stepKey);
        $requiredOutputs = $step->requires();

        return array_all($requiredOutputs, static fn ($required): bool => in_array($required, $availableOutputs, true));
    }

    /**
     * Get a unique identifier combining key and version.
     */
    public function identifier(): string
    {
        return $this->definitionKey->toString().':'.$this->definitionVersion->toString();
    }
}
