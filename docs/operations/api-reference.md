# HTTP API Reference

Maestro provides a REST API for workflow management and external trigger integration.

## Configuration

Enable and configure the API in `config/maestro.php`:

```php
'api' => [
    'enabled' => true,
    'prefix' => 'api/maestro',
    'middleware' => ['api', 'auth:sanctum'],
    'rate_limit' => 60,  // requests per minute
],
```

## Endpoints

### List Workflows

```http
GET /api/maestro/workflows
```

List workflow instances with optional filtering.

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `state` | string | Filter by state |
| `definition_key` | string | Filter by definition |
| `limit` | int | Results per page (default: 20, max: 100) |
| `offset` | int | Pagination offset |

**Response:**

```json
{
    "data": [
        {
            "id": "abc-123",
            "definition_key": "order-processing",
            "definition_version": 1,
            "state": "running",
            "current_step_key": "process_payment",
            "created_at": "2024-01-15T10:00:00Z",
            "updated_at": "2024-01-15T10:05:00Z"
        }
    ],
    "meta": {
        "total": 150,
        "limit": 20,
        "offset": 0
    }
}
```

### Get Workflow Status

```http
GET /api/maestro/workflows/{workflowId}
```

Get basic workflow status.

**Response:**

```json
{
    "id": "abc-123",
    "definition_key": "order-processing",
    "definition_version": 1,
    "state": "running",
    "current_step_key": "process_payment",
    "created_at": "2024-01-15T10:00:00Z",
    "started_at": "2024-01-15T10:00:01Z",
    "updated_at": "2024-01-15T10:05:00Z",
    "completed_at": null,
    "failed_at": null
}
```

### Get Workflow Detail

```http
GET /api/maestro/workflows/{workflowId}/detail
```

Get detailed workflow information including steps and jobs.

**Response:**

```json
{
    "id": "abc-123",
    "definition_key": "order-processing",
    "definition_version": 1,
    "state": "running",
    "created_at": "2024-01-15T10:00:00Z",
    "steps": [
        {
            "key": "validate_order",
            "name": "Validate Order",
            "state": "succeeded",
            "started_at": "2024-01-15T10:00:01Z",
            "completed_at": "2024-01-15T10:00:02Z",
            "attempt_number": 1,
            "jobs": [
                {
                    "id": "job-001",
                    "class": "App\\Jobs\\ValidateOrderJob",
                    "state": "succeeded",
                    "started_at": "2024-01-15T10:00:01Z",
                    "completed_at": "2024-01-15T10:00:02Z"
                }
            ]
        },
        {
            "key": "process_payment",
            "name": "Process Payment",
            "state": "running",
            "started_at": "2024-01-15T10:00:03Z",
            "completed_at": null,
            "attempt_number": 1,
            "jobs": [
                {
                    "id": "job-002",
                    "class": "App\\Jobs\\ProcessPaymentJob",
                    "state": "running",
                    "started_at": "2024-01-15T10:00:03Z",
                    "completed_at": null
                }
            ]
        }
    ]
}
```

### Pause Workflow

```http
POST /api/maestro/workflows/{workflowId}/pause
```

Pause a running workflow.

**Request Body:**

```json
{
    "reason": "Investigation required"
}
```

**Response:**

```json
{
    "success": true,
    "workflow_id": "abc-123",
    "state": "paused",
    "message": "Workflow paused successfully"
}
```

### Resume Workflow

```http
POST /api/maestro/workflows/{workflowId}/resume
```

Resume a paused workflow.

**Response:**

```json
{
    "success": true,
    "workflow_id": "abc-123",
    "state": "running",
    "message": "Workflow resumed successfully"
}
```

### Cancel Workflow

```http
POST /api/maestro/workflows/{workflowId}/cancel
```

Cancel a workflow.

**Request Body:**

```json
{
    "compensate": true,
    "reason": "Customer cancelled order"
}
```

**Response:**

```json
{
    "success": true,
    "workflow_id": "abc-123",
    "state": "cancelled",
    "message": "Workflow cancelled successfully"
}
```

### Retry Workflow

```http
POST /api/maestro/workflows/{workflowId}/retry
```

Retry a failed workflow.

**Response:**

```json
{
    "success": true,
    "workflow_id": "abc-123",
    "state": "running",
    "message": "Workflow retry initiated"
}
```

### Retry from Step

```http
POST /api/maestro/workflows/{workflowId}/retry-from-step
```

Retry workflow from a specific step.

**Request Body:**

```json
{
    "step_key": "process_payment",
    "compensate_intermediate_steps": false
}
```

**Response:**

```json
{
    "success": true,
    "workflow_id": "abc-123",
    "step_key": "process_payment",
    "new_step_run_id": "run-456",
    "message": "Retry from step initiated"
}
```

### Resolve Workflow

```http
POST /api/maestro/workflows/{workflowId}/resolve
```

Make a resolution decision for a failed workflow.

**Request Body:**

```json
{
    "decision": "retry",
    "reason": "Service restored"
}
```

Available decisions:
- `retry` - Retry the failed step
- `compensate` - Trigger compensation
- `cancel` - Cancel the workflow
- `mark_resolved` - Mark as manually resolved

**Response:**

```json
{
    "success": true,
    "workflow_id": "abc-123",
    "decision": "retry",
    "message": "Resolution decision applied"
}
```

### Trigger External Event

```http
POST /api/maestro/workflows/{workflowId}/trigger/{triggerKey}
```

Send an external trigger to a waiting workflow.

**Headers (if HMAC auth enabled):**

```
X-Maestro-Timestamp: 1705312200
X-Maestro-Signature: sha256=abc123...
```

**Request Body:**

```json
{
    "type": "approval",
    "payload": {
        "approved": true,
        "approver_id": "user-123",
        "approved_at": "2024-01-15T10:30:00Z",
        "notes": "Approved with conditions"
    }
}
```

**Response (accepted):**

```json
{
    "success": true,
    "workflow_id": "abc-123",
    "trigger_key": "manager-approval",
    "accepted": true,
    "message": "Trigger accepted, workflow resumed"
}
```

**Response (rejected):**

```json
{
    "success": false,
    "workflow_id": "abc-123",
    "trigger_key": "manager-approval",
    "accepted": false,
    "rejection_reason": "Resume condition not met: approver lacks authority"
}
```

### Compensate Workflow

```http
POST /api/maestro/workflows/{workflowId}/compensate
```

Trigger compensation for a workflow.

**Request Body:**

```json
{
    "scope": "all"
}
```

Available scopes:
- `all` - Compensate all completed steps
- `failed_step_only` - Compensate only the failed step
- `from_step` - Compensate from specific step (include `from_step` field)

**Response:**

```json
{
    "success": true,
    "workflow_id": "abc-123",
    "state": "compensating",
    "message": "Compensation initiated"
}
```

## Error Responses

### Validation Error (400)

```json
{
    "error": "validation_error",
    "message": "The given data was invalid",
    "errors": {
        "decision": ["The decision field is required."]
    }
}
```

### Not Found (404)

```json
{
    "error": "not_found",
    "message": "Workflow not found"
}
```

### Invalid State (409)

```json
{
    "error": "invalid_state",
    "message": "Cannot pause workflow in state: succeeded"
}
```

### Authentication Error (401)

```json
{
    "error": "unauthenticated",
    "message": "Invalid or missing authentication"
}
```

### Rate Limited (429)

```json
{
    "error": "rate_limited",
    "message": "Too many requests",
    "retry_after": 60
}
```

## HMAC Authentication

For trigger endpoints with HMAC authentication enabled:

### Computing the Signature

```php
$timestamp = time();
$payload = json_encode($data);
$stringToSign = "{$timestamp}.{$payload}";
$signature = hash_hmac('sha256', $stringToSign, $secret);

// Request headers
$headers = [
    'X-Maestro-Timestamp' => $timestamp,
    'X-Maestro-Signature' => "sha256={$signature}",
    'Content-Type' => 'application/json',
];
```

### Example in JavaScript

```javascript
const crypto = require('crypto');

function signRequest(payload, secret) {
    const timestamp = Math.floor(Date.now() / 1000);
    const body = JSON.stringify(payload);
    const stringToSign = `${timestamp}.${body}`;
    const signature = crypto
        .createHmac('sha256', secret)
        .update(stringToSign)
        .digest('hex');

    return {
        headers: {
            'X-Maestro-Timestamp': timestamp,
            'X-Maestro-Signature': `sha256=${signature}`,
            'Content-Type': 'application/json'
        },
        body
    };
}
```

### Configuration

```php
'trigger_auth' => [
    'driver' => 'hmac',
    'hmac' => [
        'secret' => env('MAESTRO_TRIGGER_SECRET'),
        'max_timestamp_drift_seconds' => 300,
    ],
],
```

## Pagination

List endpoints support pagination:

```http
GET /api/maestro/workflows?limit=20&offset=40
```

Response includes pagination metadata:

```json
{
    "data": [...],
    "meta": {
        "total": 150,
        "limit": 20,
        "offset": 40,
        "has_more": true
    }
}
```

## Rate Limiting

Default rate limit is 60 requests per minute per user/IP.

Rate limit headers are included in responses:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
X-RateLimit-Reset: 1705312260
```

## SDK Examples

### PHP (Guzzle)

```php
use GuzzleHttp\Client;

$client = new Client([
    'base_uri' => 'https://your-app.com/api/maestro/',
    'headers' => [
        'Authorization' => 'Bearer ' . $apiToken,
        'Accept' => 'application/json',
    ],
]);

// List workflows
$response = $client->get('workflows', [
    'query' => ['state' => 'failed'],
]);
$workflows = json_decode($response->getBody(), true);

// Retry a workflow
$response = $client->post("workflows/{$workflowId}/retry");
```

### JavaScript (Axios)

```javascript
import axios from 'axios';

const client = axios.create({
    baseURL: 'https://your-app.com/api/maestro/',
    headers: {
        Authorization: `Bearer ${apiToken}`,
    },
});

// List workflows
const { data: workflows } = await client.get('workflows', {
    params: { state: 'failed' },
});

// Trigger external event
await client.post(`workflows/${workflowId}/trigger/approval`, {
    type: 'approval',
    payload: { approved: true },
});
```

## Next Steps

- [Events Reference](events.md) - Webhook events
- [Console Commands](console-commands.md) - CLI alternatives
- [External Triggers](../guide/advanced/external-triggers.md) - Trigger integration
