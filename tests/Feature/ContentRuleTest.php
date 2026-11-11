<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Suggestion;
use App\Services\Brand\NewOrder;
use App\Services\Site\ContentRule;
use App\Services\Site\SiteSuggestionTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** İçerik kuralı (Marka Düzeni 2.7): four ideas a month, none while five written articles wait, none in the new order. */
final class ContentRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_automatic_run_is_held_by_the_month_the_backlog_and_the_new_order(): void
    {
        Cache::flush();
        $this->travelTo('2026-11-20 10:00:00');
        $brand = Brand::factory()->create();
        $idea = fn (string $key, array $action = [], ?string $at = null, string $status = Suggestion::OPEN): Suggestion => Suggestion::query()->forceCreate([
            'brand_id' => $brand->id, 'fingerprint' => $key, 'channel' => 'search', 'decision_key' => 'site.content', 'material_hash' => 'x', 'reason' => 'Deneme.', 'priority' => 5,
            'target_type' => 'brand', 'target_id' => $brand->id, 'action_type' => SiteSuggestionTypes::CONTENT, 'title' => 'Yazı '.$key, 'status' => $status, 'action' => $action,
            'created_at' => $at ?? now(), 'updated_at' => $at ?? now()]);

        $this->assertNull(ContentRule::hold($brand));
        $this->assertSame(4, ContentRule::room($brand));
        $idea('old', [], '2026-10-15 10:00:00');
        $idea('a');
        $idea('b');
        $idea('dismissed', [], null, Suggestion::DISMISSED);
        $this->assertSame(2, ContentRule::room($brand), 'last month and dismissed ideas do not count');
        $idea('c');
        $idea('d');
        $this->assertStringContainsString('Bu ay 4 yazı fikri açıldı', (string) ContentRule::hold($brand));

        $this->travelTo('2026-12-02 10:00:00');
        $this->assertNull(ContentRule::hold($brand), 'a new month opens room again');
        foreach (['w1', 'w2', 'w3', 'w4', 'w5'] as $key) {
            $idea($key, ['article' => ['html' => '<p>Yazı</p>']], '2026-10-20 10:00:00');
        }
        $this->assertSame(5, ContentRule::unpublished($brand));
        $this->assertStringContainsString('5 yazılmış yazı yayımlanmayı bekliyor', (string) ContentRule::hold($brand));

        $other = Brand::factory()->create();
        $this->assertNull(ContentRule::hold($other));
        app(NewOrder::class)->on($other);
        $this->assertStringContainsString('yeni düzende', (string) ContentRule::hold($other));
    }
}
