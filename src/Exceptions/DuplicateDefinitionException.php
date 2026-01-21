<?php

declare(strict_types=1);

namespace Maestro\Workflow\Exceptions;

use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;

final class DuplicateDefinitionException extends ConfigurationException
{
    public static function withKeyAndVersion(DefinitionKey $definitionKey, DefinitionVersion $definitionVersion): self
    {
        return new self(
            message: sprintf('Workflow definition already registered: %s version %s', $definitionKey->toString(), $definitionVersion->toString()),
            code: self::CODE_CONFIGURATION,
        );
    }
}
