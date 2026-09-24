<?php

namespace Tests\Feature\Assistant;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\WhatsApp\Inbox;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Prospect;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Services\Assistant\WhatsAppContactLinker;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

final class WhatsAppContactLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $integrationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        Http::fake();
        app(WhatsAppConnection::class)->save($this->admin, [
            'waba_id' => '111111111', 'phone_number_id' => '222222222', 'business_phone' => '905551112233', 'business_context' => 'Ajans',
            'access_token' => 'test-access-token', 'app_secret' => 'test-app-secret', 'verify_token' => 'test-verify-token-12345',
            'enabled' => true, 'automatic_suggestions' => false,
        ]);
        $this->integrationId = (int) app(WhatsAppConnection::class)->integration()->id;
    }

    public function test_new_conversations_link_to_customers_and_prospects_by_phone(): void
    {
        $customer = Customer::factory()->create(['status' => CustomerStatus::Active, 'name' => 'Atlas Sağlık', 'primary_phone' => '0532 111 22 33']);
        $other = Customer::factory()->create(['name' => 'Beta']);
        CustomerContact::query()->create(['customer_id' => $other->id, 'name' => 'Ali', 'phone' => '+90 (533) 444 55 66']);
        $prospect = Prospect::factory()->create(['company_name' => 'Yeni Klinik', 'contact_phone' => '5357778899']);

        $a = $this->conversation('905321112233');
        $b = $this->conversation('905334445566');
        $c = $this->conversation('905357778899');
        $d = $this->conversation('905000000000');

        $this->assertSame([$customer->id, null], [$a->fresh()->customer_id, $a->fresh()->prospect_id]);
        $this->assertSame($other->id, $b->fresh()->customer_id, 'customer contact phone');
        $this->assertSame([null, $prospect->id], [$c->fresh()->customer_id, $c->fresh()->prospect_id]);
        $this->assertNull($d->fresh()->link_source);

        app(WhatsAppContactLinker::class)->setManual($a->fresh(), $other->id, null);
        app(WhatsAppContactLinker::class)->linkAll();
        $this->assertSame($other->id, $a->fresh()->customer_id, 'operator choice is kept');
    }

    public function test_inbox_creates_a_prospect_and_saves_its_follow_up(): void
    {
        $conversation = $this->conversation('905009998877', 'Mehmet Bey');
        $this->actingAs($this->admin);

        $component = Livewire::test(Inbox::class)->call('selectConversation', $conversation->id)
            ->assertSee('Bu numara bir müşteri veya adayla eşleşmedi')
            ->call('createProspect')->assertSee('Mehmet Bey aday olarak eklendi');
        $prospect = Prospect::query()->where('company_name', 'Mehmet Bey')->sole();
        $this->assertSame('+905009998877', $prospect->contact_phone);
        $this->assertSame(now()->addDay()->toDateString(), $prospect->next_follow_up_on->toDateString());

        $component->set('followUpOn', '2026-12-01')->set('nextStep', 'Fiyat teklifi gönder')->call('saveFollowUp')->assertSee('Aday takibi kaydedildi');
        $this->assertSame('Fiyat teklifi gönder', $prospect->fresh()->next_step);
        $this->assertSame('operator', $conversation->fresh()->link_source);
    }

    private function conversation(string $waId, ?string $name = null): WhatsAppConversation
    {
        return WhatsAppConversation::query()->create([
            'integration_id' => $this->integrationId, 'phone_number_id' => '222222222', 'contact_id' => $waId,
            'contact_name' => $name, 'last_message_at' => now(),
        ]);
    }
}
