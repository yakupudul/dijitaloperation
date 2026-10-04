<?php

namespace Tests\Feature;

use App\Ai\Agents\WhatsAppReplyAgent;
use App\Jobs\WhatsApp\GenerateWhatsAppSuggestion;
use App\Livewire\Operator\WhatsApp\Inbox;
use App\Models\AgencySetting;
use App\Models\CoreIntegration;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Services\WhatsApp\WhatsAppSuggestions;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** WhatsApp reply suggestions: OpenAI only, the model chosen on the WhatsApp screen, drafted right after the click. */
final class WhatsAppSuggestionModelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private WhatsAppConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->connection = app(WhatsAppConnection::class);
        Http::fake();
        $this->connection->save($this->admin, [
            'waba_id' => '111111111', 'phone_number_id' => '222222222', 'business_phone' => '905551112233', 'business_context' => 'Ajans',
            'access_token' => 'test-access-token', 'app_secret' => 'test-app-secret', 'verify_token' => 'test-verify-token-12345',
            'enabled' => true, 'automatic_suggestions' => false,
        ]);
        AgencySetting::query()->firstOrCreate([], ['agency_name' => 'Moximu']);
    }

    public function test_the_screen_saves_the_openai_model_and_the_retention_period(): void
    {
        $this->actingAs($this->admin);
        $this->assertSame(WhatsAppSuggestions::model(null), WhatsAppSuggestions::model($this->connection->integration()), 'default before a choice');

        Livewire::test(Inbox::class)
            ->set('ai_model', 'gpt-4.1-mini')->set('retention_days', '90')->call('saveAiSettings')
            ->assertHasNoErrors()->assertSee('gpt-4.1-mini ile hazırlanır');

        $this->assertSame('gpt-4.1-mini', $this->connection->integration()->config['ai_model']);
        $this->assertSame(90, (int) AgencySetting::query()->value('whatsapp_retention_days'));
        Livewire::test(Inbox::class)->assertSet('ai_model', 'gpt-4.1-mini')->assertSet('retention_days', '90');
    }

    public function test_an_unknown_model_or_a_short_retention_is_rejected(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(Inbox::class)->set('ai_model', 'claude-sonnet-5')->set('retention_days', '10')->call('saveAiSettings')
            ->assertHasErrors(['ai_model', 'retention_days']);

        $this->assertArrayNotHasKey('ai_model', $this->connection->integration()->config);
    }

    public function test_the_suggestion_button_queues_the_draft_right_away(): void
    {
        Queue::fake();
        $this->actingAs($this->admin);
        $conversation = $this->conversation();

        Livewire::test(Inbox::class)->call('generate', $conversation->id)->assertSee('birkaç saniye içinde');

        $this->assertSame('requested', $conversation->fresh()->suggestion_status);
        Queue::assertPushed(GenerateWhatsAppSuggestion::class, fn (GenerateWhatsAppSuggestion $job): bool => $job->conversationId === $conversation->id);
    }

    public function test_the_draft_uses_the_chosen_openai_model(): void
    {
        config(['moxdop.openai.api_key' => 'sk-test', 'ai.providers.openai.key' => 'sk-test']);
        CoreIntegration::factory()->openai()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $integration = $this->connection->integration();
        $integration->update(['config' => [...$integration->config, 'ai_model' => 'gpt-4.1-mini']]);
        $conversation = $this->conversation();
        $conversation->update(['suggestion_status' => 'requested']);
        WhatsAppReplyAgent::fake([['action' => 'reply', 'reply' => 'Merhaba, fiyatı hemen iletiyorum.', 'rationale' => 'Fiyat sordu.', 'summary' => 'Fiyat sorusu']]);

        app(WhatsAppSuggestions::class)->generate($conversation->id);

        $fresh = $conversation->fresh();
        $this->assertSame(['ready', 'openai:gpt-4.1-mini'], [$fresh->suggestion_status, $fresh->route_signature]);
        $this->assertSame('Merhaba, fiyatı hemen iletiyorum.', $fresh->suggestion);
        WhatsAppReplyAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, 'fiyat öğrenebilir miyim'));
    }

    public function test_without_an_openai_key_the_draft_fails_with_a_clear_code(): void
    {
        config(['moxdop.openai.api_key' => null, 'ai.providers.openai.key' => null]);
        $conversation = $this->conversation();
        $conversation->update(['suggestion_status' => 'requested']);
        WhatsAppReplyAgent::fake();

        app(WhatsAppSuggestions::class)->generate($conversation->id);

        $this->assertSame(['failed', 'ai_not_configured'], [$conversation->fresh()->suggestion_status, $conversation->fresh()->error_code]);
        WhatsAppReplyAgent::assertNeverPrompted();
    }

    private function conversation(): WhatsAppConversation
    {
        $conversation = WhatsAppConversation::query()->create([
            'integration_id' => $this->connection->integration()->id, 'phone_number_id' => '222222222', 'contact_id' => '905009998877',
            'contact_name' => 'Mehmet Bey', 'last_message_at' => now(), 'last_incoming_at' => now(), 'revision' => 1,
        ]);
        WhatsAppMessage::query()->create([
            'conversation_id' => $conversation->id, 'message_id' => 'wamid.1', 'direction' => 'incoming', 'message_type' => 'text',
            'body' => 'Merhaba, fiyat öğrenebilir miyim?', 'sent_at' => now(),
        ]);

        return $conversation;
    }
}
