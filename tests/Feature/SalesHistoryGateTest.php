<?php

namespace Tests\Feature;

use App\Jobs\Sales\DraftSalesReply;
use App\Livewire\Operator\Sales\SalesPage;
use App\Models\AgencySetting;
use App\Models\CoreIntegration;
use App\Models\SalesLead;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\Inbox\InboxQueue;
use App\Services\Sales\SalesLeads;
use App\Services\Sales\SalesTidy;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Support\Roles;
use App\Support\WhatsApp\MessageText;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "İlk tam senkron bitmeden borç üretme" (yakup 2026-11-09): imported history makes no debts, steps or counts; old
 * silent history goes to the archive; a live message brings it back.
 */
final class SalesHistoryGateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true, 'locale' => 'tr']);
        $this->admin->assignRole(Roles::ADMIN);
        AgencySetting::query()->firstOrCreate([], ['agency_name' => 'Moximu']);
        CarbonImmutable::setTestNow('2026-11-09 12:00:00');
        Http::fake();
        Queue::fake();
        app(WhatsAppConnection::class)->save($this->admin, [
            'waba_id' => '111111111', 'phone_number_id' => '222222222', 'business_phone' => '905388610288', 'business_context' => 'Ajans',
            'access_token' => 'test-access-token', 'app_secret' => 'test-app-secret', 'verify_token' => 'test-verify-token-12345',
            'enabled' => true, 'automatic_suggestions' => false,
        ]);
        config(['moxdop.anthropic.api_key' => 'sk-ant-test', 'ai.providers.anthropic.key' => 'sk-ant-test']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** A chat the way ingestion builds it: the conversation, its message, then the conversation's times. */
    private function chat(string $phone, string $body, int $daysAgo, bool $history, string $direction = 'incoming'): WhatsAppConversation
    {
        $conversation = WhatsAppConversation::query()->firstOrCreate(
            ['integration_id' => app(WhatsAppConnection::class)->integration()->id, 'phone_number_id' => '222222222', 'contact_id' => $phone],
        );
        $at = now()->subDays($daysAgo);
        WhatsAppMessage::query()->create(['conversation_id' => $conversation->id, 'message_id' => 'm-'.$phone.'-'.$at->timestamp.'-'.$direction, 'direction' => $direction,
            'message_type' => 'text', 'body' => $body, 'sent_at' => $at, 'is_history' => $history]);
        $update = ['last_message_at' => $at];
        if ($direction === 'incoming') {
            $update['last_incoming_at'] = $at;
        }
        $conversation->update($update);

        return $conversation->fresh();
    }

    private function leadOf(WhatsAppConversation $conversation): SalesLead
    {
        return SalesLead::query()->where('conversation_id', $conversation->id)->firstOrFail();
    }

    public function test_history_makes_no_debt_old_history_is_archived_and_a_live_message_brings_it_back(): void
    {
        $old = $this->chat('905301110000', 'Merhaba, fiyat alabilir miyim?', 40, true);
        $lead = $this->leadOf($old);
        $this->assertTrue($lead->history_only);
        $this->assertTrue($lead->isArchived(), 'an old chat from the history goes straight to the archive');
        $this->assertFalse($lead->awaitsUs());
        $this->assertNull($lead->next_step_at);
        $this->assertSame(0, SalesLead::query()->relevant()->count());

        $recent = $this->leadOf($this->chat('905301110001', 'Bilgi almak istiyorum', 3, true));
        $this->assertFalse($recent->isArchived(), 'a recent history chat stays in the list');
        $this->assertFalse($recent->awaitsUs(), 'but it owes nothing: whether it was answered on the phone is unknown');
        $this->assertNull($recent->next_step_at);
        $this->assertSame([], app(InboxQueue::class)->view('borclu')->pluck('key')->all());
        Queue::assertNotPushed(DraftSalesReply::class);

        // They write on the live line: the card comes back, owes an answer and Claude writes the reply.
        $this->chat('905301110000', 'Merhaba, hâlâ ilgileniyorum', 0, false);
        $lead = $lead->fresh();
        $this->assertFalse($lead->history_only);
        $this->assertFalse($lead->isArchived());
        $this->assertTrue($lead->awaitsUs());
        $this->assertSame('Cevap yaz', $lead->next_step);
        Queue::assertPushed(DraftSalesReply::class, fn (DraftSalesReply $job): bool => $job->leadId === $lead->id);
        $this->assertSame(['wa-'.$old->id], app(InboxQueue::class)->view('borclu')->pluck('key')->all());
    }

    public function test_the_sweep_archives_stale_history_but_never_a_card_the_operator_worked(): void
    {
        // Cards from before this change: open, with automatic red steps, nothing marked yet.
        $silent = $this->leadOf($this->chat('905302220000', 'Merhaba', 20, false));
        $worked = $this->leadOf($this->chat('905302220001', 'Fiyat nedir?', 20, false));
        WhatsAppMessage::query()->whereIn('conversation_id', [$silent->conversation_id, $worked->conversation_id])->update(['is_history' => true]);
        SalesLead::query()->whereKey([$silent->id, $worked->id])->update(['history_only' => false, 'archived_at' => null, 'next_step' => 'Cevap yaz', 'next_step_at' => now()->subDay()]);
        app(SalesLeads::class)->event($worked, 'note', 'Telefonda konuştuk, ay sonu dönecek.');

        $stats = app(SalesTidy::class)->sweep();
        $this->assertSame(2, $stats['history']);
        $this->assertTrue($silent->fresh()->isArchived());
        $this->assertSame('history', $silent->fresh()->archive_reason);
        $this->assertFalse($worked->fresh()->isArchived());
        $this->assertNull($worked->fresh()->next_step_at, 'the automatic step built on history is taken off');

        // Satış › Arşiv lists it with Geri al; restoring keeps it out of the next sweep.
        Livewire::test(SalesPage::class)->call('showStatus', 'arsiv')->assertSeeHtml('data-sales-lead="'.$silent->id.'"')->assertSee('Geri al')
            ->call('restore', $silent->id);
        $this->assertFalse($silent->fresh()->isArchived());
        app(SalesTidy::class)->sweep();
        $this->assertFalse($silent->fresh()->isArchived());
    }

    public function test_placeholders_read_as_plain_words(): void
    {
        $this->assertSame('(Okunamayan mesaj, telefonda görün)', MessageText::clean('[errors — metin olarak çözümlenmedi]'));
        $this->assertSame('(Fotoğraf, video ya da dosya)', MessageText::clean('[media_placeholder — metin olarak çözümlenmedi]'));
        $this->assertSame('(Okunamayan mesaj, telefonda görün)', MessageText::clean('[WhatsApp bu mesajın içeriğini paylaşmadı · telefonda görün]'));
        $this->assertSame('Merhaba', MessageText::clean('Merhaba'));
    }
}
