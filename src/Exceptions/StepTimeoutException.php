<?php

declare(strict_types=1);

namespace Maestro\Workflow\Exceptions;

use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\StepRunId;

final class StepTimeoutException extends StepException
{
    private const int CODE = 3004;

    public static function exceeded(StepRunId $stepRunId, StepKey $stepKey, int $timeoutSeconds): self
    {
        return new self(
            message: sprintf("Step '%s' (run %s) exceeded timeout of %d seconds", $stepKey->value, $stepRunId->value, $timeoutSeconds),
            code: self::CODE,
        );
    }
}
