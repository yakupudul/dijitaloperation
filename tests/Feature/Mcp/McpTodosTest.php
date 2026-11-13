<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\MoxdopServer;
use App\Mcp\Tools\Todos;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Reminder;
use App\Models\User;
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

    public function test_a_dated_job_keeps_its_date_and_notes_are_refused(): void
    {
        MoxdopServer::tool(Todos::class, ['action' => 'add', 'text' => '15 aralık Serap Hanım ile devam kararını konuş'])->assertOk();
        $this->assertNotNull(Reminder::query()->value('remind_at'));

        MoxdopServer::tool(Todos::class, ['action' => 'add', 'text' => 'not: bu bir not'])->assertHasErrors();
        $this->assertSame(1, Reminder::query()->count());
    }
}
