# Trigger Authentication

This guide covers configuring authentication for external triggers in Maestro.

## Overview

External triggers allow workflows to be resumed by external systems (webhooks, approvals, etc.). Authentication ensures only authorized systems can trigger workflow state changes.

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        Trigger Authentication Flow                           │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   External System                                                            │
│   ┌──────────────────────────────────────────────────────────────────────┐  │
│   │                                                                      │  │
│   │   1. Build payload: { "approved": true, ... }                       │  │
│   │   2. Generate signature: HMAC-SHA256(payload, secret)               │  │
│   │   3. Add headers:                                                   │  │
│   │      • X-Maestro-Signature: sha256={signature}                      │  │
│   │      • X-Maestro-Timestamp: {unix_timestamp}                        │  │
│   │                                                                      │  │
│   └──────────────────────────────────────────────────────────────────────┘  │
│                                    │                                         │
│                                    │ POST /api/maestro/workflows/{id}/trigger│
│                                    ▼                                         │
│   Maestro API                                                                │
│   ┌──────────────────────────────────────────────────────────────────────┐  │
│   │                                                                      │  │
│   │   ┌────────────────────────────────────────────────────────────────┐│  │
│   │   │                   Trigger Authenticator                        ││  │
│   │   │                                                                ││  │
│   │   │   1. Extract signature from header                             ││  │
│   │   │   2. Verify timestamp (within allowed drift)                   ││  │
│   │   │   3. Compute expected signature                                ││  │
│   │   │   4. Compare signatures (constant-time)                        ││  │
│   │   │   5. Return authenticated or reject                            ││  │
│   │   │                                                                ││  │
│   │   └────────────────────────────────────────────────────────────────┘│  │
│   │                               │                                      │  │
│   │                          ┌────┴────┐                                │  │
│   │                          │         │                                │  │
│   │                          ▼         ▼                                │  │
│   │                      Authenticated  Rejected                        │  │
│   │                          │         │                                │  │
│   │                          ▼         ▼                                │  │
│   │                   Process Trigger  401 Unauthorized                 │  │
│   │                                                                      │  │
│   └──────────────────────────────────────────────────────────────────────┘  │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Configuration

### Basic Setup

```php
// config/maestro.php
'trigger_auth' => [
    // Driver: 'hmac' or 'null'
    'driver' => env('MAESTRO_TRIGGER_AUTH_DRIVER', 'hmac'),

    // HMAC settings
    'hmac' => [
        // Shared secret for signature generation
        'secret' => env('MAESTRO_TRIGGER_SECRET'),

        // Maximum allowed timestamp drift (prevents replay attacks)
        'max_timestamp_drift_seconds' => env('MAESTRO_TRIGGER_MAX_DRIFT', 300),
    ],
],
```

### Environment Variables

```bash
# .env
MAESTRO_TRIGGER_AUTH_DRIVER=hmac
MAESTRO_TRIGGER_SECRET=your-256-bit-secret-key-here
MAESTRO_TRIGGER_MAX_DRIFT=300
```

Generate a secure secret:

```bash
# Generate a random 32-byte (256-bit) hex string
openssl rand -hex 32
```

## HMAC Authentication

### How It Works

1. The external system creates a payload
2. Computes HMAC-SHA256 signature using shared secret
3. Includes signature and timestamp in request headers
4. Maestro verifies the signature using the same algorithm

### Request Format

```http
POST /api/maestro/workflows/{workflow_id}/trigger/{trigger_key} HTTP/1.1
Host: your-app.com
Content-Type: application/json
X-Maestro-Signature: sha256=abc123...
X-Maestro-Timestamp: 1705123456

{"approved": true, "approver": "manager@example.com"}
```

### Signature Generation

```php
// Client-side signature generation
function generateSignature(array $payload, string $secret, int $timestamp): string
{
    $body = json_encode($payload);
    $signaturePayload = "{$timestamp}.{$body}";

    return 'sha256=' . hash_hmac('sha256', $signaturePayload, $secret);
}

// Example usage
$payload = ['approved' => true];
$secret = 'your-secret-key';
$timestamp = time();

$signature = generateSignature($payload, $secret, $timestamp);
```

### JavaScript Example

```javascript
async function triggerWorkflow(workflowId, triggerKey, payload) {
    const secret = process.env.MAESTRO_SECRET;
    const timestamp = Math.floor(Date.now() / 1000);
    const body = JSON.stringify(payload);

    // Generate signature
    const signaturePayload = `${timestamp}.${body}`;
    const signature = crypto
        .createHmac('sha256', secret)
        .update(signaturePayload)
        .digest('hex');

    const response = await fetch(
        `https://your-app.com/api/maestro/workflows/${workflowId}/trigger/${triggerKey}`,
        {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Maestro-Signature': `sha256=${signature}`,
                'X-Maestro-Timestamp': timestamp.toString(),
            },
            body: body,
        }
    );

    return response.json();
}
```

### Python Example

```python
import hmac
import hashlib
import json
import time
import requests

def trigger_workflow(workflow_id, trigger_key, payload):
    secret = os.environ['MAESTRO_SECRET']
    timestamp = int(time.time())
    body = json.dumps(payload)

    # Generate signature
    signature_payload = f"{timestamp}.{body}"
    signature = hmac.new(
        secret.encode(),
        signature_payload.encode(),
        hashlib.sha256
    ).hexdigest()

    response = requests.post(
        f"https://your-app.com/api/maestro/workflows/{workflow_id}/trigger/{trigger_key}",
        headers={
            'Content-Type': 'application/json',
            'X-Maestro-Signature': f'sha256={signature}',
            'X-Maestro-Timestamp': str(timestamp),
        },
        data=body,
    )

    return response.json()
```

## Null Authentication

For development or when using external authentication middleware:

```php
// config/maestro.php
'trigger_auth' => [
    'driver' => 'null', // Disables built-in authentication
],
```

When using null authentication, secure your triggers with Laravel middleware:

```php
// config/maestro.php
'api' => [
    'enabled' => true,
    'prefix' => 'api/maestro',
    'middleware' => ['api', 'auth:sanctum'], // Use Sanctum
],
```

## Custom Authenticator

Implement your own authentication logic:

### Create Authenticator Class

```php
<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Http\Request;
use Maestro\Workflow\Contracts\TriggerAuthenticator;
use Maestro\Workflow\Http\TriggerAuthenticationResult;

final readonly class CustomTriggerAuthenticator implements TriggerAuthenticator
{
    public function authenticate(Request $request): TriggerAuthenticationResult
    {
        // Your authentication logic
        $apiKey = $request->header('X-API-Key');

        if (!$apiKey) {
            return TriggerAuthenticationResult::failed('Missing API key');
        }

        $validKey = ApiKey::where('key', $apiKey)
            ->where('active', true)
            ->first();

        if (!$validKey) {
            return TriggerAuthenticationResult::failed('Invalid API key');
        }

        return TriggerAuthenticationResult::authenticated([
            'api_key_id' => $validKey->id,
            'client_name' => $validKey->client_name,
        ]);
    }
}
```

### Register Custom Authenticator

```php
// AppServiceProvider
public function register(): void
{
    $this->app->bind(
        TriggerAuthenticator::class,
        CustomTriggerAuthenticator::class
    );
}
```

## Multi-Tenant Authentication

For systems with multiple tenants:

```php
<?php

declare(strict_types=1);

namespace App\Auth;

use Maestro\Workflow\Contracts\TriggerAuthenticator;
use Maestro\Workflow\Http\TriggerAuthenticationResult;

final readonly class TenantTriggerAuthenticator implements TriggerAuthenticator
{
    public function authenticate(Request $request): TriggerAuthenticationResult
    {
        $tenantId = $request->route('tenant_id');
        $signature = $request->header('X-Maestro-Signature');
        $timestamp = $request->header('X-Maestro-Timestamp');

        if (!$signature || !$timestamp) {
            return TriggerAuthenticationResult::failed('Missing authentication headers');
        }

        // Get tenant-specific secret
        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            return TriggerAuthenticationResult::failed('Invalid tenant');
        }

        // Verify signature with tenant's secret
        $expectedSignature = $this->computeSignature(
            $request->getContent(),
            $timestamp,
            $tenant->trigger_secret
        );

        if (!hash_equals($expectedSignature, $signature)) {
            return TriggerAuthenticationResult::failed('Invalid signature');
        }

        return TriggerAuthenticationResult::authenticated([
            'tenant_id' => $tenantId,
        ]);
    }
}
```

## Webhook Integration Examples

### Stripe

```php
// Verify Stripe webhook signature
public function handleStripeWebhook(Request $request)
{
    $payload = $request->getContent();
    $sigHeader = $request->header('Stripe-Signature');
    $secret = config('services.stripe.webhook_secret');

    try {
        $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $secret);

        // Find workflow waiting for this payment
        $workflow = WorkflowModel::where('metadata->stripe_payment_intent', $event->data->object->id)
            ->first();

        if ($workflow) {
            // Trigger workflow via Maestro API
            $handler = app(ExternalTriggerHandler::class);
            $handler->handleTrigger(
                WorkflowId::fromString($workflow->id),
                StepKey::fromString('payment-confirmation'),
                new TriggerPayload([
                    'status' => $event->type,
                    'payment_intent' => $event->data->object->id,
                ])
            );
        }

        return response()->json(['status' => 'ok']);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 400);
    }
}
```

### GitHub

```php
// Verify GitHub webhook signature
public function handleGitHubWebhook(Request $request)
{
    $signature = $request->header('X-Hub-Signature-256');
    $secret = config('services.github.webhook_secret');
    $payload = $request->getContent();

    $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

    if (!hash_equals($expectedSignature, $signature)) {
        return response()->json(['error' => 'Invalid signature'], 401);
    }

    // Process webhook...
}
```

## Security Best Practices

### 1. Use Strong Secrets

```bash
# Generate cryptographically secure secret
openssl rand -base64 32
```

### 2. Rotate Secrets Periodically

```php
// Support multiple secrets during rotation
'trigger_auth' => [
    'hmac' => [
        'secrets' => [
            'current' => env('MAESTRO_TRIGGER_SECRET'),
            'previous' => env('MAESTRO_TRIGGER_SECRET_OLD'), // During rotation
        ],
    ],
],
```

### 3. Enable Timestamp Verification

```php
'trigger_auth' => [
    'hmac' => [
        'max_timestamp_drift_seconds' => 300, // 5 minutes
    ],
],
```

### 4. Use HTTPS Only

```php
// Force HTTPS in production
if (app()->environment('production')) {
    URL::forceScheme('https');
}
```

### 5. Log Authentication Failures

```php
Event::listen(TriggerValidationFailed::class, function ($event) {
    Log::warning('Trigger authentication failed', [
        'workflow_id' => $event->workflowId->value,
        'reason' => $event->reason,
        'ip' => request()->ip(),
    ]);
});
```

## Troubleshooting

### Signature Mismatch

1. Verify both sides use the same secret
2. Check timestamp is Unix seconds (not milliseconds)
3. Ensure JSON encoding is consistent
4. Verify signature format includes `sha256=` prefix

### Clock Drift Issues

If timestamps are frequently rejected:

```php
// Increase allowed drift
'max_timestamp_drift_seconds' => 600, // 10 minutes
```

Or sync system clocks with NTP.

## Next Steps

- [External Triggers](../guide/advanced/external-triggers.md) - Trigger usage guide
- [API Reference](../operations/api-reference.md) - Full API docs
- [Events](../operations/events.md) - Trigger events
