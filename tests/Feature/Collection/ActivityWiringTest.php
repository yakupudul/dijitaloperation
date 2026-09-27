<?php

namespace Tests\Feature\Collection;

use App\Contracts\Collection\ActivityTierReader;
use App\Models\CoreAssetBinding;
use App\Models\ResourceActivity;
use App\Models\User;
use App\Services\CommandCenter\Activity\ActivityTierServiceReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Activity tiers reach the Command Center (suppression, pause) through the ActivityTierReader binding. */
final class ActivityWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_real_tier_service_is_bound_and_pausing_an_asset_pauses_its_ad_account(): void
    {
        $this->assertInstanceOf(ActivityTierServiceReader::class, app(ActivityTierReader::class));

        $binding = CoreAssetBinding::factory()->create(['capability' => 'google_ads', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        ResourceActivity::query()->create([
            'external_resource_id' => $binding->external_resource_id, 'provider' => 'GOOGLE_ADS', 'tier' => 'active',
            'last_active_on' => now()->subDay()->toDateString(), 'tier_since' => now()->subMonth(),
        ]);
        $user = User::factory()->create();

        $this->assertTrue(app(ActivityTierReader::class)->pause((int) $binding->digital_asset_id, $user));
        $state = app(ActivityTierReader::class)->forAsset((int) $binding->digital_asset_id);
        $this->assertTrue($state['operator_paused']);
    }
}
