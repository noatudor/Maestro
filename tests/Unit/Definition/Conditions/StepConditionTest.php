<?php

declare(strict_types=1);

use Maestro\Workflow\Contracts\WorkflowContext;
use Maestro\Workflow\Definition\Conditions\AlwaysCondition;
use Maestro\Workflow\Definition\Conditions\ClosureCondition;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;

describe('StepCondition', static function (): void {
    describe('AlwaysCondition', static function (): void {
        it('always returns true', function (): void {
            $condition = new AlwaysCondition();
            $workflow = WorkflowInstance::create(
                DefinitionKey::fromString('test'),
                DefinitionVersion::fromString('1.0.0'),
            );
            $context = Mockery::mock(WorkflowContext::class);

            expect($condition->shouldExecute($workflow, $context))->toBeTrue();
        });
    });

    describe('ClosureCondition', static function (): void {
        it('evaluates custom closure', function (): void {
            $condition = ClosureCondition::create(
                fn (WorkflowInstance $workflow, WorkflowContext $context): bool => true
            );
            $workflow = WorkflowInstance::create(
                DefinitionKey::fromString('test'),
                DefinitionVersion::fromString('1.0.0'),
            );
            $context = Mockery::mock(WorkflowContext::class);

            expect($condition->shouldExecute($workflow, $context))->toBeTrue();
        });

        it('evaluates to false when closure returns false', function (): void {
            $condition = ClosureCondition::create(
                fn (WorkflowInstance $workflow, WorkflowContext $context): bool => false
            );
            $workflow = WorkflowInstance::create(
                DefinitionKey::fromString('test'),
                DefinitionVersion::fromString('1.0.0'),
            );
            $context = Mockery::mock(WorkflowContext::class);

            expect($condition->shouldExecute($workflow, $context))->toBeFalse();
        });

        it('can access workflow instance in closure', function (): void {
            $condition = ClosureCondition::create(
                fn (WorkflowInstance $workflow, WorkflowContext $context): bool =>
                    $workflow->definitionKey->toString() === 'specific-workflow'
            );

            $matchingWorkflow = WorkflowInstance::create(
                DefinitionKey::fromString('specific-workflow'),
                DefinitionVersion::fromString('1.0.0'),
            );
            $nonMatchingWorkflow = WorkflowInstance::create(
                DefinitionKey::fromString('other-workflow'),
                DefinitionVersion::fromString('1.0.0'),
            );
            $context = Mockery::mock(WorkflowContext::class);

            expect($condition->shouldExecute($matchingWorkflow, $context))->toBeTrue();
            expect($condition->shouldExecute($nonMatchingWorkflow, $context))->toBeFalse();
        });
    });
});
