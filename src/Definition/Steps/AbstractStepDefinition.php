<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition\Steps;

use Maestro\Workflow\Contracts\StepDefinition;
use Maestro\Workflow\Contracts\StepOutput;
use Maestro\Workflow\Definition\Config\QueueConfiguration;
use Maestro\Workflow\Definition\Config\RetryConfiguration;
use Maestro\Workflow\Definition\Config\StepTimeout;
use Maestro\Workflow\Enums\FailurePolicy;
use Maestro\Workflow\ValueObjects\StepKey;

abstract readonly class AbstractStepDefinition implements StepDefinition
{
    /**
     * @param list<class-string<StepOutput>> $requires
     * @param class-string<StepOutput>|null $produces
     */
    protected function __construct(
        private StepKey $key,
        private string $displayName,
        private array $requires,
        private ?string $produces,
        private FailurePolicy $failurePolicy,
        private RetryConfiguration $retryConfiguration,
        private StepTimeout $timeout,
        private QueueConfiguration $queueConfiguration,
    ) {}

    final public function key(): StepKey
    {
        return $this->key;
    }

    final public function displayName(): string
    {
        return $this->displayName;
    }

    final public function requires(): array
    {
        return $this->requires;
    }

    final public function produces(): ?string
    {
        return $this->produces;
    }

    final public function failurePolicy(): FailurePolicy
    {
        return $this->failurePolicy;
    }

    final public function retryConfiguration(): RetryConfiguration
    {
        return $this->retryConfiguration;
    }

    final public function timeout(): StepTimeout
    {
        return $this->timeout;
    }

    final public function queueConfiguration(): QueueConfiguration
    {
        return $this->queueConfiguration;
    }

    final public function hasRequirements(): bool
    {
        return $this->requires !== [];
    }

    final public function producesOutput(): bool
    {
        return $this->produces !== null;
    }
}
