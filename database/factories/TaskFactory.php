<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Recommendation;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recommendation_id' => Recommendation::factory(),
            'client_request_id' => null,
            'source_kind' => 'recommendation',
            'idempotency_key' => null,
            'digital_asset_id' => function (array $attributes): int {
                if (isset($attributes['recommendation_id'])) {
                    $digitalAssetId = Recommendation::query()
                        ->find($attributes['recommendation_id'])
                        ?->digital_asset_id;

                    if ($digitalAssetId !== null) {
                        return $digitalAssetId;
                    }
                }

                if (isset($attributes['brand_id'])) {
                    return DigitalAsset::factory()->create([
                        'brand_id' => $attributes['brand_id'],
                    ])->id;
                }

                return DigitalAsset::factory()->create()->id;
            },
            'scope_kind' => 'digital_asset',
            'brand_id' => function (array $attributes): int {
                if (isset($attributes['digital_asset_id'])) {
                    $brandId = DigitalAsset::query()->find($attributes['digital_asset_id'])?->brand_id;

                    if ($brandId !== null) {
                        return $brandId;
                    }
                }

                if (isset($attributes['customer_id'])) {
                    return Brand::factory()->create([
                        'customer_id' => $attributes['customer_id'],
                    ])->id;
                }

                return Brand::factory()->create()->id;
            },
            'customer_id' => function (array $attributes): int {
                if (isset($attributes['brand_id'])) {
                    $customerId = Brand::query()->find($attributes['brand_id'])?->customer_id;

                    if ($customerId !== null) {
                        return $customerId;
                    }
                }

                return Customer::factory()->create()->id;
            },
            'title' => fake()->sentence(6),
            'action' => fake()->paragraph(),
            'rationale' => fake()->optional()->paragraph(),
            'priority' => fake()->randomElement(['critical', 'high', 'medium', 'low']),
            'snapshot_json' => null,
            'assignee_id' => null,
            'due_date' => null,
            'status' => fake()->randomElement([
                'open',
                'in_progress',
                'blocked',
                'completed',
                'cancelled',
            ]),
        ];
    }

    public function assigned(?User $user = null): static
    {
        return $this->state(fn (): array => [
            'assignee_id' => $user?->id ?? User::factory(),
        ]);
    }
}
