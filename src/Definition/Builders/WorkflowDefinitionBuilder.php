<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition\Builders;

use Closure;
use Maestro\Workflow\Contracts\ContextLoader;
use Maestro\Workflow\Contracts\StepDefinition;
use Maestro\Workflow\Definition\Config\FailureResolutionConfig;
use Maestro\Workflow\Definition\StepCollection;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Exceptions\InvalidDefinitionKeyException;
use Maestro\Workflow\Exceptions\InvalidDefinitionVersionException;
use Maestro\Workflow\Exceptions\InvalidStepKeyException;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;

final class WorkflowDefinitionBuilder
{
    private DefinitionVersion $definitionVersion;

    private string $displayName;

    /** @var list<StepDefinition> */
    private array $steps = [];

    /** @var class-string<ContextLoader>|null */
    private ?string $contextLoaderClass = null;

    private ?FailureResolutionConfig $failureResolutionConfig = null;

    private function __construct(
        private readonly DefinitionKey $definitionKey,
    ) {
        $this->definitionVersion = DefinitionVersion::initial();
        $this->displayName = $definitionKey->toString();
    }

    /**
     * @throws InvalidDefinitionKeyException
     */
    public static function create(string $key): self
    {
        return new self(DefinitionKey::fromString($key));
    }

    /**
     * @throws InvalidDefinitionVersionException
     */
    public function version(string $version): self
    {
        $this->definitionVersion = DefinitionVersion::fromString($version);

        return $this;
    }

    public function displayName(string $name): self
    {
        $this->displayName = $name;

        return $this;
    }

    /**
     * @param class-string<ContextLoader> $loaderClass
     */
    public function contextLoader(string $loaderClass): self
    {
        $this->contextLoaderClass = $loaderClass;

        return $this;
    }

    /**
     * Configure failure resolution strategy for this workflow.
     *
     * This determines how the workflow handles step failures after
     * exhausting step-level retries.
     */
    public function failureResolution(FailureResolutionConfig $failureResolutionConfig): self
    {
        $this->failureResolutionConfig = $failureResolutionConfig;

        return $this;
    }

    public function addStep(StepDefinition $stepDefinition): self
    {
        $this->steps[] = $stepDefinition;

        return $this;
    }

    /**
     * Add a single job step using a fluent builder.
     *
     * @param Closure(SingleJobStepBuilder): SingleJobStepBuilder $configure
     *
     * @throws InvalidStepKeyException
     */
    public function singleJob(string $key, Closure $configure): self
    {
        $singleJobStepBuilder = SingleJobStepBuilder::create($key);
        $configure($singleJobStepBuilder);
        $this->steps[] = $singleJobStepBuilder->build();

        return $this;
    }

    /**
     * Add a fan-out step using a fluent builder.
     *
     * @param Closure(FanOutStepBuilder): FanOutStepBuilder $configure
     *
     * @throws InvalidStepKeyException
     */
    public function fanOut(string $key, Closure $configure): self
    {
        $fanOutStepBuilder = FanOutStepBuilder::create($key);
        $configure($fanOutStepBuilder);
        $this->steps[] = $fanOutStepBuilder->build();

        return $this;
    }

    /**
     * Add a polling step using a fluent builder.
     *
     * Polling steps execute repeatedly until a condition is met or timeout.
     *
     * @param Closure(PollingStepBuilder): PollingStepBuilder $configure
     *
     * @throws InvalidStepKeyException
     */
    public function polling(string $key, Closure $configure): self
    {
        $pollingStepBuilder = PollingStepBuilder::create($key);
        $configure($pollingStepBuilder);
        $this->steps[] = $pollingStepBuilder->build();

        return $this;
    }

    public function build(): WorkflowDefinition
    {
        return WorkflowDefinition::create(
            $this->definitionKey,
            $this->definitionVersion,
            $this->displayName,
            StepCollection::fromArray($this->steps),
            $this->contextLoaderClass,
            $this->failureResolutionConfig,
        );
    }
}
