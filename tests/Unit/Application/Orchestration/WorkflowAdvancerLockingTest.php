<?php

declare(strict_types=1);

use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Exceptions\WorkflowLockedException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\Tests\Fakes\InMemoryWorkflowRepository;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('Workflow repository database-level locking', function (): void {
    beforeEach(function (): void {
        $this->repository = new InMemoryWorkflowRepository();
    });

    it('acquires a row lock with withLockedWorkflow', function (): void {
        $workflowInstance = WorkflowInstance::create(
            definitionKey: DefinitionKey::fromString('test-workflow'),
            definitionVersion: DefinitionVersion::fromString('1.0.0'),
        );
        $this->repository->save($workflowInstance);

        $callbackExecuted = false;
        $this->repository->withLockedWorkflow($workflowInstance->id, static function (WorkflowInstance $workflowInstance) use (&$callbackExecuted): void {
            $callbackExecuted = true;
        });

        expect($callbackExecuted)->toBeTrue();
    });

    it('throws WorkflowNotFoundException for non-existent workflow', function (): void {
        $workflowId = WorkflowId::generate();

        expect(fn () => $this->repository->withLockedWorkflow($workflowId, static fn (): null => null))
            ->toThrow(WorkflowNotFoundException::class);
    });

    it('throws WorkflowLockedException when lock cannot be acquired', function (): void {
        $workflowInstance = WorkflowInstance::create(
            definitionKey: DefinitionKey::fromString('test-workflow'),
            definitionVersion: DefinitionVersion::fromString('1.0.0'),
        );
        $this->repository->save($workflowInstance);

        $this->repository->findAndLockForUpdate($workflowInstance->id);

        expect(fn () => $this->repository->withLockedWorkflow($workflowInstance->id, static fn (): null => null))
            ->toThrow(WorkflowLockedException::class);
    });

    it('releases lock after callback completes', function (): void {
        $workflowInstance = WorkflowInstance::create(
            definitionKey: DefinitionKey::fromString('test-workflow'),
            definitionVersion: DefinitionVersion::fromString('1.0.0'),
        );
        $this->repository->save($workflowInstance);

        $this->repository->withLockedWorkflow($workflowInstance->id, static fn (): null => null);

        $secondCallbackExecuted = false;
        $this->repository->withLockedWorkflow($workflowInstance->id, static function () use (&$secondCallbackExecuted): void {
            $secondCallbackExecuted = true;
        });

        expect($secondCallbackExecuted)->toBeTrue();
    });
});
