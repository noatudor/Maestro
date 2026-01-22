<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Maestro\Workflow\Http\Authentication\HmacTriggerAuthenticator;

describe('HmacTriggerAuthenticator', function (): void {
    beforeEach(function (): void {
        $this->secret = 'test-secret-key';
        $this->authenticator = new HmacTriggerAuthenticator($this->secret);
    });

    describe('authenticate', function (): void {
        it('fails when signature header is missing', function (): void {
            $request = Request::create('/trigger', 'POST', [], [], [], [], '{}');

            $result = $this->authenticator->authenticate($request);

            expect($result->isAuthenticated())->toBeFalse();
            expect($result->failureReason())->toBe('Missing signature header');
        });

        it('succeeds with valid signature', function (): void {
            $body = '{"trigger_type":"webhook"}';
            $signature = 'sha256='.hash_hmac('sha256', $body, $this->secret);

            $request = Request::create('/trigger', 'POST', [], [], [], [
                'HTTP_X_MAESTRO_SIGNATURE' => $signature,
            ], $body);

            $result = $this->authenticator->authenticate($request);

            expect($result->isAuthenticated())->toBeTrue();
        });

        it('fails with invalid signature', function (): void {
            $body = '{"trigger_type":"webhook"}';
            $signature = 'sha256='.hash_hmac('sha256', $body, 'wrong-secret');

            $request = Request::create('/trigger', 'POST', [], [], [], [
                'HTTP_X_MAESTRO_SIGNATURE' => $signature,
            ], $body);

            $result = $this->authenticator->authenticate($request);

            expect($result->isAuthenticated())->toBeFalse();
            expect($result->failureReason())->toBe('Invalid signature');
        });

        it('succeeds with valid timestamp and signature', function (): void {
            $body = '{"trigger_type":"webhook"}';
            $timestamp = (string) time();
            $payload = $timestamp.'.'.$body;
            $signature = 'sha256='.hash_hmac('sha256', $payload, $this->secret);

            $request = Request::create('/trigger', 'POST', [], [], [], [
                'HTTP_X_MAESTRO_SIGNATURE' => $signature,
                'HTTP_X_MAESTRO_TIMESTAMP' => $timestamp,
            ], $body);

            $result = $this->authenticator->authenticate($request);

            expect($result->isAuthenticated())->toBeTrue();
        });

        it('fails with expired timestamp', function (): void {
            $body = '{"trigger_type":"webhook"}';
            $timestamp = (string) (time() - 400);
            $payload = $timestamp.'.'.$body;
            $signature = 'sha256='.hash_hmac('sha256', $payload, $this->secret);

            $request = Request::create('/trigger', 'POST', [], [], [], [
                'HTTP_X_MAESTRO_SIGNATURE' => $signature,
                'HTTP_X_MAESTRO_TIMESTAMP' => $timestamp,
            ], $body);

            $result = $this->authenticator->authenticate($request);

            expect($result->isAuthenticated())->toBeFalse();
            expect($result->failureReason())->toBe('Request timestamp expired');
        });

        it('allows configurable timestamp drift', function (): void {
            $authenticator = new HmacTriggerAuthenticator($this->secret, 600);

            $body = '{"trigger_type":"webhook"}';
            $timestamp = (string) (time() - 400);
            $payload = $timestamp.'.'.$body;
            $signature = 'sha256='.hash_hmac('sha256', $payload, $this->secret);

            $request = Request::create('/trigger', 'POST', [], [], [], [
                'HTTP_X_MAESTRO_SIGNATURE' => $signature,
                'HTTP_X_MAESTRO_TIMESTAMP' => $timestamp,
            ], $body);

            $triggerAuthenticationResult = $authenticator->authenticate($request);

            expect($triggerAuthenticationResult->isAuthenticated())->toBeTrue();
        });
    });
});
