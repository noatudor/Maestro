<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

use Illuminate\Http\Request;
use Maestro\Workflow\Http\TriggerAuthenticationResult;

/**
 * Contract for authenticating incoming trigger requests.
 *
 * Implementations can use HMAC signatures, API keys, or any other authentication mechanism.
 */
interface TriggerAuthenticator
{
    /**
     * Authenticate an incoming trigger request.
     */
    public function authenticate(Request $request): TriggerAuthenticationResult;
}
