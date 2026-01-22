<?php

declare(strict_types=1);

namespace Maestro\Workflow\Http\Authentication;

use Illuminate\Http\Request;
use Maestro\Workflow\Contracts\TriggerAuthenticator;
use Maestro\Workflow\Http\TriggerAuthenticationResult;

/**
 * Authenticator that allows all requests.
 *
 * Use this when authentication is handled by external middleware (e.g., Sanctum)
 * or when no authentication is required for triggers.
 */
final readonly class NullTriggerAuthenticator implements TriggerAuthenticator
{
    public function authenticate(Request $request): TriggerAuthenticationResult
    {
        return TriggerAuthenticationResult::success();
    }
}
