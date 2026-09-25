<?php

namespace Tests\Feature\Brain;

use App\Enums\DigitalAssetStatus;
use App\Livewire\Operator\Advisor\AdvisorPanel;
use App\Models\AdvisorItem;
use App\Models\AdvisorPlan;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Brain phase 1: advisor findings can be closed, snoozed or skipped in bulk instead of one by one. */
final class BulkReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_advisor_items_are_handled_in_bulk(): void
    {
        Queue::fake();
        $this->seed(RoleAndPermissionSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $ads = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_ads', 'status' => DigitalAssetStatus::Active]);
        $plan = AdvisorPlan::query()->create(['channel' => 'google_ads', 'brand_id' => $brand->id, 'customer_id' => $brand->customer_id, 'digital_asset_id' => $ads->id, 'status' => 'completed', 'completed_at' => now()]);
        $items = collect(range(1, 4))->map(fn (int $i): AdvisorItem => AdvisorItem::query()->create([
            'channel' => 'google_ads', 'customer_id' => $brand->customer_id, 'brand_id' => $brand->id, 'digital_asset_id' => $ads->id,
            'item_key' => 'k'.$i, 'category' => 'waste', 'rule_id' => 'budget-waste', 'severity' => 'high', 'priority_score' => 100 + $i,
            'title' => 'Öneri '.$i, 'reason' => 'Neden', 'evidence' => [], 'checklist' => [], 'status' => 'open', 'currency' => 'TRY',
            'first_seen_plan_id' => $plan->id, 'last_seen_plan_id' => $plan->id,
        ]));

        Livewire::test(AdvisorPanel::class)->set('bulkIds', [$items[0]->id, $items[1]->id])->call('bulkDone')->assertSet('bulkIds', []);
        Livewire::test(AdvisorPanel::class)->set('bulkIds', [$items[2]->id])->call('bulkSnooze', 30);
        Livewire::test(AdvisorPanel::class)->set('bulkIds', [$items[3]->id, $items[0]->id])->call('bulkSkip');

        $this->assertSame('done', $items[0]->fresh()->status->value, 'an item already closed is not touched by a later bulk action');
        $this->assertSame('done', $items[1]->fresh()->status->value);
        $this->assertTrue($items[2]->fresh()->snoozed_until->isFuture());
        $this->assertSame('skipped', $items[3]->fresh()->status->value);
    }
}
