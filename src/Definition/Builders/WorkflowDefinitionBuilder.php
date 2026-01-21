<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition\Builders;

use Closure;
use Maestro\Workflow\Contracts\ContextLoader;
use Maestro\Workflow\Contracts\StepDefinition;
use Maestro\Workflow\Definition\StepCollection;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;

final class WorkflowDefinitionBuilder
{
    private DefinitionVersion $version;

    private string $displayName;

    /** @var list<StepDefinition> */
    private array $steps = [];

    /** @var class-string<ContextLoader>|null */
    private ?string $contextLoaderClass = null;

    private function __construct(
        private readonly DefinitionKey $key,
    ) {
        $this->version = DefinitionVersion::initial();
        $this->displayName = $key->toString();
    }

    /**
     * @throws \Maestro\Workflow\Exceptions\InvalidDefinitionKeyException
     */
    public static function create(string $key): self
    {
        return new self(DefinitionKey::fromString($key));
    }

    /**
     * @throws \Maestro\Workflow\Exceptions\InvalidDefinitionVersionException
     */
    public function version(string $version): self
    {
        $this->version = DefinitionVersion::fromString($version);

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

    public function addStep(StepDefinition $step): self
    {
        $this->steps[] = $step;

        return $this;
    }

    /**
     * Add a single job step using a fluent builder.
     *
     * @param Closure(SingleJobStepBuilder): SingleJobStepBuilder $configure
     *
     * @throws \Maestro\Workflow\Exceptions\InvalidStepKeyException
     */
    public function singleJob(string $key, Closure $configure): self
    {
        $builder = SingleJobStepBuilder::create($key);
        $configure($builder);
        $this->steps[] = $builder->build();

        return $this;
    }

    /**
     * Add a fan-out step using a fluent builder.
     *
     * @param Closure(FanOutStepBuilder): FanOutStepBuilder $configure
     *
     * @throws \Maestro\Workflow\Exceptions\InvalidStepKeyException
     */
    public function fanOut(string $key, Closure $configure): self
    {
        $builder = FanOutStepBuilder::create($key);
        $configure($builder);
        $this->steps[] = $builder->build();

        return $this;
    }

    public function build(): WorkflowDefinition
    {
        return WorkflowDefinition::create(
            $this->key,
            $this->version,
            $this->displayName,
            StepCollection::fromArray($this->steps),
            $this->contextLoaderClass,
        );
    }
}
