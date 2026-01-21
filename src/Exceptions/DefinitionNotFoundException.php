<?php

declare(strict_types=1);

namespace Maestro\Workflow\Exceptions;

use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;

final class DefinitionNotFoundException extends ConfigurationException
{
    public static function withKey(DefinitionKey $key): self
    {
        return new self(
            message: "Workflow definition not found: {$key->toString()}",
            code: self::CODE_CONFIGURATION,
        );
    }

    public static function withKeyAndVersion(DefinitionKey $key, DefinitionVersion $version): self
    {
        return new self(
            message: "Workflow definition not found: {$key->toString()} version {$version->toString()}",
            code: self::CODE_CONFIGURATION,
        );
    }
}
