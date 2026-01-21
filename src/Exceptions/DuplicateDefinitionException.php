<?php

declare(strict_types=1);

namespace Maestro\Workflow\Exceptions;

use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;

final class DuplicateDefinitionException extends ConfigurationException
{
    public static function withKeyAndVersion(DefinitionKey $key, DefinitionVersion $version): self
    {
        return new self(
            message: "Workflow definition already registered: {$key->toString()} version {$version->toString()}",
            code: self::CODE_CONFIGURATION,
        );
    }
}
