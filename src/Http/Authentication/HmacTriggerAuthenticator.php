<?php

declare(strict_types=1);

namespace Maestro\Workflow\Http\Authentication;

use Illuminate\Http\Request;
use Maestro\Workflow\Contracts\TriggerAuthenticator;
use Maestro\Workflow\Http\TriggerAuthenticationResult;

/**
 * Authenticates triggers using HMAC-SHA256 signature validation.
 *
 * The signature is expected in the X-Maestro-Signature header.
 * Format: sha256=<hex-encoded-signature>
 */
final readonly class HmacTriggerAuthenticator implements TriggerAuthenticator
{
    private const string SIGNATURE_HEADER = 'X-Maestro-Signature';

    private const string TIMESTAMP_HEADER = 'X-Maestro-Timestamp';

    private const int MAX_TIMESTAMP_DRIFT_SECONDS = 300;

    public function __construct(
        private string $secret,
        private int $maxTimestampDrift = self::MAX_TIMESTAMP_DRIFT_SECONDS,
    ) {}

    public function authenticate(Request $request): TriggerAuthenticationResult
    {
        $signature = $request->header(self::SIGNATURE_HEADER);

        if ($signature === null) {
            return TriggerAuthenticationResult::failure('Missing signature header');
        }

        $timestamp = $request->header(self::TIMESTAMP_HEADER);

        if ($timestamp !== null && ! $this->isTimestampValid($timestamp)) {
            return TriggerAuthenticationResult::failure('Request timestamp expired');
        }

        $payload = $this->buildPayload($request, $timestamp);
        $expectedSignature = $this->computeSignature($payload);

        if (! hash_equals($expectedSignature, $signature)) {
            return TriggerAuthenticationResult::failure('Invalid signature');
        }

        return TriggerAuthenticationResult::success();
    }

    private function isTimestampValid(string $timestamp): bool
    {
        $requestTime = (int) $timestamp;
        $currentTime = time();

        return abs($currentTime - $requestTime) <= $this->maxTimestampDrift;
    }

    private function buildPayload(Request $request, ?string $timestamp): string
    {
        $body = $request->getContent();

        if ($timestamp !== null) {
            return $timestamp.'.'.$body;
        }

        return $body;
    }

    private function computeSignature(string $payload): string
    {
        return 'sha256='.hash_hmac('sha256', $payload, $this->secret);
    }
}
