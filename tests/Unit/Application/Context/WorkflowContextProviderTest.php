<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\Container;
use Maestro\Workflow\Application\Context\WorkflowContextProvider;
use Maestro\Workflow\Contracts\ContextLoader;
use Maestro\Workflow\Definition\StepCollection;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Tests\Fixtures\TestContextLoader;
use Maestro\Workflow\Tests\Fixtures\TestWorkflowContext;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('WorkflowContextProvider', function (): void {
    beforeEach(function (): void {
        $this->workflowId = WorkflowId::generate();
        $this->container = Mockery::mock(Container::class);
    });

    describe('get', function (): void {
        it('returns null when no context loader is configured', function (): void {
            $workflowDefinition = WorkflowDefinition::create(
                displayName: 'Test Workflow',
                key: DefinitionKey::fromString('test-workflow'),
                version: DefinitionVersion::fromString('1.0.0'),
                steps: StepCollection::empty(),
            );

            $provider = new WorkflowContextProvider($this->workflowId, $workflowDefinition, $this->container);

            expect($provider->get())->toBeNull();
        });

        it('loads context via context loader', function (): void {
            $workflowDefinition = WorkflowDefinition::create(
                displayName: 'Test Workflow',
                contextLoaderClass: TestContextLoader::class,
                key: DefinitionKey::fromString('test-workflow'),
                version: DefinitionVersion::fromString('1.0.0'),
                steps: StepCollection::empty(),
            );

            $loader = new TestContextLoader();
            $this->container->shouldReceive('make')
                ->once()
                ->with(TestContextLoader::class)
                ->andReturn($loader);

            $provider = new WorkflowContextProvider($this->workflowId, $workflowDefinition, $this->container);

            $context = $provider->get();

            expect($context)->toBeInstanceOf(TestWorkflowContext::class);
        });

        it('caches the context after first load', function (): void {
            $workflowDefinition = WorkflowDefinition::create(
                displayName: 'Test Workflow',
                contextLoaderClass: TestContextLoader::class,
                key: DefinitionKey::fromString('test-workflow'),
                version: DefinitionVersion::fromString('1.0.0'),
                steps: StepCollection::empty(),
            );

            $loader = new TestContextLoader();
            $this->container->shouldReceive('make')
                ->once()
                ->with(TestContextLoader::class)
                ->andReturn($loader);

            $provider = new WorkflowContextProvider($this->workflowId, $workflowDefinition, $this->container);

            $context1 = $provider->get();
            $context2 = $provider->get();

            expect($context1)->toBe($context2);
        });
    });

    describe('getTyped', function (): void {
        it('returns typed context when type matches', function (): void {
            $workflowDefinition = WorkflowDefinition::create(
                displayName: 'Test Workflow',
                contextLoaderClass: TestContextLoader::class,
                key: DefinitionKey::fromString('test-workflow'),
                version: DefinitionVersion::fromString('1.0.0'),
                steps: StepCollection::empty(),
            );

            $this->container->shouldReceive('make')
                ->once()
                ->with(TestContextLoader::class)
                ->andReturn(new TestContextLoader());

            $provider = new WorkflowContextProvider($this->workflowId, $workflowDefinition, $this->container);

            $context = $provider->getTyped(TestWorkflowContext::class);

            expect($context)->toBeInstanceOf(TestWorkflowContext::class);
        });

        it('returns null when type does not match', function (): void {
            $workflowDefinition = WorkflowDefinition::create(
                displayName: 'Test Workflow',
                contextLoaderClass: TestContextLoader::class,
                key: DefinitionKey::fromString('test-workflow'),
                version: DefinitionVersion::fromString('1.0.0'),
                steps: StepCollection::empty(),
            );

            $this->container->shouldReceive('make')
                ->once()
                ->with(TestContextLoader::class)
                ->andReturn(new TestContextLoader());

            $provider = new WorkflowContextProvider($this->workflowId, $workflowDefinition, $this->container);

            $context = $provider->getTyped(ContextLoader::class);

            expect($context)->toBeNull();
        });

        it('returns null when no context loader configured', function (): void {
            $workflowDefinition = WorkflowDefinition::create(
                displayName: 'Test Workflow',
                key: DefinitionKey::fromString('test-workflow'),
                version: DefinitionVersion::fromString('1.0.0'),
                steps: StepCollection::empty(),
            );

            $provider = new WorkflowContextProvider($this->workflowId, $workflowDefinition, $this->container);

            expect($provider->getTyped(TestWorkflowContext::class))->toBeNull();
        });
    });

    describe('hasContextLoader', function (): void {
        it('returns true when context loader is configured', function (): void {
            $workflowDefinition = WorkflowDefinition::create(
                displayName: 'Test Workflow',
                contextLoaderClass: TestContextLoader::class,
                key: DefinitionKey::fromString('test-workflow'),
                version: DefinitionVersion::fromString('1.0.0'),
                steps: StepCollection::empty(),
            );

            $provider = new WorkflowContextProvider($this->workflowId, $workflowDefinition, $this->container);

            expect($provider->hasContextLoader())->toBeTrue();
        });

        it('returns false when no context loader is configured', function (): void {
            $workflowDefinition = WorkflowDefinition::create(
                displayName: 'Test Workflow',
                key: DefinitionKey::fromString('test-workflow'),
                version: DefinitionVersion::fromString('1.0.0'),
                steps: StepCollection::empty(),
            );

            $provider = new WorkflowContextProvider($this->workflowId, $workflowDefinition, $this->container);

            expect($provider->hasContextLoader())->toBeFalse();
        });
    });

    describe('clearCache', function (): void {
        it('clears the cached context', function (): void {
            $workflowDefinition = WorkflowDefinition::create(
                displayName: 'Test Workflow',
                contextLoaderClass: TestContextLoader::class,
                key: DefinitionKey::fromString('test-workflow'),
                version: DefinitionVersion::fromString('1.0.0'),
                steps: StepCollection::empty(),
            );

            $this->container->shouldReceive('make')
                ->twice()
                ->with(TestContextLoader::class)
                ->andReturn(new TestContextLoader());

            $provider = new WorkflowContextProvider($this->workflowId, $workflowDefinition, $this->container);

            $context1 = $provider->get();
            $provider->clearCache();
            $context2 = $provider->get();

            expect($context1)->not->toBe($context2);
        });
    });

    describe('workflowId', function (): void {
        it('returns the workflow id', function (): void {
            $workflowDefinition = WorkflowDefinition::create(
                displayName: 'Test Workflow',
                key: DefinitionKey::fromString('test-workflow'),
                version: DefinitionVersion::fromString('1.0.0'),
                steps: StepCollection::empty(),
            );

            $provider = new WorkflowContextProvider($this->workflowId, $workflowDefinition, $this->container);

            expect($provider->workflowId()->equals($this->workflowId))->toBeTrue();
        });
    });
});
