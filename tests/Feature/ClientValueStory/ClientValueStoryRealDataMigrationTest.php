<?php

namespace Tests\Feature\ClientValueStory;

use App\Enums\ClientValueStoryLimitation;
use App\Enums\ClientValueStoryStatus;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\Opportunity;
use App\Models\Task;
use App\Models\User;
use App\Services\ClientValueStory\ClientValueStoryReadService;
use App\Support\Tasks\TaskStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClientValueStoryRealDataMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_architecture_is_read_projection_not_writable_truth(): void
    {
        $this->assertFalse(Schema::hasTable('client_value_stories'));
        $this->assertFalse(Schema::hasTable('client_value_story_findings'));
        $this->assertFalse(class_exists('App\\Services\\ClientValueStory\\ClientValueStoryV2'));
        $this->assertTrue(class_exists(ClientValueStoryReadService::class));
    }

    public function test_story_composes_findings_opportunities_and_work(): void
    {
        [$user, $brand, $asset] = $this->seedBrand();
        $before = app(ClientValueStoryReadService::class)->domainWriteProbe();

        Finding::factory()->create([
            'customer_id' => $brand->customer_id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
            'title' => 'Conversion mapping gap',
            'status' => Finding::STATUS_OPEN,
            'first_seen_at' => '2026-07-05 10:00:00',
            'last_seen_at' => '2026-07-20 10:00:00',
            'resolved_at' => null,
        ]);
        Opportunity::factory()->create([
            'customer_id' => $brand->customer_id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
            'title' => 'Implant demand gap',
            'status' => Opportunity::STATUS_OPEN,
            'first_detected_at' => '2026-07-01 10:00:00',
            'last_detected_at' => '2026-07-15 10:00:00',
        ]);
        Task::factory()->create([
            'customer_id' => $brand->customer_id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
            'recommendation_id' => null,
            'source_kind' => 'direct',
            'title' => 'Fixed conversion mapping',
            'status' => TaskStatus::COMPLETED,
            'completed_at' => '2026-07-18 12:00:00',
            'completed_by_id' => $user->id,
        ]);

        $story = app(ClientValueStoryReadService::class)->forBrand($brand, '2026-07-01', '2026-07-31');
        $this->assertSame(ClientValueStoryStatus::Complete, $story->status);
        $this->assertCount(1, $story->findings);
        $this->assertCount(1, $story->opportunities);
        $this->assertCount(1, $story->completedWork);
        $this->assertFalse($story->hasAnyOutcomeData());
        $this->assertContains(ClientValueStoryLimitation::NoCanonicalAttribution, $story->limitations);
        $this->assertFalse($story->attributionEstablished);

        $presentation = $story->toPresentationArray();
        $this->assertSame('finding', $presentation['observations'][0]['source_type']);
        $this->assertFalse($presentation['ai_assisted']);
        $this->assertFalse($presentation['business_outcomes']['available']);
        $this->assertStringContainsString('causation', strtolower($presentation['causation_disclaimer']));

        $after = app(ClientValueStoryReadService::class)->domainWriteProbe();
        $this->assertSame($before['findings'] + 1, $after['findings']);
        $this->assertSame($before['opportunities'] + 1, $after['opportunities']);
        // Story read itself must not create additional domain rows beyond seeded setup.
        $probeBefore = app(ClientValueStoryReadService::class)->domainWriteProbe();
        app(ClientValueStoryReadService::class)->forBrand($brand, '2026-07-01', '2026-07-31');
        $this->assertSame($probeBefore, app(ClientValueStoryReadService::class)->domainWriteProbe());
    }

    public function test_missing_outcome_is_not_zero_and_no_provider_fallback(): void
    {
        [, $brand] = $this->seedBrand();
        $story = app(ClientValueStoryReadService::class)->forBrand($brand, '2026-07-01', '2026-07-31');
        $this->assertFalse($story->hasAnyOutcomeData());
        $this->assertSame([], $story->outcomes);
        $business = $story->businessOutcomesPresentation();
        $this->assertFalse($business['available']);
        $this->assertNull($business['qualified_leads']);
    }

    public function test_task_created_not_completed_is_not_delivered_work(): void
    {
        [$user, $brand, $asset] = $this->seedBrand();
        Task::factory()->create([
            'customer_id' => $brand->customer_id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
            'recommendation_id' => null,
            'source_kind' => 'direct',
            'title' => 'Still open',
            'status' => TaskStatus::OPEN,
            'completed_at' => null,
            'created_at' => '2026-07-10 09:00:00',
        ]);
        Task::factory()->create([
            'customer_id' => $brand->customer_id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
            'recommendation_id' => null,
            'source_kind' => 'direct',
            'title' => 'Done earlier',
            'status' => TaskStatus::COMPLETED,
            'completed_at' => '2026-06-01 12:00:00',
            'completed_by_id' => $user->id,
        ]);

        $story = app(ClientValueStoryReadService::class)->forBrand($brand, '2026-07-01', '2026-07-31');
        $this->assertCount(0, $story->completedWork);
        $this->assertCount(1, $story->activeWork);
    }

    public function test_cross_brand_isolation_and_no_causal_claims(): void
    {
        [$user, $brandA, $assetA] = $this->seedBrand();
        $brandB = Brand::factory()->create(['customer_id' => $brandA->customer_id, 'name' => 'Other']);
        $assetB = DigitalAsset::factory()->create(['brand_id' => $brandB->id, 'type' => 'website']);

        Finding::factory()->create([
            'customer_id' => $brandB->customer_id,
            'brand_id' => $brandB->id,
            'digital_asset_id' => $assetB->id,
            'title' => 'Other brand finding',
            'first_seen_at' => '2026-07-05 10:00:00',
            'last_seen_at' => '2026-07-05 10:00:00',
        ]);
        Finding::factory()->create([
            'customer_id' => $brandA->customer_id,
            'brand_id' => $brandA->id,
            'digital_asset_id' => $assetA->id,
            'title' => 'Brand A finding',
            'first_seen_at' => '2026-07-05 10:00:00',
            'last_seen_at' => '2026-07-05 10:00:00',
        ]);

        $story = app(ClientValueStoryReadService::class)->forBrand($brandA, '2026-07-01', '2026-07-31');
        $this->assertCount(1, $story->findings);
        $this->assertSame('Brand A finding', $story->findings[0]->title);

        foreach ($story->claims as $claim) {
            $this->assertFalse($claim->causal);
            $this->assertFalse($claim->attribution);
            $this->assertStringNotContainsString('generated', strtolower($claim->text));
            $this->assertStringNotContainsString('we increased', strtolower($claim->text));
        }
    }

    public function test_closed_opportunity_is_potential_not_realized_value(): void
    {
        [, $brand, $asset] = $this->seedBrand();
        Opportunity::factory()->create([
            'customer_id' => $brand->customer_id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
            'title' => 'Closed opp',
            'status' => Opportunity::STATUS_DISMISSED,
            'first_detected_at' => '2026-07-01 10:00:00',
            'last_detected_at' => '2026-07-10 10:00:00',
            'closed_at' => '2026-07-12 10:00:00',
        ]);

        $story = app(ClientValueStoryReadService::class)->forBrand($brand, '2026-07-01', '2026-07-31');
        $this->assertCount(1, $story->opportunities);
        $this->assertTrue($story->opportunities[0]->isPotential);
        $this->assertFalse($story->opportunities[0]->realizedValue);
    }

    public function test_source_manifest(): void
    {
        [, $brand, $asset] = $this->seedBrand();
        Finding::factory()->create([
            'customer_id' => $brand->customer_id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
            'title' => 'Finding for story',
            'first_seen_at' => '2026-07-05 10:00:00',
            'last_seen_at' => '2026-07-05 10:00:00',
        ]);

        $story = app(ClientValueStoryReadService::class)->forBrand($brand, '2026-07-01', '2026-07-31');
        $manifest = $story->sourceManifest->toArray();
        $this->assertSame((int) $brand->id, $manifest['brand_id']);
        $this->assertFalse($manifest['full_payload_copies']);
        $this->assertTrue($manifest['prompt59_pinnable']);
        $this->assertNotEmpty($manifest['finding_ids']);
    }

    public function test_summary_and_presentation_have_no_demo_fallback_flags(): void
    {
        [, $brand] = $this->seedBrand();
        $story = app(ClientValueStoryReadService::class)->forBrand($brand, '2026-07-01', '2026-07-31');
        $summary = $story->toSummaryArray();
        $this->assertFalse($summary['demo']);
        $this->assertFalse($summary['attribution_established']);
        $this->assertSame(0, $summary['observed']);
        $this->assertSame(0, $summary['delivered']);
        $presentation = $story->toPresentationArray();
        $this->assertFalse($presentation['demo']);
        $this->assertTrue($presentation['empty_sections']['findings']);
    }

    /**
     * @return array{0: User, 1: Brand, 2: DigitalAsset}
     */
    private function seedBrand(): array
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id, 'sector' => 'dental']);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website']);

        return [$user, $brand, $asset];
    }
}
