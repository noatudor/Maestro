<?php

declare(strict_types=1);

namespace Maestro\Workflow\Exceptions;

use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;

final class DefinitionNotFoundException extends ConfigurationException
{
    public static function withKey(DefinitionKey $definitionKey): self
    {
        return new self(
            message: 'Workflow definition not found: '.$definitionKey->toString(),
            code: self::CODE_CONFIGURATION,
        );
    }

    public static function withKeyAndVersion(DefinitionKey $definitionKey, DefinitionVersion $definitionVersion): self
    {
        return new self(
            message: sprintf('Workflow definition not found: %s version %s', $definitionKey->toString(), $definitionVersion->toString()),
            code: self::CODE_CONFIGURATION,
        );
    }
}
