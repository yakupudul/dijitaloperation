<?php

namespace Database\Factories\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\CollectionTriggerType;
use App\Models\Collection\CollectionRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CollectionRun>
 */
class CollectionRunFactory extends Factory
{
    protected $model = CollectionRun::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'trigger_type' => CollectionTriggerType::Manual,
            'status' => CollectionRunStatus::Queued,
            'contract_registry_id' => 'MOXDOP_DATA_CONTRACT_REGISTRY',
            'contract_registry_version' => 1,
            'contract_registry_checksum' => hash('sha256', 'fixture'),
            'last_activity_at' => now(),
            'request_context' => [],
            'plan_snapshot' => [],
            'metadata' => [],
        ];
    }
}
