<?php

declare(strict_types=1);

namespace Maestro\Workflow\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maestro\Workflow\Contracts\TriggerAuthenticator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware for authenticating incoming trigger requests.
 *
 * Uses the configured TriggerAuthenticator implementation.
 */
final readonly class AuthenticateTrigger
{
    public function __construct(
        private TriggerAuthenticator $triggerAuthenticator,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $triggerAuthenticationResult = $this->triggerAuthenticator->authenticate($request);

        if (! $triggerAuthenticationResult->isAuthenticated()) {
            return new JsonResponse([
                'error' => 'unauthorized',
                'message' => $triggerAuthenticationResult->failureReason() ?? 'Authentication failed',
            ], 401);
        }

        return $next($request);
    }
}
