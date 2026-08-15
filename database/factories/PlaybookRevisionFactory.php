<?php

namespace Database\Factories;

use App\Enums\PlaybookApplicabilityMode;
use App\Models\Playbook;
use App\Models\PlaybookRevision;
use App\Models\User;
use App\Support\Playbooks\PlaybookRevisionFingerprint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlaybookRevision>
 */
class PlaybookRevisionFactory extends Factory
{
    protected $model = PlaybookRevision::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $payload = [
            'title' => fake()->sentence(4),
            'summary' => fake()->sentence(),
            'knowledge' => ['purpose' => fake()->sentence()],
            'cadence' => 'weekly',
            'service_applicability_mode' => PlaybookApplicabilityMode::Any->value,
            'asset_applicability_mode' => PlaybookApplicabilityMode::Any->value,
            'execution_scope_mode' => PlaybookApplicabilityMode::Any->value,
            'service_definition_ids' => [],
            'asset_types' => [],
            'execution_scopes' => [],
            'instructions' => [['body' => 'Step one']],
            'references' => [],
        ];

        return [
            'playbook_id' => Playbook::factory(),
            'revision_number' => 1,
            'title' => $payload['title'],
            'summary' => $payload['summary'],
            'knowledge' => $payload['knowledge'],
            'cadence' => $payload['cadence'],
            'service_applicability_mode' => $payload['service_applicability_mode'],
            'asset_applicability_mode' => $payload['asset_applicability_mode'],
            'execution_scope_mode' => $payload['execution_scope_mode'],
            'content_fingerprint' => PlaybookRevisionFingerprint::for($payload),
            'created_by' => User::factory(),
            'idempotency_key' => null,
        ];
    }
}
