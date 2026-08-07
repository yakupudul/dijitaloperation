<?php

namespace Tests\Feature;

use App\Models\DigitalAsset;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RunModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_can_be_created_via_factory_and_persisted(): void
    {
        $asset = DigitalAsset::factory()->create();

        $startedAt = Carbon::parse('2026-08-07 10:00:00');
        $finishedAt = Carbon::parse('2026-08-07 10:05:00');

        $run = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'status' => 'completed',
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'meta' => [
                'source' => 'test',
                'checks' => 3,
            ],
        ]);

        $this->assertDatabaseHas('runs', [
            'id' => $run->id,
            'digital_asset_id' => $asset->id,
            'status' => 'completed',
        ]);

        $run->refresh();

        $this->assertSame(['source' => 'test', 'checks' => 3], $run->meta);
        $this->assertInstanceOf(Carbon::class, $run->started_at);
        $this->assertInstanceOf(Carbon::class, $run->finished_at);
        $this->assertTrue($run->started_at->equalTo($startedAt));
        $this->assertTrue($run->finished_at->equalTo($finishedAt));
        $this->assertTrue($run->digitalAsset->is($asset));
    }
}
