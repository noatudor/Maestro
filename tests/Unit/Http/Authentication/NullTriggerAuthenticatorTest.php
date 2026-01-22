<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Maestro\Workflow\Http\Authentication\NullTriggerAuthenticator;

describe('NullTriggerAuthenticator', static function (): void {
    describe('authenticate', static function (): void {
        it('always returns success', function (): void {
            $authenticator = new NullTriggerAuthenticator();
            $request = Request::create('/trigger', 'POST');

            $triggerAuthenticationResult = $authenticator->authenticate($request);

            expect($triggerAuthenticationResult->isAuthenticated())->toBeTrue();
            expect($triggerAuthenticationResult->failureReason())->toBeNull();
        });
    });
});
