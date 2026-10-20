<?php

namespace Tests\Feature\Repair;

use App\Enums\CustomerStatus;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Suggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pool #47 / #49: two checks of one brand saving the same finding keep one row instead of failing on the unique key,
 * and a long reason (cluster overlaps write up to 600 characters) is stored whole.
 */
final class SuggestionOpenOnceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_same_finding_saved_twice_keeps_the_first_row_and_a_long_reason_is_stored_whole(): void
    {
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        $reason = str_repeat('Bu konuda ana sayfa yanlış seçilmiş. ', 16);
        $values = ['brand_id' => $brand->id, 'fingerprint' => hash('sha256', 'overlap'), 'channel' => 'search', 'decision_key' => 'site.overlap',
            'material_hash' => hash('sha256', 'm'), 'action_type' => 'cluster_overlap', 'title' => 'Çakışma', 'reason' => $reason, 'status' => Suggestion::OPEN, 'priority' => 3];

        $first = Suggestion::openOnce($values);
        $second = Suggestion::openOnce(['title' => 'İkinci tur'] + $values);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Suggestion::query()->count());
        $this->assertSame('Çakışma', $second->title);
        $this->assertGreaterThan(240, mb_strlen($reason));
        $this->assertSame($reason, $first->fresh()->reason);
    }
}
