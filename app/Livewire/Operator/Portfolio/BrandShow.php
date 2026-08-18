<?php

namespace App\Livewire\Operator\Portfolio;

use App\Livewire\Demo\Portfolio\BrandShow as LegacyBrandShow;
use App\Models\Brand;
use App\Models\BrandIntelligenceContext;
use App\Services\BrandIntelligence\BrandIntelligenceContextWriteService;
use App\Support\Demo\DemoState;

/**
 * Production behavior for the existing Brand workspace.
 *
 * The legacy visual component still expects a session-shaped Business Context payload,
 * so this adapter hydrates that shape from canonical DB truth on mount and writes edits
 * through BrandIntelligenceContextWriteService. Session data is only a view cache here.
 */
class BrandShow extends LegacyBrandShow
{
    public function mount(string $brand): void
    {
        parent::mount($brand);
        $this->syncCanonicalBusinessContextToUiState();
    }

    public function saveBusinessContext(): void
    {
        $brand = Brand::query()->with('intelligenceContext')->findOrFail((int) $this->brand);
        $context = $brand->intelligenceContext;
        $split = static fn (string $value): array => array_values(array_filter(array_map(
            'trim',
            preg_split('/[,\n]+/', $value) ?: [],
        )));

        $priority = $split($this->context_priority_offerings);
        $audiences = $split($this->context_target_audiences);
        $differentiators = $split($this->context_differentiators);
        $businessGoals = $split($this->context_business_goals);
        $conversionGoals = $split($this->context_conversion_goals);

        app(BrandIntelligenceContextWriteService::class)->saveFromForm($brand, [
            'business_summary' => $this->context_business_summary,
            'business_model' => $this->context_business_model,
            'products_services' => is_array($context?->products_services) ? $context->products_services : [],
            'priority_offerings' => $priority,
            'target_audiences' => array_map(fn (string $value): array => ['name' => $value, 'note' => null], $audiences),
            'target_markets' => is_array($context?->target_markets) ? $context->target_markets : [],
            'business_goals' => array_map(fn (string $value): array => ['goal' => $value, 'note' => null], $businessGoals),
            'conversion_goals' => array_map(fn (string $value): array => ['type' => 'custom', 'label' => $value, 'note' => null], $conversionGoals),
            'positioning' => $this->context_positioning,
            'differentiators' => $differentiators,
            'known_competitors' => is_array($context?->known_competitors) ? $context->known_competitors : [],
            'important_constraints' => trim($this->context_constraints),
        ], auth()->user());

        $this->editingContext = false;
        $this->syncCanonicalBusinessContextToUiState();
        DemoState::flash('Business Context canonical Brand verisine kaydedildi.');
    }

    private function syncCanonicalBusinessContextToUiState(): void
    {
        if (! ctype_digit($this->brand)) {
            return;
        }

        $brand = Brand::query()->with('intelligenceContext.updatedByUser')->find((int) $this->brand);
        $context = $brand?->intelligenceContext;
        if (! $context instanceof BrandIntelligenceContext) {
            return;
        }

        $payload = [
            'brand_id' => (string) $brand->id,
            'completed' => $this->completedSections($context),
            'total' => 8,
            'updated_at' => $context->updated_at?->timezone(config('app.timezone'))->format('M j, Y H:i'),
            'updated_by' => $context->updatedByUser?->name,
            'source' => $context->source,
            'business_summary' => $context->business_summary,
            'business_model' => $context->business_model,
            'products_services' => $context->products_services ?? [],
            'priority_offerings' => $this->labels($context->priority_offerings ?? [], ['name', 'label', 'goal']),
            'target_audiences' => $this->labels($context->target_audiences ?? [], ['name', 'label']),
            'target_markets' => $context->target_markets ?? [],
            'business_goals' => $this->labels($context->business_goals ?? [], ['goal', 'label', 'name']),
            'conversion_goals' => $this->labels($context->conversion_goals ?? [], ['label', 'type', 'goal']),
            'positioning' => $context->positioning,
            'differentiators' => $this->labels($context->differentiators ?? [], ['name', 'label']),
            'known_competitors' => $context->known_competitors ?? [],
            'important_constraints' => $this->constraintRows($context->important_constraints),
            'unknown_areas' => [],
        ];

        $state = DemoState::all();
        $store = is_array($state['brand_business_context'] ?? null) ? $state['brand_business_context'] : [];
        $store[(string) $brand->id] = $payload;
        DemoState::put(['brand_business_context' => $store]);
    }

    /** @param mixed $rows @param list<string> $keys @return list<string> */
    private function labels(mixed $rows, array $keys): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $labels = [];
        foreach ($rows as $row) {
            if (is_string($row) && trim($row) !== '') {
                $labels[] = trim($row);

                continue;
            }
            if (! is_array($row)) {
                continue;
            }
            foreach ($keys as $key) {
                if (isset($row[$key]) && is_string($row[$key]) && trim($row[$key]) !== '') {
                    $labels[] = trim($row[$key]);
                    break;
                }
            }
        }

        return array_values(array_unique($labels));
    }

    /** @return list<string> */
    private function constraintRows(mixed $value): array
    {
        if (is_string($value)) {
            return array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', $value) ?: [])));
        }

        return $this->labels($value, ['name', 'label']);
    }

    private function completedSections(BrandIntelligenceContext $context): int
    {
        $values = [
            $context->business_summary,
            $context->business_model,
            $context->priority_offerings,
            $context->target_audiences,
            $context->business_goals,
            $context->conversion_goals,
            $context->positioning,
            $context->important_constraints,
        ];

        return collect($values)->filter(function (mixed $value): bool {
            if (is_array($value)) {
                return $value !== [];
            }

            return is_string($value) ? trim($value) !== '' : $value !== null;
        })->count();
    }
}
