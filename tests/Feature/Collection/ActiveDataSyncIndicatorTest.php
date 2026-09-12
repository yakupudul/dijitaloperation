<?php

namespace Tests\Feature\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Livewire\Demo\Partials\ActiveDataSyncIndicator;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ActiveDataSyncIndicatorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function global_indicator_includes_automatic_jobs_and_does_not_invent_a_percentage(): void
    {
        app()->setLocale('en');
        $this->actingAs(User::factory()->create());
        $dataset = CollectionDatasetRun::factory()->create();
        $dataset->collectionRun->update(['requested_by_user_id' => null]);

        Livewire::test(ActiveDataSyncIndicator::class)
            ->assertSee('Data jobs')->assertSee('Queued')->assertSee('Automatic')
            ->assertSee('0/1')->assertDontSee('%');
    }

    #[Test]
    public function waiting_dependencies_do_not_make_a_fresh_running_job_look_stalled(): void
    {
        app()->setLocale('en');
        $this->actingAs(User::factory()->create());
        $running = CollectionDatasetRun::factory()->create(['status' => CollectionRunStatus::Running]);
        CollectionDatasetRun::factory()->create([
            'collection_run_id' => $running->collection_run_id,
            'collection_resource_run_id' => $running->collection_resource_run_id,
            'last_activity_at' => now()->subHours(3),
            'depends_on_dataset_run_ids' => [$running->id],
        ]);
        Livewire::test(ActiveDataSyncIndicator::class)->assertSee('Collecting')->assertDontSee('Stalled');

        $running->update(['last_activity_at' => now()->subHours(3)]);
        Livewire::test(ActiveDataSyncIndicator::class)->assertSee('Stalled');
    }

    #[Test]
    public function guest_does_not_receive_active_job_details(): void
    {
        CollectionRun::factory()->create();
        $data = app(ActiveDataSyncIndicator::class)->render()->getData();
        $this->assertSame(0, $data['activeCount']);
        $this->assertTrue($data['items']->isEmpty());
    }
}
