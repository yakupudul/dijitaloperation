<?php

namespace Tests\Feature\Ads;

use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\ResourceActivity;
use App\Models\Suggestion;
use App\Services\Ads\DormantAdWork;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Kullanılmayan reklam hesapları (yakup 2026-10-20): no spend for 60 days, no open work; spend again, work again. */
final class DormantAdWorkTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $lastActive, string $tier = 'dormant'): DigitalAsset
    {
        $asset = DigitalAsset::factory()->create(['type' => 'google_ads']);
        $binding = CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'capability' => 'google_ads', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        ResourceActivity::query()->create(['external_resource_id' => $binding->external_resource_id, 'provider' => 'GOOGLE_ADS', 'tier' => $tier,
            'last_active_on' => $lastActive, 'tier_since' => now()->subMonth()]);

        return $asset;
    }

    private function work(DigitalAsset $asset, string $status = Suggestion::OPEN): Suggestion
    {
        return Suggestion::query()->create([
            'brand_id' => $asset->brand_id, 'channel' => 'google_ads', 'target_type' => 'google_ads', 'target_id' => $asset->id, 'decision_key' => 'ads.negative',
            'fingerprint' => hash('sha256', $asset->id.$status.random_int(1, PHP_INT_MAX)), 'material_hash' => 'x', 'title' => 'Negatif çakışması', 'reason' => 'x',
            'priority' => 1, 'action_type' => 'ads_negative', 'status' => $status, 'action' => [], 'evidence' => [],
        ]);
    }

    public function test_work_of_an_account_silent_for_sixty_days_goes_quiet_and_opens_again_when_it_spends(): void
    {
        $dead = $this->account(now()->subDays(200)->toDateString());
        $recentlyIdle = $this->account(now()->subDays(40)->toDateString());
        $running = $this->account(now()->subDay()->toDateString(), 'active');
        $deadWork = $this->work($dead);
        $operatorSnoozed = $this->work($dead, Suggestion::SNOOZED);
        $idleWork = $this->work($recentlyIdle);
        $runningWork = $this->work($running);

        $this->artisan('moxdop:ads:quiet-dormant')->assertSuccessful();

        $this->assertSame(Suggestion::SNOOZED, $deadWork->fresh()->status);
        $this->assertNotNull($deadWork->fresh()->action[DormantAdWork::MARKER] ?? null);
        $this->assertFalse(Suggestion::query()->actionable()->whereKey($deadWork->id)->exists(), 'off the desk and the brand file');
        $this->assertArrayNotHasKey(DormantAdWork::MARKER, (array) $operatorSnoozed->fresh()->action, 'an operator snooze is left alone');
        $this->assertSame(Suggestion::OPEN, $idleWork->fresh()->status, 'under 60 days: still work');
        $this->assertSame(Suggestion::OPEN, $runningWork->fresh()->status);

        $this->travel(2)->days();
        app(DormantAdWork::class)->sync();
        $this->assertTrue($deadWork->fresh()->snoozed_until->isFuture(), 'renewed every night while the account stays silent');

        ResourceActivity::query()->whereIn('external_resource_id', CoreAssetBinding::query()->where('digital_asset_id', $dead->id)->pluck('external_resource_id'))
            ->update(['tier' => 'active', 'last_active_on' => now()->toDateString()]);
        $this->assertSame(['quiet' => 0, 'reopened' => 1], app(DormantAdWork::class)->sync());
        $this->assertSame(Suggestion::OPEN, $deadWork->fresh()->status);
        $this->assertArrayNotHasKey(DormantAdWork::MARKER, (array) $deadWork->fresh()->action);
    }
}
