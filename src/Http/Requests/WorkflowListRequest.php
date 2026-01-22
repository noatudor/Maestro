<?php

declare(strict_types=1);

namespace Maestro\Workflow\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Exceptions\InvalidDefinitionKeyException;
use Maestro\Workflow\ValueObjects\DefinitionKey;

/**
 * Form request for workflow list endpoints.
 */
final class WorkflowListRequest extends FormRequest
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'state' => ['sometimes', 'string', Rule::enum(WorkflowState::class)],
            'definition_key' => ['sometimes', 'string', 'max:255'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function getState(): ?WorkflowState
    {
        $state = $this->validated('state');

        if (! is_string($state)) {
            return null;
        }

        return WorkflowState::from($state);
    }

    /**
     * @throws InvalidDefinitionKeyException
     */
    public function getDefinitionKey(): ?DefinitionKey
    {
        $key = $this->validated('definition_key');

        if (! is_string($key)) {
            return null;
        }

        return DefinitionKey::fromString($key);
    }
}
