<?php

namespace App\Services\Opportunities;

use App\Support\Opportunities\OpportunityRule;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Loads MOXDOP_OPPORTUNITY_RULES_V1. Trusted application config — never request-authored.
 */
final class OpportunityRuleRegistry
{
    public const string VERSION = 'v1';

    /** @var array<string, mixed>|null */
    private ?array $registry = null;

    /** @var list<OpportunityRule>|null */
    private ?array $rules = null;

    public function __construct(
        private readonly OpportunityRuleValidator $validator,
        private readonly ?string $path = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function registry(): array
    {
        $this->ensureLoaded();

        return $this->registry;
    }

    public function version(): int
    {
        return (int) ($this->registry()['metadata']['version'] ?? 0);
    }

    public function registryId(): string
    {
        return (string) ($this->registry()['metadata']['registry_id'] ?? '');
    }

    /**
     * @return list<OpportunityRule>
     */
    public function all(): array
    {
        $this->ensureLoaded();

        return $this->rules;
    }

    /**
     * @return list<OpportunityRule>
     */
    public function enabled(): array
    {
        return array_values(array_filter($this->all(), static fn (OpportunityRule $rule): bool => $rule->enabled));
    }

    public function byStableId(string $stableId): ?OpportunityRule
    {
        foreach ($this->all() as $rule) {
            if ($rule->stableId === $stableId) {
                return $rule;
            }
        }

        return null;
    }

    public function get(string $id): OpportunityRule
    {
        foreach ($this->all() as $rule) {
            if ($rule->id === $id) {
                return $rule;
            }
        }

        throw new RuntimeException("Unknown Opportunity rule [{$id}].");
    }

    /**
     * @return list<OpportunityRule>
     */
    public function forEvidenceDefinition(string $definitionId): array
    {
        return array_values(array_filter(
            $this->enabled(),
            static fn (OpportunityRule $rule): bool => in_array($definitionId, $rule->evidenceDefinitionIds, true),
        ));
    }

    /**
     * @return list<OpportunityRule>
     */
    public function forFindingRuleStableId(string $findingStableId): array
    {
        return array_values(array_filter(
            $this->enabled(),
            static fn (OpportunityRule $rule): bool => in_array($findingStableId, $rule->findingRuleStableIds, true),
        ));
    }

    public function validate(): void
    {
        $this->ensureLoaded();
    }

    private function ensureLoaded(): void
    {
        if ($this->registry !== null && $this->rules !== null) {
            return;
        }

        $path = $this->path ?? (string) config('moxdop-opportunity-rules.opportunity_rule_registry_path');
        if ($path === '' || ! File::exists($path)) {
            throw new RuntimeException("Opportunity rule registry not found at [{$path}].");
        }

        $decoded = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Opportunity rule registry must decode to an object.');
        }

        $this->validator->validate($decoded);

        $rules = [];
        foreach ($decoded['rules'] ?? [] as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $rules[] = OpportunityRule::fromArray($raw);
        }

        $this->registry = $decoded;
        $this->rules = $rules;
    }
}
