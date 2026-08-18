<?php

namespace Database\Factories;

use App\Enums\ClientRequestChannel;
use App\Enums\ClientRequestScopeState;
use App\Enums\ClientRequestStatus;
use App\Models\Brand;
use App\Models\ClientRequest;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientRequest>
 */
class ClientRequestFactory extends Factory
{
    protected $model = ClientRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'brand_id' => function (array $attributes): int {
                if (isset($attributes['customer_id'])) {
                    return Brand::factory()->create([
                        'customer_id' => $attributes['customer_id'],
                    ])->id;
                }

                return Brand::factory()->create()->id;
            },
            'digital_asset_id' => null,
            'service_definition_id' => null,
            'customer_contact_id' => null,
            'owner_user_id' => null,
            'created_by_user_id' => User::factory(),
            'title' => fake()->sentence(6),
            'description' => fake()->optional()->paragraph(),
            'status' => ClientRequestStatus::New,
            'channel' => ClientRequestChannel::Meeting,
            'priority' => 'medium',
            'effort' => null,
            'due_label' => null,
            'due_date' => null,
            'intake_scope_state' => ClientRequestScopeState::Unclassified,
            'intake_scope_snapshot' => null,
            'intake_scope_assessed_at' => now(),
            'idempotency_key' => null,
            'closed_at' => null,
        ];
    }

    public function forBrand(Brand $brand): static
    {
        return $this->state(fn (): array => [
            'customer_id' => $brand->customer_id,
            'brand_id' => $brand->id,
        ]);
    }
}
