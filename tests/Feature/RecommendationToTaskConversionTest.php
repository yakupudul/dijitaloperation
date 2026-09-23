<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\CreateTaskFromRecommendation;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecommendationToTaskConversionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private Brand $brand;

    private DigitalAsset $asset;

    private Recommendation $recommendation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);

        $this->actingAs($this->admin);
        Filament::setCurrentPanel('app');

        $this->customer = Customer::factory()->create(['name' => 'Acme Client']);
        $this->brand = Brand::factory()->create([
            'customer_id' => $this->customer->id,
            'name' => 'Acme Brand',
        ]);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'name' => 'Acme Corporate Website',
            'type' => 'website',
        ]);

        $finding = Finding::factory()->create([
            'digital_asset_id' => $this->asset->id,
        ]);

        $this->recommendation = Recommendation::factory()->create([
            'finding_id' => $finding->id,
            'digital_asset_id' => $this->asset->id,
            'title' => 'Optimize LCP hero image delivery',
            'action' => 'Compress and lazy-load the hero image.',
            'rationale' => 'LCP is dominated by an oversized hero image.',
            'priority' => 'high',
            'effort' => 'medium',
            'status' => 'open',
            'source_module' => 'website',
        ]);
    }

    public function test_recommendation_update_does_not_mutate_existing_task_snapshot(): void
    {
        $task = app(CreateTaskFromRecommendation::class)->create($this->recommendation, [
            'title' => 'Snapshot title',
            'priority' => 'high',
        ]);

        $originalTitle = $task->title;
        $originalAction = $task->action;
        $originalRationale = $task->rationale;
        $originalPriority = $task->priority;
        $originalSnapshot = $task->snapshot_json;

        $this->recommendation->update([
            'title' => 'Changed recommendation title',
            'action' => 'Changed action body',
            'rationale' => 'Changed rationale',
            'priority' => 'critical',
        ]);

        $task = $task->fresh();

        $this->assertSame($originalTitle, $task->title);
        $this->assertSame($originalAction, $task->action);
        $this->assertSame($originalRationale, $task->rationale);
        $this->assertSame($originalPriority, $task->priority);
        $this->assertSame($originalSnapshot, $task->snapshot_json);
        $this->assertSame($this->recommendation->id, $task->recommendation_id);

        $this->assertSame('Changed recommendation title', $this->recommendation->fresh()->title);
    }
}
