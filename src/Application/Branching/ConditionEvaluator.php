<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Branching;

use Illuminate\Contracts\Container\Container;
use Maestro\Workflow\Application\Output\StepOutputStore;
use Maestro\Workflow\Contracts\BranchCondition;
use Maestro\Workflow\Contracts\ResumeCondition;
use Maestro\Workflow\Contracts\StepCondition;
use Maestro\Workflow\Contracts\TerminationCondition;
use Maestro\Workflow\Enums\SkipReason;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Exceptions\ConditionEvaluationException;
use Maestro\Workflow\ValueObjects\BranchKey;
use Maestro\Workflow\ValueObjects\ConditionResult;
use Maestro\Workflow\ValueObjects\ResumeConditionResult;
use Maestro\Workflow\ValueObjects\TerminationResult;
use Maestro\Workflow\ValueObjects\TriggerPayload;
use Throwable;

/**
 * Evaluates conditions during workflow execution.
 *
 * Conditions are resolved via the Laravel container, allowing
 * dependency injection for services needed during evaluation.
 */
final readonly class ConditionEvaluator
{
    public function __construct(
        private Container $container,
    ) {}

    /**
     * Evaluate a step condition to determine if the step should execute.
     *
     * @param class-string<StepCondition> $conditionClass
     *
     * @throws ConditionEvaluationException
     */
    public function evaluateStepCondition(
        string $conditionClass,
        StepOutputStore $stepOutputStore,
    ): ConditionResult {
        try {
            /** @var StepCondition $condition */
            $condition = $this->container->make($conditionClass);

            $shouldExecute = $condition->evaluate($stepOutputStore);

            if ($shouldExecute) {
                return ConditionResult::execute();
            }

            return ConditionResult::skip(
                SkipReason::ConditionFalse,
                sprintf('Condition %s evaluated to false', $conditionClass),
            );
        } catch (Throwable $e) {
            throw ConditionEvaluationException::forStepCondition($conditionClass, $e);
        }
    }

    /**
     * Evaluate a branch condition to determine which branches to follow.
     *
     * @param class-string<BranchCondition> $conditionClass
     *
     * @return list<BranchKey>
     *
     * @throws ConditionEvaluationException
     */
    public function evaluateBranchCondition(
        string $conditionClass,
        StepOutputStore $stepOutputStore,
    ): array {
        try {
            /** @var BranchCondition $condition */
            $condition = $this->container->make($conditionClass);

            $branchKeys = $condition->evaluate($stepOutputStore);

            return array_map(
                BranchKey::fromString(...),
                $branchKeys,
            );
        } catch (Throwable $e) {
            throw ConditionEvaluationException::forBranchCondition($conditionClass, $e);
        }
    }

    /**
     * Evaluate a termination condition to determine if workflow should end early.
     *
     * @param class-string<TerminationCondition> $conditionClass
     *
     * @throws ConditionEvaluationException
     */
    public function evaluateTerminationCondition(
        string $conditionClass,
        StepOutputStore $stepOutputStore,
    ): TerminationResult {
        try {
            /** @var TerminationCondition $condition */
            $condition = $this->container->make($conditionClass);

            if (! $condition->shouldTerminate($stepOutputStore)) {
                return TerminationResult::continue();
            }

            $state = $condition->terminalState();

            if (! in_array($state, [WorkflowState::Succeeded, WorkflowState::Failed], true)) {
                throw ConditionEvaluationException::invalidTerminalState($conditionClass, $state);
            }

            return TerminationResult::terminate(
                $state,
                $condition->terminationReason(),
            );
        } catch (ConditionEvaluationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ConditionEvaluationException::forTerminationCondition($conditionClass, $e);
        }
    }

    /**
     * Evaluate a resume condition to determine if workflow should resume from a trigger.
     *
     * @param class-string<ResumeCondition> $conditionClass
     *
     * @throws ConditionEvaluationException
     */
    public function evaluateResumeCondition(
        string $conditionClass,
        TriggerPayload $triggerPayload,
        StepOutputStore $stepOutputStore,
    ): ResumeConditionResult {
        try {
            /** @var ResumeCondition $condition */
            $condition = $this->container->make($conditionClass);

            if ($condition->shouldResume($triggerPayload, $stepOutputStore)) {
                return ResumeConditionResult::resume();
            }

            return ResumeConditionResult::reject($condition->rejectionReason());
        } catch (Throwable $e) {
            throw ConditionEvaluationException::forResumeCondition($conditionClass, $e);
        }
    }
}
