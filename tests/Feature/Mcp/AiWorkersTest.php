<?php

namespace Tests\Feature\Mcp;

use App\Jobs\Site\RunSiteOperationJob;
use App\Livewire\Operator\Settings\AiWorkersPage;
use App\Mcp\Servers\MoxdopServer;
use App\Mcp\Tools\GetTasks;
use App\Mcp\Tools\ListTasks;
use App\Mcp\Tools\SubmitResults;
use App\Models\AiTask;
use App\Models\McpToken;
use App\Services\Ai\AiCredits;
use App\Services\AiTasks\AiWorkers;
use App\Services\AiTasks\ApiQueueWorker;
use App\Services\AiTasks\McpCaller;
use App\Services\Mcp\McpKeys;
use App\Services\Site\SiteOperations;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Site\SiteTestCase;

/**
 * AI sağlayıcıları (yakup, 2026-10-27): Claude MCP, ChatGPT MCP, Claude API and ChatGPT API drain the queue in the
 * order of Ayarlar › AI. A backup gets "sıra sende değil" while the one before it comes; it takes over when that one has
 * not come for 90 minutes while work waits; a taken task stays its taker's for two hours; every answer says who gave it.
 */
final class AiWorkersTest extends SiteTestCase
{
    private const array SCHEMA = ['type' => 'object', 'properties' => ['label' => ['type' => 'string']], 'required' => ['label'], 'additionalProperties' => false];

    private const string CHATGPT_CALLBACK = 'https://chatgpt.com/connector/oauth/abc123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['moxdop-mcp.token' => 'test-mcp-token']);
        Queue::fake();
        McpKeys::issue('ChatGPT (bağlantı)', McpToken::WRITE, null, AiWorkers::CHATGPT_MCP);
    }

    public function test_a_backup_stands_down_while_claude_comes_to_its_rounds_and_takes_over_when_claude_misses_one(): void
    {
        $this->travelTo(now('Europe/Istanbul')->setTime(10, 40));
        $workers = app(AiWorkers::class);
        $workers->setRounds(AiWorkers::CLAUDE_MCP, ['10:00', '14:00']);
        $task = $this->task(1);
        $task->forceFill(['created_at' => now()->subHours(5)])->save();
        $this->travelTo(now('Europe/Istanbul')->setTime(10, 2));
        $workers->markSeen(AiWorkers::CLAUDE_MCP);
        $this->travelTo(now('Europe/Istanbul')->setTime(13, 30));
        $this->assertSame(AiWorkers::CLAUDE_MCP, $workers->active(), 'between rounds Claude waits for its next round, it is not down');

        $this->as(AiWorkers::CHATGPT_MCP);
        MoxdopServer::tool(ListTasks::class, [])->assertOk()->assertSee('"your_turn":false')->assertSee('Sıra sende değil');
        MoxdopServer::tool(GetTasks::class, ['operation' => 'queries.triage'])->assertOk()->assertSee('"tasks":[]');
        $this->assertSame(AiTask::PENDING, $task->fresh()->status);

        $this->travelTo(now('Europe/Istanbul')->setTime(14, 30));
        MoxdopServer::tool(GetTasks::class, ['operation' => 'queries.triage'])->assertOk()->assertSee('"your_turn":true')->assertSee('"id":'.$task->id);
        $this->assertSame(AiWorkers::CHATGPT_MCP, $workers->active(), 'Claude missed its 14:00 round');
        $this->assertStringContainsString('14:00 turuna gelmedi', (string) $workers->downReason(AiWorkers::CLAUDE_MCP));
        $this->assertSame(AiWorkers::CHATGPT_MCP, $task->fresh()->claimed_by);

        MoxdopServer::tool(SubmitResults::class, ['results' => [['id' => $task->id, 'output' => ['label' => 'implant']]]])->assertOk();
        $this->assertSame(AiWorkers::CHATGPT_MCP, $task->fresh()->done_by);
        $this->assertSame('ChatGPT MCP', $task->fresh()->doneByLabel());

        $this->as(AiWorkers::CLAUDE_MCP);
        MoxdopServer::tool(ListTasks::class, [])->assertOk()->assertSee('"your_turn":true');
        $this->as(AiWorkers::CHATGPT_MCP);
        MoxdopServer::tool(ListTasks::class, [])->assertOk()->assertSee('"your_turn":false', false);
    }

    public function test_a_task_claude_took_stays_claude_s_for_two_hours_and_new_work_goes_first(): void
    {
        $taken = $this->task(1);
        $taken->forceFill(['status' => AiTask::CLAIMED, 'claimed_by' => AiWorkers::CLAUDE_MCP, 'claimed_at' => now()->subMinutes(30)])->save();
        $fresh = $this->task(2);
        app(AiWorkers::class)->setOrder([AiWorkers::CHATGPT_MCP, AiWorkers::CLAUDE_MCP, AiWorkers::CLAUDE_API, AiWorkers::OPENAI_API]);

        $this->as(AiWorkers::CHATGPT_MCP);
        MoxdopServer::tool(GetTasks::class, ['operation' => 'queries.triage'])->assertOk()->assertSee('"id":'.$fresh->id)->assertDontSee('"id":'.$taken->id.',');

        $this->travel(2)->hours();
        $ids = collect(json_decode((string) json_encode(AiWorkers::servable(AiTask::query(), AiWorkers::CHATGPT_MCP)->orderBy('id')->pluck('id')), true))->all();
        $this->assertSame([$fresh->id, $taken->id], $ids, 'after two hours the half-done task can be taken, after own/new work');
    }

    public function test_chatgpt_connector_registers_and_its_key_is_chatgpt_mcp(): void
    {
        $clientId = $this->postJson('/mcp/oauth/register', ['client_name' => 'ChatGPT', 'redirect_uris' => [self::CHATGPT_CALLBACK]])->assertCreated()->json('client_id');
        $verifier = str_repeat('v', 64);
        $query = ['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => self::CHATGPT_CALLBACK, 'state' => 's',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256'];
        $location = $this->post('/mcp/oauth/authorize', $query + ['decision' => 'approve'])->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $back);
        $this->post('/mcp/oauth/token', ['grant_type' => 'authorization_code', 'code' => $back['code'], 'redirect_uri' => self::CHATGPT_CALLBACK,
            'client_id' => $clientId, 'code_verifier' => $verifier])->assertOk();

        $this->assertSame(AiWorkers::CHATGPT_MCP, McpToken::query()->latest('id')->first()->provider);
    }

    public function test_claude_api_answers_the_queue_when_it_is_first_alive_and_records_model_and_cost(): void
    {
        config(['moxdop-mcp.token' => '', 'moxdop.anthropic.api_key' => 'sk-test', 'services.anthropic.api_key' => 'sk-test']);
        McpToken::query()->update(['revoked_at' => now()]);
        $workers = app(AiWorkers::class);
        $workers->saveApi(AiWorkers::CLAUDE_API, true, 'claude-haiku-5-5', 0, 0);
        if (! $workers->connected(AiWorkers::CLAUDE_API)) {
            $this->markTestSkipped('No Claude API key can be set in this environment.');
        }
        $task = $this->task(1);
        Http::fake(['*/messages' => Http::response(['content' => [['type' => 'tool_use', 'name' => 'cevap', 'input' => ['label' => 'implant']]],
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 100]])]);

        $this->assertSame(AiWorkers::CLAUDE_API, $workers->active());
        $this->assertSame(1, app(ApiQueueWorker::class)->round());

        $task->refresh();
        $this->assertSame(AiTask::DONE, $task->status);
        $this->assertSame('claude_api:claude-haiku-5-5', $task->done_by);
        $this->assertSame('Claude API · claude-haiku-5-5', $task->doneByLabel());
        $this->assertGreaterThan(0, $task->cost_usd);
        Http::assertSent(fn ($request): bool => $request['tool_choice']['name'] === 'cevap' && $request['tools'][0]['input_schema'] === self::SCHEMA);
    }

    public function test_settings_page_orders_workers_and_removes_a_connection_only_after_two_confirmations(): void
    {
        $token = McpToken::query()->where('provider', AiWorkers::CHATGPT_MCP)->sole();

        Livewire::test(AiWorkersPage::class)->assertSee('Claude MCP')->assertSee('ChatGPT MCP')->assertSee('Claude API')->assertSee('ChatGPT API')
            ->assertSee('Rutin yönergeleri')->assertSee('your_turn false')
            ->call('moveBy', AiWorkers::CHATGPT_MCP, -1)
            ->call('startRemove', $token->id)->call('confirmRemove')->assertSee('Son onay')
            ->set('removeName', 'yanlış')->call('confirmRemove')->assertHasErrors('removeName');
        $this->assertNull($token->fresh()->revoked_at);
        $this->assertSame(AiWorkers::CHATGPT_MCP, app(AiWorkers::class)->order()[0]);

        Livewire::test(AiWorkersPage::class)->call('startRemove', $token->id)->call('confirmRemove')->set('removeName', $token->name)->call('confirmRemove');
        $this->assertNotNull($token->fresh()->revoked_at);
    }

    public function test_api_row_adds_the_provider_credit_to_the_same_counter_as_ai_operations(): void
    {
        app(AiCredits::class)->topUp('anthropic', 100);

        Livewire::test(AiWorkersPage::class)->assertSee('Kalan kredi $100,00')->assertSee('Kredi girilmedi')
            ->set('credit.'.AiWorkers::CLAUDE_API, '200')->call('addCredit', AiWorkers::CLAUDE_API)->assertHasNoErrors()->assertSee('Kalan kredi $300,00');
        $this->assertSame(300.0, app(AiCredits::class)->status('anthropic')['loaded']);

        Livewire::test(AiWorkersPage::class)->set('credit.'.AiWorkers::CLAUDE_API, '0')->call('addCredit', AiWorkers::CLAUDE_API)->assertHasErrors('credit.'.AiWorkers::CLAUDE_API);
    }

    private function as(string $worker): void
    {
        app()->instance(McpCaller::class, new McpCaller($worker, null, McpToken::WRITE));
    }

    private function task(int $sequence): AiTask
    {
        return AiTask::query()->create(['operation' => 'queries.triage', 'resume_key' => hash('sha256', 'run'), 'sequence' => $sequence, 'input_hash' => 'h'.$sequence,
            'status' => AiTask::PENDING, 'instructions' => 'Talimat', 'input' => 'DATA_JSON {"n":'.$sequence.'}', 'output_schema' => self::SCHEMA,
            'resume' => serialize(new RunSiteOperationJob($this->site->id, SiteOperations::CLUSTER_PAGES, []))]);
    }
}
