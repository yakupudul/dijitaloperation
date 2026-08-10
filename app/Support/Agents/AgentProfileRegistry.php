<?php

namespace App\Support\Agents;

use InvalidArgumentException;

/**
 * Registry for code-defined built-in Agent Profiles.
 * Modules register profiles in their service providers.
 */
final class AgentProfileRegistry
{
    /**
     * @var array<string, AgentProfileDefinition>
     */
    private array $profiles = [];

    public function register(AgentProfileDefinition $profile): void
    {
        if ($profile->slug === '' || ! preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $profile->slug)) {
            throw new InvalidArgumentException('Agent Profile slug is invalid.');
        }

        if (isset($this->profiles[$profile->slug])) {
            throw new InvalidArgumentException("Duplicate Agent Profile slug [{$profile->slug}].");
        }

        $this->profiles[$profile->slug] = $profile;
    }

    public function get(string $slug): AgentProfileDefinition
    {
        if (! isset($this->profiles[$slug])) {
            throw new InvalidArgumentException("Unknown Agent Profile [{$slug}].");
        }

        return $this->profiles[$slug];
    }

    public function has(string $slug): bool
    {
        return isset($this->profiles[$slug]);
    }

    /**
     * @return list<AgentProfileDefinition>
     */
    public function all(): array
    {
        $profiles = array_values($this->profiles);
        usort(
            $profiles,
            fn (AgentProfileDefinition $a, AgentProfileDefinition $b): int => [$a->module, $a->name] <=> [$b->module, $b->name]
        );

        return $profiles;
    }

    /**
     * @return list<AgentProfileDefinition>
     */
    public function forModule(string $module): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (AgentProfileDefinition $profile): bool => $profile->module === $module
        ));
    }
}
