<?php

namespace Tests\Feature\Site;

use App\Services\Site\ContentPlanner;
use Tests\TestCase;

/** Panel audit K2: thin pages of main (★) services go before the usual new / update / AI-question shares. */
final class ContentPlannerMixTest extends TestCase
{
    public function test_main_service_thin_pages_come_first_up_to_half_of_the_run(): void
    {
        $candidate = fn (int $id, string $kind, int $tier = 0): array => ['id' => $id, 'kind' => $kind, 'source' => $kind === 'update' ? 'update' : 'cluster', 'tier' => $tier];
        $candidates = [
            $candidate(1, 'update', -1), $candidate(2, 'update', -1), $candidate(3, 'update', -1),
            $candidate(4, 'new'), $candidate(5, 'new'), $candidate(6, 'new'), $candidate(7, 'update'),
        ];

        $ids = array_column(ContentPlanner::mix($candidates, 4), 'id');

        $this->assertSame([1, 2, 4, 5], $ids, 'two ★ thin pages (half of 4), the update share spent on them, the rest new pages');
    }
}
