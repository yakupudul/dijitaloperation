<?php

namespace Tests\Feature;

use App\Livewire\Operator\WhatsApp\Conversations;
use App\Mcp\Servers\MoxdopServer;
use App\Mcp\Tools\WhatsappConversations;
use App\Models\CoreIntegration;
use App\Models\User;
use App\Models\WhatsAppBackupLine;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\Mcp\McpKeys;
use App\Services\WhatsApp\ConversationArchive;
use App\Support\Roles;
use Carbon\CarbonInterface;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** WhatsApp konuşmaları: every chat from every source, searched in the (encrypted) message text, for the screen and Claude. */
final class WhatsAppConversationArchiveTest extends TestCase
{
    use RefreshDatabase;

    private WhatsAppConversation $merve;

    private WhatsAppConversation $archived;

    protected function setUp(): void
    {
        parent::setUp();
        $live = CoreIntegration::factory()->create(['provider' => 'whatsapp', 'status' => CoreIntegration::STATUS_ACTIVE, 'config' => ['phone_number_id' => 'PN1']]);
        $this->merve = $this->chat($live->id, 'PN1', '905321112233', 'Merve Cincioğlu');
        $this->message($this->merve, 'outgoing', 'Fatura istemediğiniz için toplam 75.000 TL olarak yapıyorum.', now()->subDays(3));
        $this->message($this->merve, 'incoming', 'Aylık ödeme ile çalışacağız diye anlamıştım', now()->subDays(2));
        $this->message($this->merve, 'incoming', 'FATURA İSTEMİYORUM, nakit ödeyeceğim', now()->subDay());
        $other = $this->chat($live->id, 'PN1', '905329998877', 'Ahmet Bey');
        $this->message($other, 'incoming', 'Faturayı şirket adına kesebilir misiniz?', now()->subHours(5));

        $line = WhatsAppBackupLine::query()->create(['name' => 'Eski telefon', 'active' => false]);
        $this->archived = $this->chat(WhatsAppBackupLine::archive()->id, $line->conversationLine(), '905301234567', 'Dr. Ayşe');
        $this->message($this->archived, 'incoming', 'Fatura istemiyoruz, elden ödeme yaparız', now()->subYear());
    }

    public function test_search_matches_every_word_ignoring_turkish_case_and_accents_across_every_source(): void
    {
        $archive = app(ConversationArchive::class);
        $result = $archive->search('fatura istem');

        $this->assertTrue($result['complete']);
        $this->assertSame(['Merve Cincioğlu', 'Merve Cincioğlu', 'Dr. Ayşe'], array_column($result['hits'], 'name'));
        $this->assertSame('Yedek: Eski telefon', $result['hits'][2]['source']);
        $this->assertSame('Canlı numara', $result['hits'][0]['source']);
        $this->assertSame('+905321112233', $result['hits'][0]['phone']);

        // Only what the person wrote; only one source; a date window.
        $this->assertSame(['Merve Cincioğlu', 'Dr. Ayşe'], array_column($archive->search('fatura istem', ['direction' => 'incoming'])['hits'], 'name'));
        $this->assertSame(['Dr. Ayşe'], array_column($archive->search('fatura', ['source' => 'line:'.WhatsAppBackupLine::query()->value('id')])['hits'], 'name'));
        $this->assertCount(2, $archive->search('fatura', ['from' => now()->subDays(2)->startOfDay()])['hits']);

        $limited = $archive->search('fatura', ['limit' => 2]);
        $this->assertCount(2, $limited['hits']);
        $this->assertTrue($limited['more']);
    }

    public function test_read_around_a_hit_and_list_by_name(): void
    {
        $archive = app(ConversationArchive::class);
        $hit = $archive->search('nakit')['hits'][0];
        $chat = $archive->read($hit['conversation_id'], around: $hit['message_id'], limit: 5);

        $this->assertSame('Merve Cincioğlu', $chat['conversation']['name']);
        $this->assertSame(['Moximu', 'Kişi', 'Kişi'], array_column($chat['messages'], 'from'));
        $this->assertSame('FATURA İSTEMİYORUM, nakit ödeyeceğim', $chat['messages'][2]['text']);
        $this->assertNull($chat['older']);

        $this->assertSame(['Merve Cincioğlu'], $archive->conversations('merve')->pluck('name')->all());
        $this->assertSame(['Dr. Ayşe'], $archive->conversations('1234567')->pluck('name')->all());
        $this->assertCount(3, $archive->conversations());
        $this->assertNull($archive->read(999999));
    }

    public function test_highlight_escapes_and_marks_folded_words(): void
    {
        $html = (string) ConversationArchive::highlight('<b>FATURA</b> İstemiyorum', ConversationArchive::words('fatura istem'));

        $this->assertStringContainsString('&lt;b&gt;<mark', $html);
        $this->assertStringContainsString('>FATURA</mark>', $html);
        $this->assertStringContainsString('>İstem</mark>iyorum', $html);
    }

    public function test_mcp_tool_searches_and_reads_but_a_read_only_key_cannot_open_chats(): void
    {
        MoxdopServer::tool(WhatsappConversations::class, ['action' => 'search', 'q' => 'fatura istem', 'direction' => 'incoming'])
            ->assertOk()->assertSee('Merve Cincioğlu')->assertSee('nakit ödeyeceğim')->assertDontSee('Ahmet Bey');
        MoxdopServer::tool(WhatsappConversations::class, ['action' => 'read', 'conversation_id' => $this->archived->id])
            ->assertOk()->assertSee('elden ödeme');
        MoxdopServer::tool(WhatsappConversations::class, ['action' => 'search', 'q' => 'a'])->assertHasErrors();

        $this->assertNotContains('whatsapp-conversations', McpKeys::readTools(), 'patient chats need the operator\'s write key');
    }

    public function test_screen_is_admin_only_and_searches_message_text(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $member = User::factory()->create(['is_active' => true]);
        $member->assignRole(Roles::TEAM_MEMBER);

        $this->actingAs($member)->get(route('operator.whatsapp.conversations'))->assertForbidden();

        $this->actingAs($admin)->get(route('operator.whatsapp.conversations'))->assertOk()->assertSee('WhatsApp konuşmaları')->assertSee('Merve Cincioğlu');
        Livewire::actingAs($admin)->test(Conversations::class)
            ->set('q', 'nakit')
            ->assertSee('ödeyeceğim')->assertDontSee('elden ödeme')
            ->call('open', $this->merve->id, WhatsAppMessage::query()->where('conversation_id', $this->merve->id)->latest('id')->value('id'))
            ->assertSee('Aylık ödeme ile çalışacağız');
    }

    private function chat(int $integrationId, string $line, string $contact, string $name): WhatsAppConversation
    {
        return WhatsAppConversation::query()->create([
            'integration_id' => $integrationId, 'phone_number_id' => $line, 'contact_id' => $contact, 'contact_name' => $name,
            'last_message_at' => now(),
        ]);
    }

    private function message(WhatsAppConversation $chat, string $direction, string $body, CarbonInterface $at): void
    {
        WhatsAppMessage::query()->create([
            'conversation_id' => $chat->id, 'message_id' => 'm'.uniqid(), 'direction' => $direction, 'message_type' => 'text',
            'body' => $body, 'sent_at' => $at, 'is_history' => true,
        ]);
        $chat->forceFill(['last_message_at' => $at])->save();
    }
}
