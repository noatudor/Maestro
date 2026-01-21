<?php

declare(strict_types=1);

namespace Maestro\Workflow\Exceptions;

use Maestro\Workflow\ValueObjects\StepKey;

final class StepDependencyException extends StepException
{
    private const int CODE = 3003;

    /**
     * @param class-string $outputClass
     */
    public static function missingRequiredOutput(StepKey $stepKey, string $outputClass): self
    {
        return new self(
            message: sprintf("Step '%s' requires output '%s' which is not available", $stepKey->value, $outputClass),
            code: self::CODE,
        );
    }

    public static function dependencyNotCompleted(StepKey $stepKey, StepKey $dependency): self
    {
        return new self(
            message: sprintf("Step '%s' depends on step '%s' which has not completed", $stepKey->value, $dependency->value),
            code: self::CODE,
        );
    }

    /**
     * @param list<class-string> $missingOutputs
     */
    public static function missingOutputs(StepKey $stepKey, array $missingOutputs): self
    {
        $outputList = implode(', ', $missingOutputs);

        return new self(
            message: sprintf("Step '%s' has missing dependencies: %s", $stepKey->value, $outputList),
            code: self::CODE,
        );
    }
}
