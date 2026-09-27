<?php

namespace Tests\Feature\Clients;

use App\Livewire\Operator\Content\ContentCalendarPage;
use App\Models\Brand;
use App\Models\ClientApproval;
use App\Models\ContentCalendarItem;
use App\Models\Customer;
use App\Models\User;
use App\Services\CommandCenter\CommandCenter;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** ADR-075: signed client approval link for planned content. */
final class ClientApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ContentCalendarItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => 'active'])->id, 'name' => 'Atlas']);
        $this->item = ContentCalendarItem::query()->create([
            'brand_id' => $brand->id, 'channel' => 'social', 'title' => 'Ekim implant kampanyası', 'body' => 'Metin', 'status' => 'draft', 'scheduled_for' => now()->addDays(3),
        ]);
    }

    public function test_operator_creates_a_link_and_the_client_approves_once(): void
    {
        $this->actingAs($this->admin);
        $component = Livewire::test(ContentCalendarPage::class)->call('requestClientApproval', $this->item->id);
        $approval = ClientApproval::query()->sole();
        $this->assertSame($approval->link(), $component->get('clientLink'));
        auth()->logout();

        $this->get('/onay/'.$approval->id)->assertForbidden();
        $this->get($approval->link())->assertOk()->assertSee('Ekim implant kampanyası')->assertSee('Onaylıyorum');

        $this->post($approval->respondUrl(), ['decision' => 'approved'])->assertOk()->assertSee('Onayınız alındı');
        $this->post($approval->respondUrl(), ['decision' => 'changes_requested', 'note' => 'geç kaldım'])->assertOk();
        $this->assertSame('approved', $approval->refresh()->status, 'one answer per request');
        $this->assertSame('draft', $this->item->refresh()->status, 'the client answer never publishes by itself');

        $items = collect(app(CommandCenter::class)->items([]))->where('source', 'client_approval');
        $this->assertCount(1, $items);
        $this->actingAs($this->admin);
        app(CommandCenter::class)->act([$items->first()['key']], 'done', $this->admin);
        $this->assertNotNull($approval->refresh()->acknowledged_at);
    }

    public function test_change_request_needs_a_note_and_expired_or_replaced_links_do_nothing(): void
    {
        $approval = ClientApproval::requestFor($this->item, $this->admin);
        $this->post($approval->respondUrl(), ['decision' => 'changes_requested'])->assertOk()->assertSee('neyin değişmesini');
        $this->assertSame('pending', $approval->refresh()->status);
        $this->post($approval->respondUrl(), ['decision' => 'changes_requested', 'note' => 'Fiyatı çıkarın'])->assertOk()->assertSee('Fiyatı çıkarın');

        $newer = ClientApproval::requestFor($this->item, $this->admin);
        $older = ClientApproval::requestFor($this->item, $this->admin);
        $this->get($newer->refresh()->link())->assertNotFound();

        $this->travel(ClientApproval::VALID_DAYS + 1)->days();
        $this->get($older->link())->assertForbidden();
    }
}
