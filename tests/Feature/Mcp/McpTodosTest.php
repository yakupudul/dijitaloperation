<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\MoxdopServer;
use App\Mcp\Tools\Todos;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\MoxiMessage;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Calendar\QuickCapture;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** todos (yakup 2026-11-13): the operator's own jobs written by Claude land in Takvim › Yapılacaklar. */
final class McpTodosTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $second = User::factory()->create(['is_active' => true]);
        $second->assignRole(Roles::ADMIN);
        CarbonImmutable::setTestNow('2026-11-13 16:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_an_undated_job_lands_once_on_the_first_admin_and_ties_to_the_brand(): void
    {
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Atlas Diş']);

        MoxdopServer::tool(Todos::class, ['action' => 'add', 'text' => "Google Ads erişimini ver\nAyarlar › Erişim'den ajans hesabını ekle.", 'brand_id' => $brand->id])
            ->assertOk()->assertSee('Yapılacaklar');
        MoxdopServer::tool(Todos::class, ['action' => 'add', 'text' => 'Google Ads erişimini ver', 'brand_id' => $brand->id])
            ->assertOk()->assertSee('Zaten');

        $rows = Reminder::query()->orderBy('id')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($this->admin->id, (int) $rows[0]->user_id);
        $this->assertSame('task', $rows[0]->kind);
        $this->assertNull($rows[0]->remind_at);
        $this->assertSame($brand->id, (int) $rows[0]->brand_id);

        MoxdopServer::tool(Todos::class, ['action' => 'list'])->assertOk()->assertSee('Google Ads erişimini ver');
        MoxdopServer::tool(Todos::class, ['action' => 'done', 'id' => $rows[0]->id])->assertOk();
        $this->assertNotNull($rows[0]->refresh()->done_at);
    }

    public function test_an_open_job_written_without_a_brand_is_tied_to_its_brand_later(): void
    {
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Atlas Diş']);
        MoxdopServer::tool(Todos::class, ['action' => 'add', 'text' => 'Harita kaydını bağla'])->assertOk();
        $row = Reminder::query()->firstOrFail();
        $this->assertNull($row->brand_id);

        MoxdopServer::tool(Todos::class, ['action' => 'brand', 'id' => $row->id, 'brand_id' => $brand->id])->assertOk()->assertSee('Atlas Diş');
        $row->refresh();
        $this->assertSame([$brand->id, $brand->customer_id, 'Harita kaydını bağla'], [(int) $row->brand_id, (int) $row->customer_id, $row->title]);

        MoxdopServer::tool(Todos::class, ['action' => 'brand', 'id' => $row->id])->assertHasErrors();
    }

    public function test_a_dated_job_keeps_its_date_and_notes_are_refused(): void
    {
        MoxdopServer::tool(Todos::class, ['action' => 'add', 'text' => '15 aralık Serap Hanım ile devam kararını konuş'])->assertOk();
        $this->assertNotNull(Reminder::query()->value('remind_at'));

        MoxdopServer::tool(Todos::class, ['action' => 'add', 'text' => 'not: bu bir not'])->assertHasErrors();
        $this->assertSame(1, Reminder::query()->count());
    }

    /** Onay (yakup 2026-11-16): Claude's own work is asked, not handed to him as a job; Onayla sends it back to Claude. */
    public function test_an_ask_waits_for_a_yes_and_onayla_hands_the_job_back_to_claude(): void
    {
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Atlas Diş']);
        $text = "Ayrılan 3 hekimin İngilizce sayfalarını kaldırayım mı?\n15 aralık sonrası bile kalmasın: /en/dis-hekimi/a/ çöpe gider.";

        MoxdopServer::tool(Todos::class, ['action' => 'ask', 'text' => $text, 'brand_id' => $brand->id, 'url' => 'https://app.moximu.com/onarim'])
            ->assertOk()->assertSee('onayına sunuldu');
        MoxdopServer::tool(Todos::class, ['action' => 'ask', 'text' => $text, 'brand_id' => $brand->id])->assertOk()->assertSee('Zaten onayında');

        $ask = Reminder::query()->sole();
        $this->assertSame(Reminder::ASKED, $ask->approval);
        $this->assertNull($ask->remind_at, 'A date inside the details does not turn a question into a reminder.');
        $this->assertSame('Ayrılan 3 hekimin İngilizce sayfalarını kaldırayım mı?', $ask->title);
        $this->assertSame('https://app.moximu.com/onarim', $ask->url, 'A panel page is kept for an ask.');

        $board = app(QuickCapture::class)->board($this->admin->id, 0);
        $this->assertSame([$ask->id], array_column($board['asks'], 'id'));
        $this->assertSame([], $board['open'], 'An ask is not a job for his hands.');
        MoxdopServer::tool(Todos::class, ['action' => 'list'])->assertOk()->assertSee('asks');

        // A tick cannot answer it.
        app(QuickCapture::class)->toggle($this->admin->id, $ask->id);
        $this->assertNull($ask->refresh()->done_at);

        app(QuickCapture::class)->decide($this->admin->id, $ask->id, true);
        $this->assertSame(Reminder::APPROVED, $ask->refresh()->approval);
        $this->assertNotNull($ask->done_at);
        $job = MoxiMessage::query()->sole();
        $this->assertSame(MoxiMessage::REQUEST, $job->role);
        $this->assertSame(MoxiMessage::PENDING, $job->status);
        $this->assertStringStartsWith('Onaylandı: Ayrılan 3 hekimin', $job->body);
        $this->assertStringContainsString('/en/dis-hekimi/a/', (string) data_get($job->meta, 'detail'));
        $this->assertSame($brand->id, (int) data_get($job->meta, 'brand_id'));

        MoxdopServer::tool(Todos::class, ['action' => 'list'])->assertOk()->assertSee('onaylandı');
    }

    public function test_reddet_closes_the_ask_without_a_job_and_done_closes_an_ask_no_longer_needed(): void
    {
        MoxdopServer::tool(Todos::class, ['action' => 'ask', 'text' => 'Bağlantılar paketini Büyüme yapayım mı?'])->assertOk();
        MoxdopServer::tool(Todos::class, ['action' => 'ask', 'text' => 'Eski sayfaları çöpe atayım mı?'])->assertOk();
        [$first, $second] = Reminder::query()->orderBy('id')->get()->all();

        app(QuickCapture::class)->decide($this->admin->id, $first->id, false);
        $this->assertSame(Reminder::REJECTED, $first->refresh()->approval);
        $this->assertSame(0, MoxiMessage::query()->count());

        MoxdopServer::tool(Todos::class, ['action' => 'done', 'id' => $second->id])->assertOk();
        $this->assertNotNull($second->refresh()->done_at);
        $this->assertSame(Reminder::ASKED, $second->approval);
        $this->assertSame(0, MoxiMessage::query()->count());
        $this->assertSame([], app(QuickCapture::class)->board($this->admin->id, 0)['asks']);
    }
}
