<?php

declare(strict_types=1);

namespace Maestro\Workflow\Definition;

use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\DuplicateDefinitionException;
use Maestro\Workflow\Exceptions\InvalidDefinitionKeyException;
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
    public function register(WorkflowDefinition $workflowDefinition): void
    {
        $identifier = $workflowDefinition->identifier();

        if (isset($this->definitions[$identifier])) {
            throw DuplicateDefinitionException::withKeyAndVersion(
                $workflowDefinition->key(),
                $workflowDefinition->version(),
            );
        }

        $this->definitions[$identifier] = $workflowDefinition;
        $this->updateLatestVersion($workflowDefinition);
    }

    /**
     * @throws DefinitionNotFoundException
     */
    public function get(DefinitionKey $definitionKey, DefinitionVersion $definitionVersion): WorkflowDefinition
    {
        $identifier = $definitionKey->toString().':'.$definitionVersion->toString();

        if (! isset($this->definitions[$identifier])) {
            throw DefinitionNotFoundException::withKeyAndVersion($definitionKey, $definitionVersion);
        }

        return $this->definitions[$identifier];
    }

    public function find(DefinitionKey $definitionKey, DefinitionVersion $definitionVersion): ?WorkflowDefinition
    {
        $identifier = $definitionKey->toString().':'.$definitionVersion->toString();

        return $this->definitions[$identifier] ?? null;
    }

    /**
     * @throws DefinitionNotFoundException
     */
    public function getLatest(DefinitionKey $definitionKey): WorkflowDefinition
    {
        $keyString = $definitionKey->toString();

        if (! isset($this->latestVersions[$keyString])) {
            throw DefinitionNotFoundException::withKey($definitionKey);
        }

        return $this->get($definitionKey, $this->latestVersions[$keyString]);
    }

    public function findLatest(DefinitionKey $definitionKey): ?WorkflowDefinition
    {
        $keyString = $definitionKey->toString();

        if (! isset($this->latestVersions[$keyString])) {
            return null;
        }

        return $this->find($definitionKey, $this->latestVersions[$keyString]);
    }

    public function has(DefinitionKey $definitionKey, ?DefinitionVersion $definitionVersion = null): bool
    {
        if (! $definitionVersion instanceof DefinitionVersion) {
            return isset($this->latestVersions[$definitionKey->toString()]);
        }

        $identifier = $definitionKey->toString().':'.$definitionVersion->toString();

        return isset($this->definitions[$identifier]);
    }

    public function hasKey(DefinitionKey $definitionKey): bool
    {
        return isset($this->latestVersions[$definitionKey->toString()]);
    }

    /**
     * Get all versions registered for a key.
     *
     * @return list<WorkflowDefinition>
     */
    public function getAllVersions(DefinitionKey $definitionKey): array
    {
        $keyString = $definitionKey->toString();
        $versions = [];

        foreach ($this->definitions as $identifier => $definition) {
            if (str_starts_with($identifier, $keyString.':')) {
                $versions[] = $definition;
            }
        }

        usort($versions, static fn (WorkflowDefinition $a, WorkflowDefinition $b): int => $a->version()->isNewerThan($b->version()) ? -1 : 1);

        return $versions;
    }

    /**
     * @return list<DefinitionKey>
     *
     * @throws InvalidDefinitionKeyException
     */
    public function allKeys(): array
    {
        return array_map(
            DefinitionKey::fromString(...),
            array_keys($this->latestVersions),
        );
    }

    /**
     * @return list<WorkflowDefinition>
     *
     * @throws DefinitionNotFoundException
     * @throws InvalidDefinitionKeyException
     */
    public function allLatest(): array
    {
        return array_map(
            $this->getLatest(...),
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

    private function updateLatestVersion(WorkflowDefinition $workflowDefinition): void
    {
        $keyString = $workflowDefinition->key()->toString();

        if (! isset($this->latestVersions[$keyString])) {
            $this->latestVersions[$keyString] = $workflowDefinition->version();

            return;
        }

        $currentLatest = $this->latestVersions[$keyString];
        if ($workflowDefinition->version()->isNewerThan($currentLatest)) {
            $this->latestVersions[$keyString] = $workflowDefinition->version();
        }
    }
}
