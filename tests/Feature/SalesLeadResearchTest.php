<?php

namespace Tests\Feature;

use App\Ai\Agents\SalesResearchAgent;
use App\Jobs\Sales\CheckProspectSite;
use App\Jobs\Sales\ResearchSalesLead;
use App\Jobs\Sales\WriteSalesAnalysis;
use App\Models\CoreIntegration;
use App\Models\SalesLead;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\Sales\LeadResearcher;
use App\Services\WhatsApp\Backup\WhatsAppBackupImporter;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Yeni aday araştırması: an unknown number writes → Claude looks the business up → analysis → draft for approval. */
final class SalesLeadResearchTest extends TestCase
{
    use RefreshDatabase;

    private WhatsAppConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->connection = app(WhatsAppConnection::class);
        Http::fake();
        $this->connection->save($admin, [
            'waba_id' => '111111111', 'phone_number_id' => '222222222', 'business_phone' => '905388610288', 'business_context' => 'Ajans',
            'access_token' => 'test-access-token', 'app_secret' => 'test-app-secret', 'verify_token' => 'test-verify-token-12345',
            'enabled' => true, 'automatic_suggestions' => false,
        ]);
        config(['moxdop.anthropic.api_key' => 'sk-ant-test', 'ai.providers.anthropic.key' => 'sk-ant-test']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        Queue::fake();
    }

    public function test_a_new_number_on_the_live_line_is_researched_and_the_analysis_message_becomes_the_draft(): void
    {
        $lead = $this->newChat();
        Queue::assertPushed(ResearchSalesLead::class, fn (ResearchSalesLead $job): bool => $job->leadId === $lead->id);
        $this->assertSame('queued', $lead->fresh()->research_status);

        SalesResearchAgent::fake([$this->answer('high')]);
        $this->assertTrue(app(LeadResearcher::class)->run($lead->id));

        $lead = $lead->fresh();
        $this->assertSame(['ready', 'Örnek Diş Kliniği', 'ornekdis.com', 'Manisa'], [$lead->research_status, $lead->company, $lead->website_host, $lead->city]);
        $this->assertSame('12 yorum', $lead->research['maps']['reviews'].' yorum');
        Queue::assertPushed(CheckProspectSite::class);
        $admin = User::query()->firstOrFail();
        $this->actingAs($admin)->get('/satis/'.$lead->id)->assertOk()->assertSee('Dijital analiz')->assertSee('Randevu platforma gidiyor')
            ->assertSee('12 yorum')->assertSee('Yeniden araştır');
        SalesResearchAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, '0532 111 22 33'));

        // The site is read → the analysis is asked; its message becomes the draft waiting for "Gönder".
        $lead->forceFill(['findings_status' => 'ready'])->save();
        LeadResearcher::afterSiteRead($lead);
        Queue::assertPushed(WriteSalesAnalysis::class);
        $lead->forceFill(['analysis' => str_repeat('a', 250), 'analysis_message' => "Ayşe Hocam merhaba, web sitenizi inceledik.\n1. *Randevu*", 'analysis_status' => 'ready', 'draft' => 'kısa', 'draft_ai' => 'kısa'])->save();
        LeadResearcher::draftFromAnalysis($lead);
        $this->assertStringStartsWith('Ayşe Hocam merhaba', (string) $lead->fresh()->draft);
        $this->assertStringContainsString('Onaylarsan Gönder', (string) $lead->fresh()->draft_note);
    }

    public function test_an_unsure_match_does_not_fill_the_card_nor_write_an_analysis(): void
    {
        $lead = $this->newChat();
        SalesResearchAgent::fake([$this->answer('low')]);
        app(LeadResearcher::class)->run($lead->id);

        $lead = $lead->fresh();
        $this->assertSame('ready', $lead->research_status);
        $this->assertNull($lead->company);
        $this->assertStringContainsString('emin değil', (string) $lead->research_note);
        Queue::assertNotPushed(CheckProspectSite::class);
        Queue::assertNotPushed(WriteSalesAnalysis::class);
    }

    public function test_backup_chats_and_chats_we_already_wrote_to_are_never_researched(): void
    {
        $this->newChat(line: WhatsAppBackupImporter::LINE, contact: '905321112299');
        $this->newChat(contact: '905321112288', outgoing: true);

        Queue::assertNotPushed(ResearchSalesLead::class);
    }

    public function test_the_day_has_a_research_limit(): void
    {
        $lead = $this->newChat();
        SalesLead::query()->whereKey($lead->id)->update(['research_status' => null]);
        foreach (range(1, LeadResearcher::DAILY_MAX) as $i) {
            SalesLead::query()->create(['source' => 'other', 'stage' => 'new', 'name' => 'Aday '.$i, 'research_at' => now(), 'research_status' => 'ready']);
        }

        $this->assertFalse(LeadResearcher::queue($lead->fresh()));
    }

    private function newChat(string $line = '222222222', string $contact = '905321112233', bool $outgoing = false): SalesLead
    {
        $conversation = WhatsAppConversation::query()->create([
            'integration_id' => $this->connection->integration()->id, 'phone_number_id' => $line, 'contact_id' => $contact,
            'contact_name' => 'Ayşe', 'last_message_at' => now(), 'revision' => 1,
        ]);
        WhatsAppMessage::query()->create(['conversation_id' => $conversation->id, 'message_id' => 'wamid.'.$contact, 'direction' => 'incoming',
            'message_type' => 'text', 'body' => 'Merhaba, sitemiz için bilgi alabilir miyim?', 'sent_at' => now(), 'is_history' => false]);
        if ($outgoing) {
            WhatsAppMessage::query()->create(['conversation_id' => $conversation->id, 'message_id' => 'wamid.out'.$contact, 'direction' => 'outgoing',
                'message_type' => 'text', 'body' => 'Merhaba', 'sent_at' => now()->subDay(), 'is_history' => false]);
        }
        $conversation->update(['last_incoming_at' => now()]);

        return SalesLead::query()->where('conversation_id', $conversation->id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function answer(string $confidence): array
    {
        return [
            'business_name' => 'Örnek Diş Kliniği', 'person_name' => 'Dt. Ayşe Örnek', 'kind' => 'Diş kliniği', 'city' => 'Manisa', 'district' => 'Yunusemre',
            'website' => 'https://www.ornekdis.com', 'instagram' => 'https://instagram.com/ornekdis', 'google_maps' => 'https://maps.google.com/?cid=1',
            'maps' => ['rating' => '4,8', 'reviews' => '12', 'category' => 'Diş hekimi', 'address' => 'Yunusemre/Manisa', 'website_linked' => 'evet'],
            'instagram_facts' => ['followers' => '1.200', 'posts' => '80', 'last_post' => '3 hafta önce', 'bio' => ''],
            'site_notes' => ['Randevu doktortakvimi\'ne gidiyor'], 'findings' => [['title' => 'Randevu platforma gidiyor', 'detail' => 'Hasta siteden çıkıyor.', 'source' => 'site']],
            'confidence' => $confidence, 'sources' => ['https://www.ornekdis.com'], 'note' => $confidence === 'low' ? 'Aynı adda iki hekim var.' : '',
        ];
    }
}
