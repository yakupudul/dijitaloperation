<?php

namespace Tests\Feature\Brain;

use App\Enums\CustomerStatus;
use App\Jobs\RunAdvisorPlanJob;
use App\Livewire\Operator\Advisor\AdvisorPanel;
use App\Livewire\Operator\Settings\MethodLibraryPage;
use App\Models\AdvisorItem;
use App\Models\AdvisorPlan;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Advisor\AdvisorPlanWriter;
use App\Services\Brain\MethodLibrary;
use App\Services\Brain\RuleEffectiveness;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

final class MethodLibraryAndVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private DigitalAsset $ads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        $this->ads = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads']);
    }

    public function test_thresholds_are_overridden_listed_and_reset(): void
    {
        $library = app(MethodLibrary::class);
        $this->assertSame(3, config('moxdop-advisor.google_ads.negatives.min_clicks'));

        $library->set('moxdop-advisor.google_ads.negatives.min_clicks', '5', null);
        $library->set('moxdop-advisor.google_ads.negatives.stop_words', "ve\nile\nama", null);

        $this->assertSame(5, config('moxdop-advisor.google_ads.negatives.min_clicks'));
        $this->assertSame(['ve', 'ile', 'ama'], config('moxdop-advisor.google_ads.negatives.stop_words'));
        $this->assertSame('5', DB::table('method_settings')->where('config_key', 'moxdop-advisor.google_ads.negatives.min_clicks')->value('value'));

        // A fresh boot (new request) re-applies the saved value over the file default.
        config(['moxdop-advisor.google_ads.negatives.min_clicks' => 3]);
        Cache::forget(MethodLibrary::CACHE_KEY);
        MethodLibrary::boot();
        $this->assertSame(5, config('moxdop-advisor.google_ads.negatives.min_clicks'));

        $row = collect($library->catalog()['moxdop-advisor']['sections']['google_ads.negatives'])->firstWhere('name', 'min_clicks');
        $this->assertSame([5, 3, true], [$row['value'], $row['default'], $row['overridden']]);

        $library->reset('moxdop-advisor.google_ads.negatives.min_clicks');
        $this->assertSame(3, config('moxdop-advisor.google_ads.negatives.min_clicks'));

        $this->expectException(InvalidArgumentException::class);
        $library->set('moxdop-advisor.queue', 'redis', null);
    }

    public function test_page_edits_values_and_switches_rules(): void
    {
        Livewire::test(MethodLibraryPage::class)
            ->assertSee('negative-keywords')
            ->call('toggleRule', 'advisor', 'negative-keywords')->assertSee('kapatıldı')
            ->set('tab', 'thresholds')->assertSee('min_clicks')
            ->set('values.'.md5('moxdop-alerts.spend_spike.ratio'), '2.5')->call('save', 'moxdop-alerts.spend_spike.ratio')->assertSee('kaydedildi');

        $this->assertSame(['negative-keywords'], app(MethodLibrary::class)->disabledRules('advisor'));
        $this->assertSame(2.5, config('moxdop-alerts.spend_spike.ratio'));
    }

    public function test_done_item_is_verified_or_reopened_when_the_problem_comes_back_and_snooze_expires(): void
    {
        $writer = app(AdvisorPlanWriter::class);
        $candidate = $this->candidate('negative-keywords');
        $writer->write($this->plan(), [$candidate]);
        $item = AdvisorItem::query()->sole();
        $item->forceFill(['status' => 'done', 'resolved_at' => now()])->save();

        $writer->write($this->plan(), [$candidate]);
        $this->assertSame(['done', 'still_detected'], [$item->fresh()->status->value, $item->fresh()->verification]);

        $this->travel(8)->days();
        $writer->write($this->plan(), [$candidate]);
        $this->assertSame(['open', 'recurred', 1], [$item->fresh()->status->value, $item->fresh()->verification, $item->fresh()->reopened_count]);

        $item->refresh()->forceFill(['status' => 'done', 'resolved_at' => now(), 'verification' => null])->save();
        $writer->write($this->plan(), []);
        $this->assertSame('verified', $item->fresh()->verification);

        $other = $this->candidate('budget-waste');
        $writer->write($this->plan(), [$other]);
        $snoozed = AdvisorItem::query()->where('rule_id', 'budget-waste')->sole();
        $snoozed->forceFill(['status' => 'skipped', 'resolved_at' => now(), 'snoozed_until' => now()->addDays(3)])->save();
        $writer->write($this->plan(), [$other]);
        $this->assertSame('skipped', $snoozed->fresh()->status->value);
        $this->travel(4)->days();
        $writer->write($this->plan(), [$other]);
        $this->assertSame('open', $snoozed->fresh()->status->value);
        $this->assertNull($snoozed->fresh()->snoozed_until);
    }

    public function test_rule_weight_follows_measured_outcomes(): void
    {
        $plan = $this->plan();
        foreach (range(1, 6) as $i) {
            $improved = $i <= 5;
            AdvisorItem::query()->create([
                'channel' => 'google_ads', 'customer_id' => $this->brand->customer_id, 'brand_id' => $this->brand->id, 'digital_asset_id' => $this->ads->id,
                'item_key' => 'k'.$i, 'category' => 'waste', 'rule_id' => 'negative-keywords', 'severity' => 'medium', 'priority_score' => 400,
                'title' => 't', 'reason' => 'r', 'evidence' => [], 'checklist' => [], 'status' => 'done', 'first_seen_plan_id' => $plan->id, 'last_seen_plan_id' => $plan->id,
                'outcome' => ['status' => 'measured', 'before' => 100, 'after' => $improved ? 40 : 120, 'good_direction' => 'down'],
            ]);
        }
        $effect = app(RuleEffectiveness::class);

        $this->assertSame(['done' => 6, 'measured' => 6, 'improved' => 5, 'reopened' => 0], $effect->all()['negative-keywords']);
        $this->assertSame(1.133, $effect->weight('negative-keywords'));
        $this->assertSame(1.0, $effect->weight('budget-waste'), 'not enough outcomes yet');
        $this->assertSame(453.2, $effect->reweigh([['rule_id' => 'negative-keywords', 'priority_score' => 400]])[0]['priority_score']);
    }

    public function test_done_queues_a_rules_only_verification_plan(): void
    {
        Queue::fake();
        $plan = $this->plan();
        $item = AdvisorItem::query()->create([
            'channel' => 'google_ads', 'customer_id' => $this->brand->customer_id, 'brand_id' => $this->brand->id, 'digital_asset_id' => $this->ads->id,
            'item_key' => 'v1', 'category' => 'waste', 'rule_id' => 'budget-waste', 'severity' => 'medium', 'priority_score' => 100,
            'title' => 't', 'reason' => 'r', 'evidence' => [], 'checklist' => [], 'status' => 'open', 'first_seen_plan_id' => $plan->id, 'last_seen_plan_id' => $plan->id,
        ]);

        Livewire::test(AdvisorPanel::class)->call('markDone', $item->id)->assertSee('kontrol ediliyor');

        $this->assertSame('done', $item->fresh()->status->value);
        $this->assertSame(1, AdvisorPlan::query()->where('trigger', 'verify')->where('digital_asset_id', $this->ads->id)->count());
        Queue::assertPushed(RunAdvisorPlanJob::class);

        config(['moxdop-advisor.brain.verify_on_done' => false]);
        $item->forceFill(['status' => 'open'])->save();
        AdvisorPlan::query()->where('trigger', 'verify')->delete();
        Livewire::test(AdvisorPanel::class)->call('markDone', $item->id);
        $this->assertSame(0, AdvisorPlan::query()->where('trigger', 'verify')->count());
    }

    private function plan(): AdvisorPlan
    {
        return AdvisorPlan::query()->create(['channel' => 'google_ads', 'brand_id' => $this->brand->id, 'customer_id' => $this->brand->customer_id, 'digital_asset_id' => $this->ads->id, 'status' => 'completed', 'completed_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function candidate(string $rule): array
    {
        return ['item_key' => hash('sha256', 'google_ads|'.$rule), 'category' => 'waste', 'rule_id' => $rule, 'severity' => 'medium', 'priority_score' => 400.0,
            'impact_amount' => null, 'impact_label' => '', 'currency' => 'TRY', 'title' => $rule, 'reason' => 'r', 'evidence' => [], 'checklist' => [], 'copy_text' => null, 'baseline' => null];
    }
}
