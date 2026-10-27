<?php

namespace Tests\Feature\Work;

use App\Jobs\ApproveRepairRowsJob;
use App\Livewire\Operator\Flow\FlowLive;
use App\Livewire\Operator\Flow\FlowPage;
use App\Livewire\Operator\Repair\RepairDeskPage;
use App\Models\AiTask;
use App\Models\CoreConnection;
use App\Models\ExternalWriteAction;
use App\Models\Page;
use App\Models\Suggestion;
use App\Models\User;
use App\Models\WorkBatch;
use App\Services\Repair\RepairDesk;
use App\Services\Work\FlowFeed;
use App\Services\Work\FlowRetry;
use App\Services\Work\OperatorTime;
use App\Support\OperatorMenu;
use App\Support\Roles;
use App\Support\Work\WorkStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Site\SiteTestCase;

/**
 * Aşama 4 (2026-10-22): one approval is one row of Akış whatever its size, its writes and failures open under it,
 * failures go again the way they first went, the four channels share the five stages, and the operator's minutes,
 * clicks and decision wait are counted.
 */
final class FlowTest extends SiteTestCase
{
    private Page $implantPage;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake()->except([ApproveRepairRowsJob::class]);
        $this->implantPage = $this->page('/implant/', 'Ankara İmplant Tedavisi', ['category' => 'hizmet', 'wp_post_id' => 42]);
        CoreConnection::factory()->create([
            'digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'snapshot_url' => 'https://panorama.com.tr/wp-json/moxdop/v1/snapshot', 'plugin_version' => '1.5.0'],
        ]);
    }

    public function test_a_bulk_approval_is_one_row_that_opens_to_its_writes(): void
    {
        $first = $this->suggestion('Başlık 1', ['seo_title' => 'Birinci başlık'], ['first_seen_at' => now()->subHours(6)]);
        $second = $this->suggestion('Başlık 2', ['seo_title' => 'İkinci başlık'], ['first_seen_at' => now()->subHours(2)]);

        Livewire::test(RepairDeskPage::class)->set('selected', [$first->id, $second->id])->call('approveSelected');

        $batch = WorkBatch::query()->sole();
        $this->assertSame(WorkBatch::KIND_DESK, $batch->kind);
        $this->assertSame(2, $batch->total);
        $this->assertSame(1, $batch->brand_count);
        $this->assertSame('web', $batch->channel);
        $this->assertSame(WorkBatch::DONE, $batch->status);
        $this->assertSame(2, $batch->done);
        $this->assertEqualsWithDelta(6 * 3600, $batch->decision_wait_seconds, 120, 'median age of the approved rows');
        $this->assertSame(2, ExternalWriteAction::query()->where('work_batch_id', $batch->id)->count(), 'the writes belong to the approval');

        $feed = app(FlowFeed::class);
        $row = $feed->rows()->firstWhere('key', 'batch:'.$batch->id);
        $this->assertSame('Toplu onay · Onarım masası', $row['title']);
        $this->assertSame('run', $row['state'], 'its writes are still on the way');
        $this->assertStringContainsString('2 yazma sırada', $row['who']);
        $this->assertNull($feed->rows()->first(fn (array $r): bool => str_starts_with($r['key'], 'writes:')), 'batched writes are not listed twice');
        $this->assertSame(2, $feed->counts()['queue']);

        $detail = $feed->detail('batch:'.$batch->id);
        $this->assertCount(2, $detail['items']);
        $this->assertSame('/implant/', $detail['items'][0]['subject']);
        $this->assertSame(WorkStage::LABELS[WorkStage::WORK], $detail['items'][0]['stage']);

        ExternalWriteAction::query()->update(['status' => 'succeeded', 'finished_at' => now()]);
        $this->assertSame('done', $feed->rows()->firstWhere('key', 'batch:'.$batch->id)['state']);
        $this->assertSame(2, $feed->counts()['done']);
    }

    public function test_a_row_that_did_not_go_is_listed_and_goes_again_from_akis(): void
    {
        $fields = $this->suggestion('Başlık', ['seo_title' => 'Yeni başlık']);
        $this->implantPage->forceFill(['wp_post_id' => null])->save();
        app(RepairDesk::class)->queueApproval([$fields->id], $this->admin);

        $batch = WorkBatch::query()->sole();
        $this->assertSame(WorkBatch::FAILED, $batch->status);
        $this->assertSame(1, $batch->failed);
        $this->assertStringContainsString('WordPress', (string) $batch->failures[0]['reason']);
        $feed = app(FlowFeed::class);
        $row = $feed->rows()->firstWhere('key', 'batch:'.$batch->id);
        $this->assertSame('err', $row['state']);
        $this->assertSame(1, $row['retry']);
        $this->assertSame('Onaylandı: Başlık', $row['title']);
        $item = $feed->detail('batch:'.$batch->id)['items'][0];
        $this->assertTrue($item['retry']);

        $this->implantPage->forceFill(['wp_post_id' => 42])->save();
        Livewire::test(FlowLive::class)->call('toggle', 'batch:'.$batch->id)->assertSee('Yeniden dene')
            ->call('retryItem', $item['id'])->assertSee('1 iş yeniden sıraya alındı');

        $this->assertSame(0, $batch->fresh()->failed, 'the row left the old failure list');
        $this->assertSame(WorkBatch::DONE, $batch->fresh()->status);
        $this->assertSame(2, WorkBatch::query()->count(), 'the new approval is its own row');
        $this->assertSame(1, ExternalWriteAction::query()->where('suggestion_id', $fields->id)->count());
    }

    public function test_a_failed_write_goes_again_through_the_desk_and_is_no_longer_an_error(): void
    {
        $fields = $this->suggestion('Başlık', ['seo_title' => 'Yeni başlık']);
        $failed = ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_WORDPRESS, 'action' => ExternalWriteAction::ACTION_SITE_FIX, 'digital_asset_id' => $this->site->id,
            'brand_id' => $this->brand->id, 'suggestion_id' => $fields->id, 'status' => 'failed', 'error' => 'Site 30 sn içinde yanıt vermedi',
            'request_payload' => ['changes' => [['type' => 'seo_title', 'url' => 'https://panorama.com.tr/implant/', 'value' => 'Yeni başlık', 'reference' => 'r1']]],
            'requested_by' => $this->admin->id, 'finished_at' => now(),
        ]);
        $ads = ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_GOOGLE_ADS, 'action' => ExternalWriteAction::ACTION_NEGATIVE_LIST_ADD, 'digital_asset_id' => $this->site->id,
            'brand_id' => $this->brand->id, 'status' => 'failed', 'error' => 'x', 'request_payload' => [], 'requested_by' => $this->admin->id, 'finished_at' => now(),
        ]);
        $feed = app(FlowFeed::class);
        $row = $feed->rows()->firstWhere('key', 'writes:site_fix|'.$this->brand->id);
        $this->assertSame('err', $row['state']);
        $this->assertSame('Panorama Ankara · MoxDOP', $row['who']);
        $this->assertSame('Site 30 sn içinde yanıt vermedi', $feed->detail($row['key'])['items'][0]['line']);
        $this->assertSame(2, $feed->counts()['err']);

        $result = app(FlowRetry::class)->retry($this->admin);

        $this->assertSame(1, $result['sent']);
        $this->assertNotNull($failed->fresh()->retried_at);
        $this->assertNull($ads->fresh()->retried_at, 'Google Ads writes are off: nothing goes again');
        $this->assertSame(1, ExternalWriteAction::query()->where('suggestion_id', $fields->id)->where('status', 'queued')->count(), 'approved again on the desk, checks included');
        $this->assertSame(1, $feed->counts()['err']);
    }

    public function test_claude_queue_work_is_one_row_per_operation_and_brand(): void
    {
        foreach ([AiTask::PENDING, AiTask::CLAIMED, AiTask::DONE] as $i => $status) {
            AiTask::query()->create(['operation' => 'content.article', 'brand_id' => $this->brand->id, 'subject' => 'Yazı '.$i, 'resume_key' => 'flow-'.$i, 'resume' => '{}', 'sequence' => 1, 'input_hash' => str_repeat((string) $i, 64),
                'status' => $status, 'instructions' => 'x', 'input' => '{}', 'output_schema' => [], 'completed_at' => $status === AiTask::DONE ? now() : null,
                'claimed_at' => $status === AiTask::CLAIMED ? now() : null]);
        }

        $row = app(FlowFeed::class)->rows()->firstWhere('key', 'ai:content.article|'.$this->brand->id);

        $this->assertSame('run', $row['state']);
        $this->assertSame('icerik', $row['kind']);
        $this->assertSame([3, 1], [$row['total'], $row['ok']]);
        $this->assertSame('Panorama Ankara · Claude MCP', $row['who']);
        $this->assertSame(['queue' => 1, 'run' => 1], array_intersect_key(app(FlowFeed::class)->counts(), ['queue' => 0, 'run' => 0]));
    }

    public function test_the_page_menu_and_pill_show_the_live_work(): void
    {
        $this->suggestion('Başlık', ['seo_title' => 'Yeni başlık']);
        ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_WORDPRESS, 'action' => ExternalWriteAction::ACTION_SITE_FIX, 'digital_asset_id' => $this->site->id,
            'brand_id' => $this->brand->id, 'status' => 'running', 'request_payload' => [], 'requested_by' => $this->admin->id, 'started_at' => now(),
        ]);

        $this->get(route('operator.flow'))->assertOk()
            ->assertSee('şu an çalışıyor')->assertSee('Site düzeltmesi')->assertSee('İşin yolu')->assertSee('Senin zamanın')
            ->assertSee('data-flow-pill', false)->assertSee('data-menu-badge="live"', false);
        $flow = collect(OperatorMenu::groups())->flatMap(fn (array $g): array => $g['items'])->firstWhere('path', '/akis');
        $this->assertSame(1, $flow['count']);

        Livewire::test(FlowLive::class)->call('filter', 'done')->assertSee('Bu durumda iş yok')->call('filter', 'done')->assertSee('Site düzeltmesi');

        $member = User::factory()->create(['is_active' => true]);
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member);
        Livewire::test(FlowPage::class)->assertDontSee('Senin zamanın');
        Livewire::test(FlowLive::class)->call('retryAll')->assertForbidden();
    }

    public function test_operator_minutes_clicks_and_decision_wait_are_counted_per_brand(): void
    {
        $this->get(route('operator.brand', ['brand' => $this->brand->id]))->assertOk();
        $this->get(route('operator.flow'))->assertOk();
        Livewire::test(FlowLive::class)->call('filter', 'run');
        $this->travel(2)->minutes();
        $this->get(route('operator.brand', ['brand' => $this->brand->id]))->assertOk();
        WorkBatch::query()->create(['kind' => WorkBatch::KIND_DESK, 'title' => 'x', 'user_id' => $this->admin->id, 'total' => 3, 'decision_wait_seconds' => 7200, 'status' => WorkBatch::DONE]);
        WorkBatch::query()->create(['kind' => WorkBatch::KIND_DESK, 'title' => 'y', 'user_id' => $this->admin->id, 'total' => 1, 'decision_wait_seconds' => 3600, 'status' => WorkBatch::DONE]);

        $week = app(OperatorTime::class)->week();

        $this->assertSame(2, $week['minutes'], 'two distinct minutes');
        $this->assertGreaterThanOrEqual(1, $week['clicks']);
        $this->assertSame(3, $week['views']);
        $this->assertSame((int) round((3 * 7200 + 3600) / 4), $week['wait_seconds']);
        $this->assertSame([['id' => $this->brand->id, 'name' => 'Panorama Ankara', 'minutes' => 2, 'clicks' => 0]], $week['brands']);
        $this->assertSame('1 sa 45 dk', OperatorTime::minutesLabel(105));
        $this->assertSame('2 saat', OperatorTime::waitLabel(7200));
        $this->assertSame(0, DB::table('operator_activity')->where('clicks', '>', 0)->where('brand_id', '>', 0)->count(), 'Akış has no brand');
    }

    public function test_the_four_channels_share_the_five_stages(): void
    {
        $this->suggestion('Açık', []);
        $this->suggestion('Doğrulandı', ['seo_title' => 'x'], ['status' => Suggestion::APPLIED, 'verification' => Suggestion::VERIFY_CONFIRMED, 'verified_at' => now()]);
        $this->suggestion('Ölçüldü', ['seo_title' => 'y'], ['status' => Suggestion::APPLIED, 'measured_at' => now(), 'outcome' => ['verdict' => 'won']]);
        $this->suggestion('Meta bulgusu', [], ['channel' => 'meta', 'action_type' => 'meta_creative']);

        $stages = app(FlowFeed::class)->stages();

        $this->assertSame(['web', 'gbp', 'google_ads', 'meta'], array_keys($stages));
        $this->assertSame(1, $stages['web'][WorkStage::FOUND]);
        $this->assertSame(1, $stages['web'][WorkStage::VERIFY]);
        $this->assertSame(1, $stages['web'][WorkStage::RESULT]);
        $this->assertSame(1, $stages['meta'][WorkStage::FOUND]);
        $this->assertSame(WorkStage::RESULT, WorkStage::ofSuggestion(Suggestion::query()->where('title', 'Ölçüldü')->sole()));
    }

    /** @param  array<string, string>  $fields */
    private function suggestion(string $title, array $fields, array $extra = []): Suggestion
    {
        return Suggestion::query()->create(array_merge([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'flow.test', 'fingerprint' => hash('sha256', $title.random_int(1, PHP_INT_MAX)),
            'material_hash' => hash('sha256', 'x'), 'title' => $title, 'reason' => 'Gerekçe.', 'priority' => 2, 'action_type' => 'title_description',
            'status' => Suggestion::OPEN, 'action' => $fields === [] ? [] : ['proposal' => ['kind' => 'fields', 'current' => [], 'new' => $fields]], 'evidence' => [],
            'page_id' => $this->implantPage->id,
        ], $extra));
    }
}
