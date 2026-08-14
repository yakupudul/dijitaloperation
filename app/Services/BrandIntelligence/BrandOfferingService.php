<?php

namespace App\Services\BrandIntelligence;

use App\Enums\OfferingNameKind;
use App\Enums\OfferingNameProvenance;
use App\Enums\OfferingStatus;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\BrandOfferingName;
use App\Models\User;
use App\Support\BrandIntelligence\IdentityLabelNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Canonical write path for Brand Offerings and name claims.
 *
 * Offering ≠ MoxDOP Service / WebsitePage / Campaign / Keyword.
 * Does not create Evidence, Findings, Opportunities, Recommendations, Tasks, or AI calls.
 */
final class BrandOfferingService
{
    public function __construct(
        private readonly IdentityLabelNormalizer $normalizer,
        private readonly BrandContextActivityRecorder $activity,
    ) {}

    /**
     * Resolve existing Offering by primary/alias claim, or create a new one.
     *
     * @return array{offering: BrandOffering, created: bool}
     */
    public function resolveOrCreate(
        Brand $brand,
        string $label,
        ?string $locale = null,
        OfferingNameProvenance $provenance = OfferingNameProvenance::PrimaryOperator,
        ?User $actor = null,
        bool $recordActivity = true,
    ): array {
        $label = trim($label);
        if ($label === '') {
            throw ValidationException::withMessages(['label' => 'Offering label is required.']);
        }

        $normalized = $this->normalizer->normalize($label);
        if ($normalized === '') {
            throw ValidationException::withMessages(['label' => 'Offering label is empty after normalization.']);
        }

        $existingName = BrandOfferingName::query()
            ->where('brand_id', $brand->id)
            ->where('normalized_key', $normalized)
            ->first();

        if ($existingName instanceof BrandOfferingName) {
            $offering = BrandOffering::query()->findOrFail($existingName->brand_offering_id);

            return ['offering' => $offering, 'created' => false];
        }

        try {
            $offering = DB::transaction(function () use (
                $brand,
                $label,
                $normalized,
                $locale,
                $provenance,
                $actor,
                $recordActivity,
            ): BrandOffering {
                $offering = BrandOffering::query()->create([
                    'brand_id' => $brand->id,
                    'status' => OfferingStatus::Active,
                    'priority_rank' => null,
                ]);

                BrandOfferingName::query()->create([
                    'brand_id' => $brand->id,
                    'brand_offering_id' => $offering->id,
                    'raw_label' => $label,
                    'normalized_key' => $normalized,
                    'locale' => $locale,
                    'name_kind' => OfferingNameKind::Primary,
                    'is_primary' => true,
                    'is_active' => true,
                    'provenance' => $provenance,
                    'normalization_version' => $this->normalizer->version(),
                ]);

                if ($recordActivity) {
                    $this->activity->record(
                        $brand,
                        'OFFERING_CREATED',
                        BrandOffering::class,
                        $offering->id,
                        ['label' => $label],
                        $actor,
                    );
                }

                return $offering;
            });

            return ['offering' => $offering, 'created' => true];
        } catch (UniqueConstraintViolationException) {
            $existingName = BrandOfferingName::query()
                ->where('brand_id', $brand->id)
                ->where('normalized_key', $normalized)
                ->first();

            if ($existingName instanceof BrandOfferingName) {
                return [
                    'offering' => BrandOffering::query()->findOrFail($existingName->brand_offering_id),
                    'created' => false,
                ];
            }

            throw ValidationException::withMessages(['label' => 'Offering name claim conflict.']);
        }
    }

    public function create(Brand $brand, string $label, ?string $locale = null, ?User $actor = null): BrandOffering
    {
        $result = $this->resolveOrCreate($brand, $label, $locale, OfferingNameProvenance::PrimaryOperator, $actor);

        if (! $result['created']) {
            throw ValidationException::withMessages([
                'label' => 'OFFERING_ALREADY_EXISTS',
            ]);
        }

        return $result['offering'];
    }

    public function findByLabel(Brand $brand, string $label): ?BrandOffering
    {
        $normalized = $this->normalizer->normalize(trim($label));
        if ($normalized === '') {
            return null;
        }

        $name = BrandOfferingName::query()
            ->where('brand_id', $brand->id)
            ->where('normalized_key', $normalized)
            ->first();

        return $name instanceof BrandOfferingName
            ? BrandOffering::query()->find($name->brand_offering_id)
            : null;
    }

    public function rename(BrandOffering $offering, string $newLabel, ?User $actor = null): BrandOffering
    {
        $offering = BrandOffering::query()->with(['primaryName', 'brand'])->findOrFail($offering->id);
        $newLabel = trim($newLabel);
        if ($newLabel === '') {
            throw ValidationException::withMessages(['label' => 'Offering label is required.']);
        }

        $normalized = $this->normalizer->normalize($newLabel);
        $primary = $offering->primaryName;
        if (! $primary instanceof BrandOfferingName) {
            throw ValidationException::withMessages(['label' => 'Offering has no primary name.']);
        }

        if ($primary->normalized_key === $normalized) {
            $primary->raw_label = $newLabel;
            $primary->save();

            return $offering->fresh(['primaryName', 'names']) ?? $offering;
        }

        $claim = BrandOfferingName::query()
            ->where('brand_id', $offering->brand_id)
            ->where('normalized_key', $normalized)
            ->first();

        if ($claim instanceof BrandOfferingName && (int) $claim->brand_offering_id !== (int) $offering->id) {
            throw ValidationException::withMessages(['label' => 'Name is already claimed by another Offering.']);
        }

        if ($claim instanceof BrandOfferingName && (int) $claim->brand_offering_id === (int) $offering->id) {
            // Promote existing alias/former name to primary; demote current primary.
            return DB::transaction(function () use ($offering, $primary, $claim, $newLabel, $actor): BrandOffering {
                $oldLabel = $primary->raw_label;
                $primary->is_primary = false;
                $primary->name_kind = OfferingNameKind::FormerPrimary;
                $primary->provenance = OfferingNameProvenance::FormerPrimary;
                $primary->save();

                $claim->raw_label = $newLabel;
                $claim->is_primary = true;
                $claim->is_active = true;
                $claim->name_kind = OfferingNameKind::Primary;
                $claim->provenance = OfferingNameProvenance::PrimaryOperator;
                $claim->save();

                $this->activity->record(
                    $offering->brand,
                    'OFFERING_RENAMED',
                    BrandOffering::class,
                    $offering->id,
                    ['from' => $oldLabel, 'to' => $newLabel],
                    $actor,
                );

                return $offering->fresh(['primaryName', 'names']) ?? $offering;
            });
        }

        return DB::transaction(function () use ($offering, $primary, $newLabel, $normalized, $actor): BrandOffering {
            $oldLabel = $primary->raw_label;
            $primary->is_primary = false;
            $primary->name_kind = OfferingNameKind::FormerPrimary;
            $primary->provenance = OfferingNameProvenance::FormerPrimary;
            $primary->save();

            BrandOfferingName::query()->create([
                'brand_id' => $offering->brand_id,
                'brand_offering_id' => $offering->id,
                'raw_label' => $newLabel,
                'normalized_key' => $normalized,
                'locale' => null,
                'name_kind' => OfferingNameKind::Primary,
                'is_primary' => true,
                'is_active' => true,
                'provenance' => OfferingNameProvenance::PrimaryOperator,
                'normalization_version' => $this->normalizer->version(),
            ]);

            $this->activity->record(
                $offering->brand,
                'OFFERING_RENAMED',
                BrandOffering::class,
                $offering->id,
                ['from' => $oldLabel, 'to' => $newLabel],
                $actor,
            );

            return $offering->fresh(['primaryName', 'names']) ?? $offering;
        });
    }

    public function addAlias(
        BrandOffering $offering,
        string $alias,
        ?string $locale = null,
        ?User $actor = null,
    ): BrandOfferingName {
        $offering = BrandOffering::query()->with('brand')->findOrFail($offering->id);
        $alias = trim($alias);
        if ($alias === '') {
            throw ValidationException::withMessages(['alias' => 'Alias is required.']);
        }

        $normalized = $this->normalizer->normalize($alias);

        $claim = BrandOfferingName::query()
            ->where('brand_id', $offering->brand_id)
            ->where('normalized_key', $normalized)
            ->first();

        if ($claim instanceof BrandOfferingName) {
            if ((int) $claim->brand_offering_id === (int) $offering->id) {
                if (! $claim->is_active) {
                    $claim->is_active = true;
                    $claim->raw_label = $alias;
                    $claim->save();
                }

                return $claim;
            }

            throw ValidationException::withMessages(['alias' => 'Alias is already claimed by another Offering.']);
        }

        try {
            $name = BrandOfferingName::query()->create([
                'brand_id' => $offering->brand_id,
                'brand_offering_id' => $offering->id,
                'raw_label' => $alias,
                'normalized_key' => $normalized,
                'locale' => $locale,
                'name_kind' => OfferingNameKind::Alias,
                'is_primary' => false,
                'is_active' => true,
                'provenance' => OfferingNameProvenance::ConfirmedAlias,
                'normalization_version' => $this->normalizer->version(),
            ]);

            $this->activity->record(
                $offering->brand,
                'OFFERING_ALIAS_ADDED',
                BrandOffering::class,
                $offering->id,
                ['alias' => $alias],
                $actor,
            );

            return $name;
        } catch (UniqueConstraintViolationException) {
            $claim = BrandOfferingName::query()
                ->where('brand_id', $offering->brand_id)
                ->where('normalized_key', $normalized)
                ->first();

            if ($claim instanceof BrandOfferingName && (int) $claim->brand_offering_id === (int) $offering->id) {
                return $claim;
            }

            throw ValidationException::withMessages(['alias' => 'Alias is already claimed by another Offering.']);
        }
    }

    public function deactivateAlias(BrandOfferingName $name, ?User $actor = null): BrandOfferingName
    {
        $name = BrandOfferingName::query()->with(['offering.brand'])->findOrFail($name->id);

        if ($name->is_primary) {
            throw ValidationException::withMessages(['alias' => 'Cannot deactivate the primary name.']);
        }

        $name->is_active = false;
        $name->save();

        $this->activity->record(
            $name->offering->brand,
            'OFFERING_ALIAS_REMOVED',
            BrandOffering::class,
            $name->brand_offering_id,
            ['alias' => $name->raw_label, 'name_id' => $name->id],
            $actor,
        );

        return $name;
    }

    /**
     * @param  list<int>  $orderedOfferingIds  Priority order (1-based ranks assigned in sequence). Empty clears priorities.
     */
    public function setPriorityOrder(Brand $brand, array $orderedOfferingIds, ?User $actor = null, bool $recordActivity = true): void
    {
        DB::transaction(function () use ($brand, $orderedOfferingIds, $actor, $recordActivity): void {
            $ids = array_values(array_unique(array_map('intval', $orderedOfferingIds)));

            if ($ids !== []) {
                $count = BrandOffering::query()
                    ->where('brand_id', $brand->id)
                    ->whereIn('id', $ids)
                    ->count();

                if ($count !== count($ids)) {
                    throw ValidationException::withMessages(['offerings' => 'All Offerings must belong to this Brand.']);
                }

                $archived = BrandOffering::query()
                    ->where('brand_id', $brand->id)
                    ->whereIn('id', $ids)
                    ->where('status', OfferingStatus::Archived)
                    ->exists();

                if ($archived) {
                    throw ValidationException::withMessages(['offerings' => 'Archived Offerings cannot be prioritized.']);
                }
            }

            BrandOffering::query()
                ->where('brand_id', $brand->id)
                ->whereNotNull('priority_rank')
                ->update(['priority_rank' => null]);

            foreach ($ids as $index => $id) {
                BrandOffering::query()
                    ->where('brand_id', $brand->id)
                    ->where('id', $id)
                    ->update(['priority_rank' => $index + 1]);
            }

            if ($recordActivity) {
                $this->activity->record(
                    $brand,
                    'OFFERING_PRIORITY_CHANGED',
                    null,
                    null,
                    ['offering_ids' => $ids],
                    $actor,
                );
            }
        });
    }

    public function archive(BrandOffering $offering, ?User $actor = null): BrandOffering
    {
        $offering = BrandOffering::query()->with('brand')->findOrFail($offering->id);

        return DB::transaction(function () use ($offering, $actor): BrandOffering {
            $offering->status = OfferingStatus::Archived;
            $offering->priority_rank = null;
            $offering->save();

            $this->activity->record(
                $offering->brand,
                'OFFERING_ARCHIVED',
                BrandOffering::class,
                $offering->id,
                [],
                $actor,
            );

            return $offering;
        });
    }

    public function primaryLabel(BrandOffering $offering): ?string
    {
        $offering->loadMissing('primaryName');

        return $offering->primaryName?->raw_label;
    }
}
