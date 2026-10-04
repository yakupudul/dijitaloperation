<?php

namespace Tests\Feature\Assistant;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\WhatsApp\Inbox;
use App\Models\Customer;
use App\Models\CustomerContact;
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

    public function test_new_conversations_link_to_customers_by_phone(): void
    {
        $customer = Customer::factory()->create(['status' => CustomerStatus::Active, 'name' => 'Atlas Sağlık', 'primary_phone' => '0532 111 22 33']);
        $other = Customer::factory()->create(['name' => 'Beta']);
        CustomerContact::query()->create(['customer_id' => $other->id, 'name' => 'Ali', 'phone' => '+90 (533) 444 55 66']);

        $a = $this->conversation('905321112233');
        $b = $this->conversation('905334445566');
        $d = $this->conversation('905000000000');

        $this->assertSame($customer->id, $a->fresh()->customer_id);
        $this->assertSame('phone', $a->fresh()->link_source);
        $this->assertSame($other->id, $b->fresh()->customer_id, 'customer contact phone');
        $this->assertNull($d->fresh()->link_source);
        $this->assertNull($d->fresh()->customer_id);

        app(WhatsAppContactLinker::class)->setManual($a->fresh(), $other->id);
        app(WhatsAppContactLinker::class)->linkAll();
        $this->assertSame($other->id, $a->fresh()->customer_id, 'operator choice is kept');
    }

    public function test_inbox_links_an_unmatched_conversation_to_a_customer_by_hand(): void
    {
        $customer = Customer::factory()->create(['name' => 'Gama Klinik']);
        $conversation = $this->conversation('905009998877', 'Mehmet Bey');
        $this->actingAs($this->admin);

        Livewire::test(Inbox::class)->call('selectConversation', $conversation->id)
            ->assertSee('Bu numara bir müşteriyle eşleşmedi')
            ->set('linkCustomer', (string) $customer->id)->call('saveLink')
            ->assertSee('Görüşme müşteriye bağlandı');

        $this->assertSame([$customer->id, 'operator'], [$conversation->fresh()->customer_id, $conversation->fresh()->link_source]);
    }

    private function conversation(string $waId, ?string $name = null): WhatsAppConversation
    {
        return WhatsAppConversation::query()->create([
            'integration_id' => $this->integrationId, 'phone_number_id' => '222222222', 'contact_id' => $waId,
            'contact_name' => $name, 'last_message_at' => now(),
        ]);
    }
}
