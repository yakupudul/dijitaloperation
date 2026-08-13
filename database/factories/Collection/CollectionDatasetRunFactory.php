<?php

namespace Database\Factories\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\ProgressMode;
use App\Enums\Collection\RequirementLevel;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CollectionDatasetRun>
 */
class CollectionDatasetRunFactory extends Factory
{
    protected $model = CollectionDatasetRun::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'collection_run_id' => CollectionRun::factory(),
            'collection_resource_run_id' => CollectionResourceRun::factory(),
            'provider_or_source' => 'GA4',
            'dataset_contract_id' => 'ga4_property_metadata',
            'request_family_id' => 'GA4_RF_PROPERTY_METADATA',
            'requirement_level' => RequirementLevel::Required,
            'contract_registry_version' => 1,
            'status' => CollectionRunStatus::Queued,
            'attempt_count' => 0,
            'max_attempts' => 3,
            'progress_mode' => ProgressMode::Indeterminate,
            'last_activity_at' => now(),
            'metadata' => [],
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (CollectionDatasetRun $run): void {
            if ($run->collection_run_id && ! $run->collection_resource_run_id) {
                return;
            }
        })->afterCreating(function (CollectionDatasetRun $run): void {
            // Ensure parent FKs align when nested factories create separate parents.
            if ($run->resourceRun && (int) $run->collection_run_id !== (int) $run->resourceRun->collection_run_id) {
                $run->forceFill([
                    'collection_run_id' => $run->resourceRun->collection_run_id,
                ])->saveQuietly();
            }
        });
    }
}
