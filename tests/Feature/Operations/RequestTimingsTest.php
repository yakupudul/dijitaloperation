<?php

namespace Tests\Feature\Operations;

use App\Http\Middleware\RecordRequestTiming;
use App\Services\Operations\RequestTimings;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Live request timings (2026-10-21): per page / Livewire component, read by MCP system-health.
 */
class RequestTimingsTest extends TestCase
{
    public function test_requests_add_up_per_key_and_the_slowest_total_comes_first(): void
    {
        $timings = app(RequestTimings::class);
        $timings->record('operator.repair', 1200, 40);
        $timings->record('operator.repair', 300, 10);
        $timings->record('operator.today', 100, 5);
        RequestTimings::lazyLoad('App\Models\Suggestion.page');
        RequestTimings::lazyLoad('App\Models\Suggestion.page');
        $timings->flushLazyLoads();

        $top = $timings->top();

        $this->assertSame('operator.repair', $top[0]['key']);
        $this->assertSame(2, $top[0]['requests']);
        $this->assertSame(1.5, $top[0]['total_s']);
        $this->assertSame(750, $top[0]['avg_ms']);
        $this->assertSame(1200, $top[0]['max_ms']);
        $this->assertSame(1, $top[0]['slow']);
        $this->assertSame(25.0, $top[0]['queries_per_request']);
        $lazy = collect($top)->firstWhere('key', 'lazy:App\Models\Suggestion.page');
        $this->assertSame(2.0, $lazy['queries_per_request']);
    }

    public function test_a_livewire_update_is_keyed_by_its_components_and_calls(): void
    {
        $request = Request::create('/livewire/update', 'POST', ['components' => [
            ['snapshot' => json_encode(['memo' => ['name' => 'operator.repair.daily-send-strip']]), 'calls' => [['method' => 'send']]],
            ['snapshot' => json_encode(['memo' => ['name' => 'operator.live-feed']]), 'calls' => []],
        ]]);

        $this->assertSame('livewire:operator.live-feed+operator.repair.daily-send-strip@send', RecordRequestTiming::key($request));
    }
}
