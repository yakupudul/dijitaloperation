<?php

namespace Database\Factories\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CollectionResourceRun>
 */
class CollectionResourceRunFactory extends Factory
{
    protected $model = CollectionResourceRun::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'collection_run_id' => CollectionRun::factory(),
            'provider_or_source' => 'GA4',
            'resource_kind' => 'bound_provider_resource',
            'status' => CollectionRunStatus::Queued,
            'last_activity_at' => now(),
            'metadata' => [],
        ];
    }
}
