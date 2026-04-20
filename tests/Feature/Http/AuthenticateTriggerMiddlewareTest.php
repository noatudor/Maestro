<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Maestro\Workflow\Contracts\TriggerAuthenticator;
use Maestro\Workflow\Http\Middleware\AuthenticateTrigger;
use Maestro\Workflow\Http\TriggerAuthenticationResult;

describe('AuthenticateTrigger Middleware', function () {
    it('allows authenticated requests', function () {
        $authenticator = Mockery::mock(TriggerAuthenticator::class);
        $authenticator->shouldReceive('authenticate')
            ->once()
            ->andReturn(TriggerAuthenticationResult::success());

        $middleware = new AuthenticateTrigger($authenticator);

        $request = Request::create('/test', 'POST');
        $called = false;

        $response = $middleware->handle($request, function () use (&$called) {
            $called = true;

            return response('OK');
        });

        expect($called)->toBeTrue()
            ->and($response->getContent())->toBe('OK');
    });

    it('blocks unauthenticated requests with 401', function () {
        $authenticator = Mockery::mock(TriggerAuthenticator::class);
        $authenticator->shouldReceive('authenticate')
            ->once()
            ->andReturn(TriggerAuthenticationResult::failure('Invalid signature'));

        $middleware = new AuthenticateTrigger($authenticator);

        $request = Request::create('/test', 'POST');
        $called = false;

        $response = $middleware->handle($request, function () use (&$called) {
            $called = true;

            return response('OK');
        });

        expect($called)->toBeFalse()
            ->and($response->getStatusCode())->toBe(401)
            ->and($response->getContent())->toContain('Invalid signature');
    });

    it('uses provided message in failure response', function () {
        $authenticator = Mockery::mock(TriggerAuthenticator::class);
        $authenticator->shouldReceive('authenticate')
            ->once()
            ->andReturn(TriggerAuthenticationResult::failure('Custom error message'));

        $middleware = new AuthenticateTrigger($authenticator);

        $request = Request::create('/test', 'POST');

        $response = $middleware->handle($request, fn () => response('OK'));

        expect($response->getStatusCode())->toBe(401)
            ->and($response->getContent())->toContain('Custom error message');
    });
});
