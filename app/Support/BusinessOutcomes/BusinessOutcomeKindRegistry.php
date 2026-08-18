<?php

namespace App\Support\BusinessOutcomes;

use App\Enums\BusinessOutcomeKind;
use App\Enums\BusinessOutcomeUnit;

/**
 * Bounded Business Outcome kind registry (Prompt 57).
 * No generic arbitrary metric engine.
 */
final class BusinessOutcomeKindRegistry
{
    public const string VERSION = 'business_outcome_kind_registry_v1';

    /**
     * @return list<BusinessOutcomeKind>
     */
    public function all(): array
    {
        return BusinessOutcomeKind::cases();
    }

    public function has(string $kind): bool
    {
        return BusinessOutcomeKind::tryFrom($kind) !== null;
    }

    public function unitFor(BusinessOutcomeKind $kind): BusinessOutcomeUnit
    {
        return $kind->unit();
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $kinds = [];
        foreach ($this->all() as $kind) {
            $kinds[$kind->value] = [
                'kind' => $kind->value,
                'unit' => $kind->unit()->value,
                'default_label' => $kind->defaultLabel(),
                'currency_required' => $kind->requiresCurrency(),
                'integer' => $kind->unit() === BusinessOutcomeUnit::Count,
                'negative_allowed' => false,
                'provider_auto_mapping' => false,
            ];
        }

        return [
            'version' => self::VERSION,
            'kinds' => $kinds,
            'crm' => false,
            'provider_auto_mapping' => false,
            'arbitrary_metric_engine' => false,
        ];
    }
}
