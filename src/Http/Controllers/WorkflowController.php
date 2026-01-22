<?php

declare(strict_types=1);

namespace Maestro\Workflow\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Maestro\Workflow\Application\Orchestration\ExternalTriggerHandler;
use Maestro\Workflow\Application\Query\WorkflowQueryService;
use Maestro\Workflow\Contracts\WorkflowManager;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Exceptions\InvalidDefinitionKeyException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\Http\Requests\TriggerRequest;
use Maestro\Workflow\Http\Requests\WorkflowListRequest;
use Maestro\Workflow\Http\Responses\TriggerResponseDTO;
use Maestro\Workflow\Http\Responses\WorkflowStatusDTO;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\WorkflowId;
use Throwable;

/**
 * HTTP controller for workflow API endpoints.
 */
final class WorkflowController extends Controller
{
    public function __construct(
        private readonly WorkflowManager $workflowManager,
        private readonly WorkflowQueryService $workflowQueryService,
        private readonly ExternalTriggerHandler $externalTriggerHandler,
    ) {}

    /**
     * List workflows with optional filtering.
     *
     * @throws InvalidDefinitionKeyException
     */
    public function index(WorkflowListRequest $workflowListRequest): JsonResponse
    {
        $state = $workflowListRequest->getState();
        $definitionKey = $workflowListRequest->getDefinitionKey();

        if ($definitionKey instanceof DefinitionKey) {
            $result = $this->workflowQueryService->getWorkflowsByDefinition($definitionKey);
        } elseif ($state instanceof WorkflowState) {
            $result = $this->workflowQueryService->getWorkflowsByState($state);
        } else {
            $result = $this->workflowQueryService->getRunningWorkflows();
        }

        return response()->json($result->toArray());
    }

    /**
     * Get workflow status.
     */
    public function show(string $workflowId): JsonResponse
    {
        try {
            $result = $this->workflowQueryService->getWorkflowStatus(
                WorkflowId::fromString($workflowId),
            );

            return response()->json($result->toArray());
        } catch (WorkflowNotFoundException $e) {
            return response()->json([
                'error' => 'workflow_not_found',
                'message' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Get detailed workflow information including steps and jobs.
     */
    public function detail(string $workflowId): JsonResponse
    {
        try {
            $result = $this->workflowQueryService->getWorkflowDetail(
                WorkflowId::fromString($workflowId),
            );

            return response()->json($result->toArray());
        } catch (WorkflowNotFoundException $e) {
            return response()->json([
                'error' => 'workflow_not_found',
                'message' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Pause a running workflow.
     */
    public function pause(string $workflowId): JsonResponse
    {
        try {
            $workflow = $this->workflowManager->pauseWorkflow(
                WorkflowId::fromString($workflowId),
            );

            return response()->json(
                WorkflowStatusDTO::fromWorkflowInstance($workflow)->toArray(),
            );
        } catch (WorkflowNotFoundException $e) {
            return response()->json([
                'error' => 'workflow_not_found',
                'message' => $e->getMessage(),
            ], 404);
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'operation_failed',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Resume a paused workflow.
     */
    public function resume(string $workflowId): JsonResponse
    {
        try {
            $workflow = $this->workflowManager->resumeWorkflow(
                WorkflowId::fromString($workflowId),
            );

            return response()->json(
                WorkflowStatusDTO::fromWorkflowInstance($workflow)->toArray(),
            );
        } catch (WorkflowNotFoundException $e) {
            return response()->json([
                'error' => 'workflow_not_found',
                'message' => $e->getMessage(),
            ], 404);
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'operation_failed',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Cancel a workflow.
     */
    public function cancel(string $workflowId): JsonResponse
    {
        try {
            $workflow = $this->workflowManager->cancelWorkflow(
                WorkflowId::fromString($workflowId),
            );

            return response()->json(
                WorkflowStatusDTO::fromWorkflowInstance($workflow)->toArray(),
            );
        } catch (WorkflowNotFoundException $e) {
            return response()->json([
                'error' => 'workflow_not_found',
                'message' => $e->getMessage(),
            ], 404);
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'operation_failed',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Retry a failed workflow.
     */
    public function retry(string $workflowId): JsonResponse
    {
        try {
            $workflow = $this->workflowManager->retryWorkflow(
                WorkflowId::fromString($workflowId),
            );

            return response()->json(
                WorkflowStatusDTO::fromWorkflowInstance($workflow)->toArray(),
            );
        } catch (WorkflowNotFoundException $e) {
            return response()->json([
                'error' => 'workflow_not_found',
                'message' => $e->getMessage(),
            ], 404);
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'operation_failed',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Handle an external trigger for a workflow.
     */
    public function trigger(TriggerRequest $triggerRequest, string $workflowId): JsonResponse
    {
        try {
            $result = $this->externalTriggerHandler->handleTrigger(
                WorkflowId::fromString($workflowId),
                $triggerRequest->getTriggerType(),
                $triggerRequest->getTriggerPayload(),
            );

            $response = TriggerResponseDTO::fromTriggerResult($result);

            return response()->json(
                $response->toArray(),
                $result->isSuccess() ? 200 : 422,
            );
        } catch (WorkflowNotFoundException $e) {
            return response()->json([
                'error' => 'workflow_not_found',
                'message' => $e->getMessage(),
            ], 404);
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'trigger_failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
