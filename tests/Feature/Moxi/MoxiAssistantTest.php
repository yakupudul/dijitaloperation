<?php

namespace Tests\Feature\Moxi;

use App\Jobs\Moxi\MoxiTurnJob;
use App\Livewire\Operator\Moxi;
use App\Mcp\Servers\MoxdopServer;
use App\Mcp\Tools\ListTasks;
use App\Mcp\Tools\MoxiRequests;
use App\Models\AgencySetting;
use App\Models\Agreement;
use App\Models\MoxiMessage;
use App\Models\SystemChange;
use App\Models\User;
use App\Services\AiTasks\AiWorkers;
use App\Services\Moxi\MoxiAgent;
use App\Services\Moxi\MoxiCharacter;
use App\Services\Moxi\MoxiTools;
use App\Services\Sales\SalesLeads;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Moxi, the panel's corner assistant (yakup 2026-11-09): answers with tools, changes the panel only through cards the
 * operator confirms, hands what it cannot do to Claude over MCP, and writes its findings to the Geliştirme havuzu.
 */
class MoxiAssistantTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true, 'name' => 'Yakup Udul']);
        $this->admin->assignRole(Roles::ADMIN);
        AgencySetting::query()->firstOrCreate([], ['agency_name' => 'Moximu']);
        config(['moxdop.anthropic.api_key' => 'test-key']);
        $this->actingAs($this->admin);
    }

    /** @param list<array<string, mixed>> $replies */
    private function fakeClaude(array $replies): void
    {
        $sequence = Http::sequence();
        foreach ($replies as $reply) {
            $sequence->push($reply + ['usage' => ['input_tokens' => 1000, 'output_tokens' => 100]]);
        }
        Http::fake(['api.anthropic.com/*' => $sequence, '*' => Http::response([], 200)]);
    }

    private function ask(string $text, ?string $page = null): MoxiMessage
    {
        $message = MoxiMessage::query()->create(['user_id' => $this->admin->id, 'role' => MoxiMessage::USER, 'status' => MoxiMessage::PENDING, 'body' => $text, 'page' => $page]);
        app(MoxiAgent::class)->turn($message);

        return $message->fresh();
    }

    public function test_only_admins_get_moxi_on_panel_screens(): void
    {
        $this->get(route('operator.dashboard'))->assertOk()->assertSee('data-moxi', false)->assertSee('window.moxiChat', false);

        $member = User::factory()->create(['is_active' => true]);
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member)->get(route('operator.dashboard'))->assertDontSee('data-moxi', false);
    }

    public function test_a_message_waits_for_the_answer_job(): void
    {
        Queue::fake();
        Livewire::test(Moxi::class)->set('text', 'Gelen kutusu linki')->call('send', 'https://app.moximu.com/takvim')
            ->assertSet('text', '')->assertDispatched('moxi-think');

        $message = MoxiMessage::query()->where('role', MoxiMessage::USER)->sole();
        $this->assertSame(MoxiMessage::PENDING, $message->status);
        $this->assertSame('https://app.moximu.com/takvim', $message->page);
        Queue::assertPushed(MoxiTurnJob::class, fn (MoxiTurnJob $job): bool => $job->messageId === $message->id && $job->queue === 'writes');
    }

    public function test_moxi_answers_with_its_tools_and_the_cost_counts_toward_the_ai_limits(): void
    {
        $lead = app(SalesLeads::class)->quickAdd(['company' => 'Portakal Diş', 'name' => 'Eslem Hanım', 'phone' => '0530 951 56 11'], $this->admin);
        $this->fakeClaude([
            ['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'satis_ara', 'input' => ['q' => 'portakal']]]],
            ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Portakal Diş devam eden bir aday. [Kartı aç](/satis/'.$lead->id.')']]],
        ]);

        $asked = $this->ask('Portakal Diş ne durumda?');

        $this->assertSame(MoxiMessage::DONE, $asked->status);
        $answer = MoxiMessage::query()->where('role', MoxiMessage::MOXI)->sole();
        $this->assertStringContainsString('Portakal Diş devam eden', $answer->body);
        $this->assertGreaterThan(0, $answer->cost_usd);
        // The tool ran and its result went back to the model.
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'anthropic') && str_contains(json_encode($request->data()), 'Eslem'));
        $this->assertGreaterThan(0, app(AiWorkers::class)->spendTry(AiWorkers::CLAUDE_API, now()->startOfDay()));
        $this->assertStringContainsString('<a href="/satis/'.$lead->id.'"', Moxi::html($answer->body));
    }

    public function test_a_status_change_is_a_card_and_only_yap_makes_the_customer(): void
    {
        $lead = app(SalesLeads::class)->quickAdd(['company' => 'Portakal Diş', 'phone' => '0530 951 56 11'], $this->admin);
        $this->fakeClaude([
            ['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'durum_degistir', 'input' => [
                'lead_id' => $lead->id, 'status' => 'musteri', 'agreement' => ['fee' => 14000, 'kind' => 'monthly', 'due_day' => 1, 'note' => '6 ay peşin'],
            ]]]],
            ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Kartı hazırladım, kontrol eder misin?']]],
        ]);

        $this->ask('Portakal Diş müşteri oldu, 14.000 aylık, 6 ay peşin');

        $card = MoxiMessage::query()->where('role', MoxiMessage::CARD)->sole();
        $this->assertSame(MoxiMessage::PENDING, $card->status);
        $this->assertStringContainsString('14.000 ₺ + KDV', implode(' ', $card->meta['lines']));
        $this->assertFalse($lead->fresh()->isCustomer(), 'nothing changes before Yap');

        Livewire::test(Moxi::class)->call('confirm', $card->id)->assertDispatched('moxi-happy');

        $this->assertSame(MoxiMessage::DONE, $card->fresh()->status);
        $this->assertTrue($lead->fresh()->isCustomer());
        $agreement = Agreement::query()->where('brand_id', $lead->fresh()->brand_id)->sole();
        $this->assertSame(14000, (int) $agreement->fee);
        $this->assertTrue($lead->events()->get()->contains(fn ($event): bool => str_starts_with((string) $event->body, 'Moxi müşteriye çevirdi')), 'event bodies are encrypted');
    }

    public function test_a_card_for_a_wrong_record_is_refused_and_a_cancelled_card_never_runs(): void
    {
        $this->fakeClaude([
            ['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'not_ekle', 'input' => ['lead_id' => 999, 'text' => 'ara']]]],
            ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Bu kaydı bulamadım.']]],
        ]);
        $this->ask('999 numaraya not ekle');
        $this->assertSame(0, MoxiMessage::query()->where('role', MoxiMessage::CARD)->count());
        Http::assertSent(fn (Request $request): bool => str_contains(json_encode($request->data()), 'HATA: Sat'));

        $lead = app(SalesLeads::class)->quickAdd(['company' => 'Dentafix'], $this->admin);
        $card = MoxiMessage::query()->create(['user_id' => $this->admin->id, 'role' => MoxiMessage::CARD, 'status' => MoxiMessage::PENDING, 'body' => 'Not ekle',
            'meta' => ['action' => 'lead_note', 'args' => ['lead_id' => $lead->id, 'text' => 'Yarın ara'], 'lines' => []]]);
        Livewire::test(Moxi::class)->call('cancel', $card->id)->call('confirm', $card->id);
        $this->assertSame(MoxiMessage::CANCELLED, $card->fresh()->status);
        $this->assertFalse($lead->events()->get()->contains('body', 'Yarın ara'));
    }

    public function test_without_the_api_the_message_goes_to_claude_and_comes_back_over_mcp(): void
    {
        config(['moxdop.anthropic.api_key' => null, 'ai.providers.anthropic.key' => null]);
        Http::fake();

        $this->ask('Buşra için bu haftaki Meta sonuçlarını yorumla', 'https://app.moximu.com/brands/5');

        $request = MoxiMessage::query()->where('role', MoxiMessage::REQUEST)->sole();
        $this->assertSame(MoxiMessage::PENDING, $request->status);
        $this->assertStringContainsString('Claude\'a ilettim', MoxiMessage::query()->where('role', MoxiMessage::MOXI)->sole()->body);
        MoxdopServer::tool(ListTasks::class, [])->assertOk()->assertSee('"moxi_requests":1', false);
        MoxdopServer::tool(MoxiRequests::class, ['action' => 'list'])->assertOk()->assertSee('Meta sonuçlarını');
        MoxdopServer::tool(MoxiRequests::class, ['action' => 'answer', 'id' => $request->id, 'answer' => 'CPL bu hafta 180 TL; [Meta](/meta)'])->assertOk();

        $this->assertSame(MoxiMessage::DONE, $request->fresh()->status);
        Livewire::test(Moxi::class)->set('seen', 0)->call('refresh')->assertDispatched('moxi-said');
        MoxdopServer::tool(MoxiRequests::class, ['action' => 'answer', 'id' => $request->id, 'answer' => 'tekrar'])->assertHasErrors();
    }

    public function test_moxi_writes_its_findings_to_the_pool_and_waits_for_approval(): void
    {
        $this->fakeClaude([
            ['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'oneri_yaz', 'input' => [
                'kind' => 'bug', 'title' => 'Müşteri yaparken ödeme günü sorulmuyor', 'detail' => 'Anlaşma ödeme günü boş kalınca 1 oluyor; kartta sorulmalı.',
            ]]]],
            ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Bir öneri yazdım.']]],
        ]);
        $this->ask('Ödeme günü neden sorulmadı?');

        $change = SystemChange::query()->sole();
        $this->assertSame(SystemChange::PROPOSED, $change->status, 'Moxi findings wait for yakup even when called a bug');
        $this->assertSame('improvement', $change->kind);
        $audit = MoxiMessage::query()->where('role', MoxiMessage::AUDIT)->sole();

        Livewire::test(Moxi::class)->assertSee("Moxi'nin önerisi", false)->call('decide', $audit->id, true);
        $this->assertSame(SystemChange::APPROVED, $change->fresh()->status);
        $this->assertSame(MoxiMessage::DONE, $audit->fresh()->status);
    }

    public function test_every_tool_schema_has_object_properties_as_the_api_requires(): void
    {
        foreach (app(MoxiTools::class)->definitions() as $tool) {
            $this->assertStringStartsWith('{', (string) json_encode($tool['input_schema']['properties']), $tool['name'].': properties must be a JSON object');
        }
    }

    public function test_a_tool_called_without_arguments_goes_back_to_the_api_as_an_object(): void
    {
        $this->fakeClaude([
            ['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'bekleyenler', 'input' => new \stdClass]]],
            ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Bekleyen iş yok.']]],
        ]);
        $this->ask('Bekleyen ne var?');

        $this->assertSame(MoxiMessage::DONE, MoxiMessage::query()->where('role', MoxiMessage::USER)->sole()->status);
        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '"tool_result"') && str_contains($request->body(), '"input":{}'));
    }

    public function test_moxi_keeps_what_it_learns_in_a_notebook_the_operator_can_edit(): void
    {
        $this->fakeClaude([
            ['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'deftere_yaz', 'input' => ['text' => 'Fiyatları hep +KDV yaz.', 'kind' => 'tercih']]]],
            ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Not ettim.']]],
            ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => '14.000 ₺ + KDV.']]],
        ]);
        $this->ask('Bundan sonra fiyatları hep +KDV yaz');
        $note = MoxiMessage::query()->where('role', MoxiMessage::NOTE)->sole();
        $this->assertSame('tercih', $note->meta['kind']);

        $this->ask('Paket fiyatı ne?');
        Http::assertSent(fn (Request $request): bool => str_contains(json_encode($request->data()['system'] ?? [], JSON_UNESCAPED_UNICODE), 'Fiyatları hep +KDV yaz.'));
        Livewire::test(Moxi::class)->assertSee('Fiyatları hep +KDV yaz.')->call('forget', $note->id);
        $this->assertSame([], app(MoxiCharacter::class)->notebook($this->admin->id));
    }

    public function test_bubbles_tell_useful_things_or_a_joke_and_the_rank_grows_with_work(): void
    {
        $character = app(MoxiCharacter::class);
        $joke = $character->bubble($this->admin, '/satis', 1);
        $this->assertSame('joke', $joke['kind']);
        $this->assertNotSame('', $joke['text']);

        $lead = app(SalesLeads::class)->quickAdd(['company' => 'Dentafix'], $this->admin);
        $lead->forceFill(['next_step' => 'Ara', 'next_step_at' => now()->subDay()])->save();
        $tip = $character->bubble($this->admin, '/satis', 0);
        $this->assertSame('tip', $tip['kind']);
        $this->assertStringContainsString('gecikti', $tip['text']);
        $this->assertSame(route('operator.sales'), $tip['url']);

        $this->assertSame('Çırak', $character->rank($this->admin->id)['name']);
        foreach (range(1, 10) as $i) {
            MoxiMessage::query()->create(['user_id' => $this->admin->id, 'role' => MoxiMessage::CARD, 'status' => MoxiMessage::DONE, 'body' => 'Kart '.$i]);
        }
        $this->assertSame('Samuray', $character->rank($this->admin->id)['name']);
        Livewire::test(Moxi::class)->assertSee('data-rank="2"', false)->assertSee('data-cards="10"', false);
    }

    public function test_chat_text_is_escaped_and_only_safe_links_are_made(): void
    {
        $html = Moxi::html('<script>x</script> [Aç](javascript:alert(1)) [Satış](/satis) **önemli**');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('href="javascript', $html);
        $this->assertStringContainsString('<a href="/satis" class="moxi-link">Satış</a>', $html);
        $this->assertStringContainsString('<strong>önemli</strong>', $html);
        $this->assertSame('Satış hazır', Moxi::plain('[Satış](/satis) hazır'));
    }
}
