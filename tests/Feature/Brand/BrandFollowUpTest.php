<?php

namespace Tests\Feature\Brand;

use App\Enums\CustomerStatus;
use App\Jobs\Ads\RefreshAdServiceStatsJob;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ResourceAutomation;
use App\Services\Brand\BrandFollowUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A brand's numbers follow its collection, not the clock (yakup, 2026-10-10): rebuilt once every account finished and
 * one brought new data; never while an account is still collecting, never twice for the same data.
 */
final class BrandFollowUpTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_brand_is_followed_up_once_after_all_its_accounts_finished(): void
    {
        Queue::fake();
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        $ads = $this->account($brand, 'google_ads', ['collection_status' => 'current', 'last_collection_success_at' => now()->subMinutes(30)]);
        $gsc = $this->account($brand, 'website', ['collection_status' => 'collecting', 'last_collection_success_at' => now()->subDay()], 'search_console');
        $followUp = app(BrandFollowUp::class);

        $this->assertSame('busy', $followUp->state((int) $brand->id), 'one account is still collecting');
        $this->assertSame(['brands' => 1, 'followed' => 0, 'busy' => 1], $followUp->run());
        Queue::assertNothingPushed();

        $gsc->update(['collection_status' => 'current', 'last_collection_success_at' => now()->subMinutes(12)]);
        $this->assertSame(['brands' => 1, 'followed' => 1, 'busy' => 0], $followUp->run());
        Queue::assertPushed(RefreshAdServiceStatsJob::class, 2);

        $this->assertSame('done', $followUp->state((int) $brand->id), 'nothing new since');
        $this->travel(5)->minutes();
        $ads->update(['last_collection_success_at' => now()]);
        $this->assertSame('done', $followUp->state((int) $brand->id), 'a just-finished collection settles first');
        $this->travel(11)->minutes();
        $this->assertSame('due', $followUp->state((int) $brand->id));
    }

    /** @param  array<string, mixed>  $automation */
    private function account(Brand $brand, string $assetType, array $automation, ?string $resourceType = null): ResourceAutomation
    {
        $resourceType ??= $assetType;
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => $assetType]);
        $resource = CoreExternalResource::factory()->create(['provider' => 'google', 'resource_type' => $resourceType, 'external_id' => $resourceType.'-'.$asset->id, 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => $resourceType, 'status' => CoreAssetBinding::STATUS_ACTIVE]);

        return ResourceAutomation::query()->create($automation + ['external_resource_id' => $resource->id, 'collection_enabled' => true, 'interval_days' => 1]);
    }
}
