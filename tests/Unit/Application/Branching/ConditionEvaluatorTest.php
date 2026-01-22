<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\Container;
use Maestro\Workflow\Application\Branching\ConditionEvaluator;
use Maestro\Workflow\Application\Output\StepOutputStore;
use Maestro\Workflow\Contracts\BranchCondition;
use Maestro\Workflow\Contracts\StepCondition;
use Maestro\Workflow\Contracts\StepOutputReader;
use Maestro\Workflow\Contracts\TerminationCondition;
use Maestro\Workflow\Enums\SkipReason;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Exceptions\ConditionEvaluationException;
use Maestro\Workflow\Tests\Fakes\InMemoryStepOutputRepository;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('ConditionEvaluator', function (): void {
    beforeEach(function (): void {
        $this->container = Mockery::mock(Container::class);
        $this->evaluator = new ConditionEvaluator($this->container);
        $this->outputs = new StepOutputStore(
            WorkflowId::generate(),
            new InMemoryStepOutputRepository(),
        );
    });

    describe('evaluateStepCondition', function (): void {
        it('returns execute result when condition returns true', function (): void {
            $condition = new class implements StepCondition
            {
                public function evaluate(StepOutputReader $stepOutputReader): bool
                {
                    return true;
                }
            };

            $this->container->shouldReceive('make')
                ->with($condition::class)
                ->once()
                ->andReturn($condition);

            $result = $this->evaluator->evaluateStepCondition($condition::class, $this->outputs);

            expect($result->shouldExecute())->toBeTrue();
            expect($result->shouldSkip())->toBeFalse();
            expect($result->skipReason())->toBeNull();
        });

        it('returns skip result when condition returns false', function (): void {
            $condition = new class implements StepCondition
            {
                public function evaluate(StepOutputReader $stepOutputReader): bool
                {
                    return false;
                }
            };

            $this->container->shouldReceive('make')
                ->with($condition::class)
                ->once()
                ->andReturn($condition);

            $result = $this->evaluator->evaluateStepCondition($condition::class, $this->outputs);

            expect($result->shouldExecute())->toBeFalse();
            expect($result->shouldSkip())->toBeTrue();
            expect($result->skipReason())->toBe(SkipReason::ConditionFalse);
            expect($result->skipMessage())->toContain('evaluated to false');
        });

        it('throws ConditionEvaluationException on error', function (): void {
            $condition = new class implements StepCondition
            {
                public function evaluate(StepOutputReader $stepOutputReader): bool
                {
                    throw new RuntimeException('Evaluation failed');
                }
            };

            $this->container->shouldReceive('make')
                ->with($condition::class)
                ->once()
                ->andReturn($condition);

            expect(fn () => $this->evaluator->evaluateStepCondition($condition::class, $this->outputs))
                ->toThrow(ConditionEvaluationException::class);
        });
    });

    describe('evaluateBranchCondition', function (): void {
        it('returns branch keys from condition', function (): void {
            $condition = new class implements BranchCondition
            {
                public function evaluate(StepOutputReader $stepOutputReader): array
                {
                    return ['success', 'notification'];
                }
            };

            $this->container->shouldReceive('make')
                ->with($condition::class)
                ->once()
                ->andReturn($condition);

            $result = $this->evaluator->evaluateBranchCondition($condition::class, $this->outputs);

            expect($result)->toHaveCount(2);
            expect($result[0]->value)->toBe('success');
            expect($result[1]->value)->toBe('notification');
        });

        it('returns single branch key for exclusive branches', function (): void {
            $condition = new class implements BranchCondition
            {
                public function evaluate(StepOutputReader $stepOutputReader): array
                {
                    return ['failure'];
                }
            };

            $this->container->shouldReceive('make')
                ->with($condition::class)
                ->once()
                ->andReturn($condition);

            $result = $this->evaluator->evaluateBranchCondition($condition::class, $this->outputs);

            expect($result)->toHaveCount(1);
            expect($result[0]->value)->toBe('failure');
        });

        it('throws ConditionEvaluationException on error', function (): void {
            $condition = new class implements BranchCondition
            {
                public function evaluate(StepOutputReader $stepOutputReader): array
                {
                    throw new RuntimeException('Branch evaluation failed');
                }
            };

            $this->container->shouldReceive('make')
                ->with($condition::class)
                ->once()
                ->andReturn($condition);

            expect(fn () => $this->evaluator->evaluateBranchCondition($condition::class, $this->outputs))
                ->toThrow(ConditionEvaluationException::class);
        });
    });

    describe('evaluateTerminationCondition', function (): void {
        it('returns continue result when condition returns false', function (): void {
            $condition = new class implements TerminationCondition
            {
                public function shouldTerminate(StepOutputReader $stepOutputReader): bool
                {
                    return false;
                }

                public function terminalState(): WorkflowState
                {
                    return WorkflowState::Succeeded;
                }

                public function terminationReason(): string
                {
                    return 'Not used';
                }
            };

            $this->container->shouldReceive('make')
                ->with($condition::class)
                ->once()
                ->andReturn($condition);

            $result = $this->evaluator->evaluateTerminationCondition($condition::class, $this->outputs);

            expect($result->shouldContinue())->toBeTrue();
            expect($result->shouldTerminate())->toBeFalse();
            expect($result->terminalState())->toBeNull();
        });

        it('returns terminate result with Succeeded state', function (): void {
            $condition = new class implements TerminationCondition
            {
                public function shouldTerminate(StepOutputReader $stepOutputReader): bool
                {
                    return true;
                }

                public function terminalState(): WorkflowState
                {
                    return WorkflowState::Succeeded;
                }

                public function terminationReason(): string
                {
                    return 'All goals achieved';
                }
            };

            $this->container->shouldReceive('make')
                ->with($condition::class)
                ->once()
                ->andReturn($condition);

            $result = $this->evaluator->evaluateTerminationCondition($condition::class, $this->outputs);

            expect($result->shouldTerminate())->toBeTrue();
            expect($result->shouldContinue())->toBeFalse();
            expect($result->terminalState())->toBe(WorkflowState::Succeeded);
            expect($result->reason())->toBe('All goals achieved');
        });

        it('returns terminate result with Failed state', function (): void {
            $condition = new class implements TerminationCondition
            {
                public function shouldTerminate(StepOutputReader $stepOutputReader): bool
                {
                    return true;
                }

                public function terminalState(): WorkflowState
                {
                    return WorkflowState::Failed;
                }

                public function terminationReason(): string
                {
                    return 'Critical error detected';
                }
            };

            $this->container->shouldReceive('make')
                ->with($condition::class)
                ->once()
                ->andReturn($condition);

            $result = $this->evaluator->evaluateTerminationCondition($condition::class, $this->outputs);

            expect($result->shouldTerminate())->toBeTrue();
            expect($result->terminalState())->toBe(WorkflowState::Failed);
            expect($result->reason())->toBe('Critical error detected');
        });

        it('throws when terminal state is invalid', function (): void {
            $condition = new class implements TerminationCondition
            {
                public function shouldTerminate(StepOutputReader $stepOutputReader): bool
                {
                    return true;
                }

                public function terminalState(): WorkflowState
                {
                    return WorkflowState::Running;
                }

                public function terminationReason(): string
                {
                    return 'Invalid state';
                }
            };

            $this->container->shouldReceive('make')
                ->with($condition::class)
                ->once()
                ->andReturn($condition);

            expect(fn () => $this->evaluator->evaluateTerminationCondition($condition::class, $this->outputs))
                ->toThrow(ConditionEvaluationException::class, 'invalid terminal state');
        });

        it('throws ConditionEvaluationException on error', function (): void {
            $condition = new class implements TerminationCondition
            {
                public function shouldTerminate(StepOutputReader $stepOutputReader): bool
                {
                    throw new RuntimeException('Termination check failed');
                }

                public function terminalState(): WorkflowState
                {
                    return WorkflowState::Succeeded;
                }

                public function terminationReason(): string
                {
                    return 'Not used';
                }
            };

            $this->container->shouldReceive('make')
                ->with($condition::class)
                ->once()
                ->andReturn($condition);

            expect(fn () => $this->evaluator->evaluateTerminationCondition($condition::class, $this->outputs))
                ->toThrow(ConditionEvaluationException::class);
        });
    });
});
