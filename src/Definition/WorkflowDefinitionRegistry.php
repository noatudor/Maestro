<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition;

use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\DuplicateDefinitionException;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\DefinitionVersion;

/**
 * In-memory registry for workflow definitions.
 */
final class WorkflowDefinitionRegistry
{
    /**
     * Definitions indexed by "key:version".
     *
     * @var array<string, WorkflowDefinition>
     */
    private array $definitions = [];

    /**
     * Latest version for each key.
     *
     * @var array<string, DefinitionVersion>
     */
    private array $latestVersions = [];

    /**
     * @throws DuplicateDefinitionException
     */
    public function register(WorkflowDefinition $definition): void
    {
        $identifier = $definition->identifier();

        if (isset($this->definitions[$identifier])) {
            throw DuplicateDefinitionException::withKeyAndVersion(
                $definition->key(),
                $definition->version(),
            );
        }

        $this->definitions[$identifier] = $definition;
        $this->updateLatestVersion($definition);
    }

    /**
     * @throws DefinitionNotFoundException
     */
    public function get(DefinitionKey $key, DefinitionVersion $version): WorkflowDefinition
    {
        $identifier = $key->toString() . ':' . $version->toString();

        if (! isset($this->definitions[$identifier])) {
            throw DefinitionNotFoundException::withKeyAndVersion($key, $version);
        }

        return $this->definitions[$identifier];
    }

    public function find(DefinitionKey $key, DefinitionVersion $version): ?WorkflowDefinition
    {
        $identifier = $key->toString() . ':' . $version->toString();

        return $this->definitions[$identifier] ?? null;
    }

    /**
     * @throws DefinitionNotFoundException
     */
    public function getLatest(DefinitionKey $key): WorkflowDefinition
    {
        $keyString = $key->toString();

        if (! isset($this->latestVersions[$keyString])) {
            throw DefinitionNotFoundException::withKey($key);
        }

        return $this->get($key, $this->latestVersions[$keyString]);
    }

    public function findLatest(DefinitionKey $key): ?WorkflowDefinition
    {
        $keyString = $key->toString();

        if (! isset($this->latestVersions[$keyString])) {
            return null;
        }

        return $this->find($key, $this->latestVersions[$keyString]);
    }

    public function has(DefinitionKey $key, ?DefinitionVersion $version = null): bool
    {
        if ($version === null) {
            return isset($this->latestVersions[$key->toString()]);
        }

        $identifier = $key->toString() . ':' . $version->toString();

        return isset($this->definitions[$identifier]);
    }

    public function hasKey(DefinitionKey $key): bool
    {
        return isset($this->latestVersions[$key->toString()]);
    }

    /**
     * Get all versions registered for a key.
     *
     * @return list<WorkflowDefinition>
     */
    public function getAllVersions(DefinitionKey $key): array
    {
        $keyString = $key->toString();
        $versions = [];

        foreach ($this->definitions as $identifier => $definition) {
            if (str_starts_with($identifier, $keyString . ':')) {
                $versions[] = $definition;
            }
        }

        usort($versions, static function (WorkflowDefinition $a, WorkflowDefinition $b): int {
            return $a->version()->isNewerThan($b->version()) ? -1 : 1;
        });

        return $versions;
    }

    /**
     * @return list<DefinitionKey>
     */
    public function allKeys(): array
    {
        return array_map(
            static fn (string $keyString): DefinitionKey => DefinitionKey::fromString($keyString),
            array_keys($this->latestVersions),
        );
    }

    /**
     * @return list<WorkflowDefinition>
     */
    public function allLatest(): array
    {
        return array_map(
            fn (DefinitionKey $key): WorkflowDefinition => $this->getLatest($key),
            $this->allKeys(),
        );
    }

    /**
     * @return list<WorkflowDefinition>
     */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    public function count(): int
    {
        return count($this->definitions);
    }

    public function countKeys(): int
    {
        return count($this->latestVersions);
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    public function clear(): void
    {
        $this->definitions = [];
        $this->latestVersions = [];
    }

    private function updateLatestVersion(WorkflowDefinition $definition): void
    {
        $keyString = $definition->key()->toString();

        if (! isset($this->latestVersions[$keyString])) {
            $this->latestVersions[$keyString] = $definition->version();

            return;
        }

        $currentLatest = $this->latestVersions[$keyString];
        if ($definition->version()->isNewerThan($currentLatest)) {
            $this->latestVersions[$keyString] = $definition->version();
        }
    }
}
