<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Context;

use Illuminate\Contracts\Container\Container;
use Maestro\Workflow\Contracts\ContextLoader;
use Maestro\Workflow\Contracts\WorkflowContext;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Provides lazy-loaded, cached access to workflow context.
 *
 * The context is loaded on first access and cached for the duration
 * of the provider's lifetime (typically a single job execution).
 */
final class WorkflowContextProvider
{
    private ?WorkflowContext $workflowContext = null;

    public function __construct(
        private readonly WorkflowId $workflowId,
        private readonly WorkflowDefinition $workflowDefinition,
        private readonly Container $container,
    ) {}

    /**
     * Get the workflow context.
     *
     * The context is lazy-loaded on first access and cached for subsequent calls.
     * Returns null if no context loader is configured for the workflow.
     */
    public function get(): ?WorkflowContext
    {
        if ($this->workflowContext instanceof WorkflowContext) {
            return $this->workflowContext;
        }

        if (! $this->workflowDefinition->hasContextLoader()) {
            return null;
        }

        $loaderClass = $this->workflowDefinition->contextLoaderClass();

        if ($loaderClass === null) {
            return null;
        }

        /** @var ContextLoader $contextLoader */
        $contextLoader = $this->container->make($loaderClass);

        $this->workflowContext = $contextLoader->load($this->workflowId);

        return $this->workflowContext;
    }

    /**
     * Get the workflow context, typed.
     *
     * @template T of WorkflowContext
     *
     * @param class-string<T> $contextClass
     *
     * @return T|null
     */
    public function getTyped(string $contextClass): ?WorkflowContext
    {
        $context = $this->get();

        if (! $context instanceof WorkflowContext) {
            return null;
        }

        if (! $context instanceof $contextClass) {
            return null;
        }

        return $context;
    }

    /**
     * Check if a context loader is configured.
     */
    public function hasContextLoader(): bool
    {
        return $this->workflowDefinition->hasContextLoader();
    }

    /**
     * Clear the cached context.
     *
     * Useful for testing or when context needs to be refreshed.
     */
    public function clearCache(): void
    {
        $this->workflowContext = null;
    }

    /**
     * Get the workflow ID.
     */
    public function workflowId(): WorkflowId
    {
        return $this->workflowId;
    }
}
