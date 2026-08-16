<?php

namespace App\Services\BusinessOutcomes;

use App\Enums\BusinessOutcomeDefinitionStatus;
use App\Enums\BusinessOutcomeKind;
use App\Models\Brand;
use App\Models\BrandGoal;
use App\Models\BusinessOutcomeDefinition;
use App\Models\User;
use App\Support\BusinessOutcomes\BusinessOutcomeKindRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Canonical Business Outcome Definition write boundary.
 */
final class BusinessOutcomeDefinitionService
{
    public function __construct(
        private readonly BusinessOutcomeKindRegistry $kinds,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(Brand $brand, array $input, ?User $actor = null): BusinessOutcomeDefinition
    {
        $kind = BusinessOutcomeKind::tryFrom((string) ($input['kind'] ?? ''));
        if ($kind === null || ! $this->kinds->has($kind->value)) {
            throw ValidationException::withMessages(['kind' => 'UNKNOWN_OUTCOME_KIND']);
        }

        $unit = $kind->unit();
        if (isset($input['unit']) && (string) $input['unit'] !== $unit->value) {
            throw ValidationException::withMessages(['unit' => 'UNIT_DERIVED_FROM_KIND']);
        }

        $currency = isset($input['currency_code']) ? strtoupper(trim((string) $input['currency_code'])) : null;
        if ($kind->requiresCurrency()) {
            if ($currency === null || $currency === '' || strlen($currency) !== 3) {
                throw ValidationException::withMessages(['currency_code' => 'REVENUE_CURRENCY_REQUIRED']);
            }
        } elseif ($currency !== null && $currency !== '') {
            throw ValidationException::withMessages(['currency_code' => 'COUNT_MUST_NOT_HAVE_CURRENCY']);
        } else {
            $currency = null;
        }

        $code = trim((string) ($input['code'] ?? $kind->defaultCode()));
        if ($code === '') {
            throw ValidationException::withMessages(['code' => 'CODE_REQUIRED']);
        }

        $semantic = trim((string) ($input['semantic_definition'] ?? ''));
        if ($semantic === '') {
            throw ValidationException::withMessages(['semantic_definition' => 'SEMANTIC_DEFINITION_REQUIRED']);
        }

        $label = trim((string) ($input['display_label'] ?? $kind->defaultLabel()));
        if ($label === '') {
            $label = $kind->defaultLabel();
        }

        $goalId = isset($input['brand_goal_id']) ? (int) $input['brand_goal_id'] : null;
        if ($goalId !== null) {
            $goal = BrandGoal::query()->find($goalId);
            if ($goal === null || (int) $goal->brand_id !== (int) $brand->id) {
                throw ValidationException::withMessages(['brand_goal_id' => 'GOAL_NOT_IN_BRAND']);
            }
        }

        return DB::transaction(function () use ($brand, $kind, $unit, $code, $label, $semantic, $currency, $goalId, $actor, $input): BusinessOutcomeDefinition {
            $existingActive = BusinessOutcomeDefinition::query()
                ->where('brand_id', $brand->id)
                ->where('kind', $kind->value)
                ->where('status', BusinessOutcomeDefinitionStatus::Active)
                ->lockForUpdate()
                ->exists();

            if ($existingActive) {
                throw ValidationException::withMessages(['kind' => 'DUPLICATE_ACTIVE_KIND']);
            }

            return BusinessOutcomeDefinition::query()->create([
                'customer_id' => $brand->customer_id,
                'brand_id' => $brand->id,
                'kind' => $kind,
                'unit' => $unit,
                'code' => $code,
                'display_label' => $label,
                'semantic_definition' => $semantic,
                'reporting_timezone' => isset($input['reporting_timezone'])
                    ? (string) $input['reporting_timezone']
                    : null,
                'currency_code' => $currency,
                'status' => BusinessOutcomeDefinitionStatus::Active,
                'definition_version' => 1,
                'brand_goal_id' => $goalId,
                'created_by' => $actor?->id,
            ]);
        });
    }

    /**
     * Create the four standard definition templates for a Brand.
     * Does not invent Brand-specific semantics beyond placeholder confirmation text.
     *
     * @return list<BusinessOutcomeDefinition>
     */
    public function createStandardDefinitionsForBrand(Brand $brand, ?User $actor = null, string $revenueCurrency = 'EUR'): array
    {
        $created = [];
        foreach (BusinessOutcomeKind::cases() as $kind) {
            $exists = BusinessOutcomeDefinition::query()
                ->where('brand_id', $brand->id)
                ->where('kind', $kind->value)
                ->where('status', BusinessOutcomeDefinitionStatus::Active)
                ->exists();
            if ($exists) {
                continue;
            }

            $created[] = $this->create($brand, [
                'kind' => $kind->value,
                'code' => $kind->defaultCode(),
                'display_label' => $kind->defaultLabel(),
                'semantic_definition' => 'Operator-confirmed Brand semantics pending refinement for '.$kind->defaultLabel().'.',
                'currency_code' => $kind->requiresCurrency() ? $revenueCurrency : null,
                'reporting_timezone' => 'UTC',
            ], $actor);
        }

        return $created;
    }

    public function archive(BusinessOutcomeDefinition $definition): BusinessOutcomeDefinition
    {
        $definition->forceFill([
            'status' => BusinessOutcomeDefinitionStatus::Archived,
        ])->save();

        return $definition->refresh();
    }

    /**
     * Material semantic change → new definition version identity (new row) after archiving.
     *
     * @param  array<string, mixed>  $input
     */
    public function reviseSemantics(BusinessOutcomeDefinition $definition, array $input, ?User $actor = null): BusinessOutcomeDefinition
    {
        return DB::transaction(function () use ($definition, $input, $actor): BusinessOutcomeDefinition {
            $locked = BusinessOutcomeDefinition::query()->lockForUpdate()->findOrFail($definition->id);
            $this->archive($locked);

            $payload = [
                'kind' => $locked->kind->value,
                'code' => (string) ($input['code'] ?? $locked->code.'_v'.($locked->definition_version + 1)),
                'display_label' => (string) ($input['display_label'] ?? $locked->display_label),
                'semantic_definition' => (string) ($input['semantic_definition'] ?? $locked->semantic_definition),
                'currency_code' => $input['currency_code'] ?? $locked->currency_code,
                'reporting_timezone' => $input['reporting_timezone'] ?? $locked->reporting_timezone,
                'brand_goal_id' => $input['brand_goal_id'] ?? $locked->brand_goal_id,
            ];

            $created = $this->create($locked->brand, $payload, $actor);
            $created->forceFill([
                'definition_version' => $locked->definition_version + 1,
            ])->save();

            return $created->refresh();
        });
    }
}
