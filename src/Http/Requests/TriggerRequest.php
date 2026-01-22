<?php

declare(strict_types=1);

namespace Maestro\Workflow\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Maestro\Workflow\ValueObjects\TriggerPayload;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Form request for external trigger endpoints.
 */
final class TriggerRequest extends FormRequest
{
    /**
     * @return array<string, array<string>>
     */
    public function rules(): array
    {
        return [
            'trigger_type' => ['required', 'string', 'max:255'],
            'payload' => ['sometimes', 'array'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function getTriggerType(): string
    {
        $triggerType = $this->validated('trigger_type');

        return is_string($triggerType) ? $triggerType : '';
    }

    public function getTriggerPayload(): TriggerPayload
    {
        $payload = $this->validated('payload', []);

        return TriggerPayload::fromArray(is_array($payload) ? $payload : []);
    }

    public function getWorkflowId(): WorkflowId
    {
        $workflowId = $this->route('workflowId');

        return WorkflowId::fromString((string) $workflowId);
    }
}
