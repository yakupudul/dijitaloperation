<?php

namespace App\Services\BrandIntelligence;

use App\Enums\GoalKind;
use App\Enums\OfferingNameProvenance;
use App\Models\Brand;
use App\Models\BrandIntelligenceContext;
use App\Support\BrandIntelligence\ConversionGoalTypes;
use App\Support\BrandIntelligence\IdentityLabelNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-way deterministic migration of legacy BIC identity arrays into Goal/Offering entities.
 * Idempotent. Does not migrate Demo fixtures. Does not fuzzy/AI merge.
 */
final class LegacyBicGoalsOfferingsMigrator
{
    /**
     * @var list<array{brand_id: int, reason: string}>
     */
    private array $conflicts = [];

    /**
     * @var array{brands: int, business_goals: int, conversion_goals: int, offerings: int, collapsed: int}
     */
    private array $stats = [
        'brands' => 0,
        'business_goals' => 0,
        'conversion_goals' => 0,
        'offerings' => 0,
        'collapsed' => 0,
    ];

    public function __construct(
        private readonly BrandGoalService $goals,
        private readonly BrandOfferingService $offerings,
        private readonly BrandIntelligenceContextWriteService $write,
        private readonly IdentityLabelNormalizer $normalizer,
        private readonly BrandContextActivityRecorder $activity,
    ) {}

    /**
     * @return array{stats: array<string, int>, conflicts: list<array{brand_id: int, reason: string}>}
     */
    public function migrateAll(): array
    {
        $this->conflicts = [];
        $this->stats = [
            'brands' => 0,
            'business_goals' => 0,
            'conversion_goals' => 0,
            'offerings' => 0,
            'collapsed' => 0,
        ];

        BrandIntelligenceContext::query()
            ->orderBy('id')
            ->chunkById(50, function ($contexts): void {
                foreach ($contexts as $context) {
                    $this->migrateContext($context);
                }
            });

        return ['stats' => $this->stats, 'conflicts' => $this->conflicts];
    }

    public function migrateContext(BrandIntelligenceContext $context): void
    {
        $brand = Brand::query()->find($context->brand_id);
        if (! $brand instanceof Brand) {
            $this->conflicts[] = ['brand_id' => (int) $context->brand_id, 'reason' => 'missing_brand'];

            return;
        }

        try {
            DB::transaction(function () use ($brand, $context): void {
                $businessBefore = $this->extractBusinessLabels($context->business_goals);
                $conversionBefore = $this->extractConversionRows($context->conversion_goals);
                $priorityBefore = $this->extractStringList($context->priority_offerings);

                $businessUnique = $this->collapseStructural($businessBefore);
                $priorityUnique = $this->collapseStructural($priorityBefore);

                $this->stats['collapsed'] += (count($businessBefore) - count($businessUnique))
                    + (count($priorityBefore) - count($priorityUnique));

                $order = 0;
                foreach ($businessUnique as $label) {
                    $order++;
                    $this->goals->create(
                        brand: $brand,
                        kind: GoalKind::Business,
                        label: $label,
                        sortOrder: $order,
                        recordActivity: false,
                    );
                    $this->stats['business_goals']++;
                }

                $order = 0;
                foreach ($conversionBefore as $row) {
                    $order++;
                    $this->goals->create(
                        brand: $brand,
                        kind: GoalKind::Conversion,
                        label: $row['label'],
                        note: $row['note'],
                        conversionType: $row['type'],
                        sortOrder: $order,
                        recordActivity: false,
                    );
                    $this->stats['conversion_goals']++;
                }

                $priorityIds = [];
                foreach ($priorityUnique as $label) {
                    $result = $this->offerings->resolveOrCreate(
                        brand: $brand,
                        label: $label,
                        provenance: OfferingNameProvenance::LegacyBic,
                        recordActivity: false,
                    );
                    $priorityIds[] = $result['offering']->id;
                    if ($result['created']) {
                        $this->stats['offerings']++;
                    }
                }

                if ($priorityIds !== []) {
                    $this->offerings->setPriorityOrder($brand, $priorityIds, recordActivity: false);
                }

                $this->write->projectIdentityFields($brand);

                $this->activity->record(
                    $brand,
                    'BIC_IDENTITY_CONTEXT_MIGRATED',
                    BrandIntelligenceContext::class,
                    $context->id,
                    [
                        'business_in' => count($businessBefore),
                        'business_out' => count($businessUnique),
                        'conversion_in' => count($conversionBefore),
                        'priority_in' => count($priorityBefore),
                        'priority_out' => count($priorityUnique),
                    ],
                );

                $this->stats['brands']++;
            });
        } catch (\Throwable $e) {
            Log::warning('BIC goals/offerings migration conflict', [
                'brand_id' => $brand->id,
                'message' => $e->getMessage(),
            ]);
            $this->conflicts[] = ['brand_id' => $brand->id, 'reason' => $e->getMessage()];
        }
    }

    /**
     * @param  list<string>  $labels
     * @return list<string>
     */
    private function collapseStructural(array $labels): array
    {
        $seen = [];
        $out = [];
        foreach ($labels as $label) {
            $key = $this->normalizer->normalize($label);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $label;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function extractBusinessLabels(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (is_string($row)) {
                $v = trim($row);
                if ($v !== '') {
                    $out[] = $v;
                }

                continue;
            }
            if (is_array($row)) {
                $v = isset($row['goal']) && is_string($row['goal'])
                    ? trim($row['goal'])
                    : (isset($row['name']) && is_string($row['name']) ? trim($row['name']) : '');
                if ($v !== '') {
                    $out[] = $v;
                }
            }
        }

        return $out;
    }

    /**
     * @return list<array{type: string, label: string, note: ?string}>
     */
    private function extractConversionRows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = isset($row['type']) && is_string($row['type']) ? trim($row['type']) : '';
            if ($type === '' || ! array_key_exists($type, ConversionGoalTypes::options())) {
                continue;
            }
            $label = isset($row['label']) && is_string($row['label']) ? trim($row['label']) : '';
            $display = $label !== '' ? $label : ConversionGoalTypes::label($type);
            $note = isset($row['note']) && is_string($row['note']) ? trim($row['note']) : null;
            $key = GoalKind::Conversion->value.'|'.$this->normalizer->normalize($display);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'type' => $type,
                'label' => $display,
                'note' => $note === '' ? null : $note,
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function extractStringList(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (is_string($row)) {
                $v = trim($row);
                if ($v !== '') {
                    $out[] = $v;
                }

                continue;
            }
            if (is_array($row) && isset($row['name']) && is_string($row['name'])) {
                $v = trim($row['name']);
                if ($v !== '') {
                    $out[] = $v;
                }
            }
        }

        return $out;
    }
}
