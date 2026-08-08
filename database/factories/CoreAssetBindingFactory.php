<?php

namespace Database\Factories;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CoreAssetBinding>
 */
class CoreAssetBindingFactory extends Factory
{
    protected $model = CoreAssetBinding::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'digital_asset_id' => DigitalAsset::factory(),
            'external_resource_id' => CoreExternalResource::factory(),
            'capability' => 'ga4',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
            'configuration' => [],
        ];
    }
}
