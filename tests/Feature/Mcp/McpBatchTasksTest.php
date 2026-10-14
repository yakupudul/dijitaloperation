<?php

namespace Tests\Feature\Mcp;

use App\Jobs\Site\RunSiteOperationJob;
use App\Mcp\Servers\MoxdopServer;
use App\Mcp\Tools\GetTasks;
use App\Mcp\Tools\ListTasks;
use App\Mcp\Tools\SubmitResults;
use App\Models\AiTask;
use App\Services\Site\SiteOperations;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Site\SiteTestCase;

/**
 * Paket alma / teslim (yakup, 2026-10-14 "abonelik tasarrufu"): one operation's tasks come in one call with the
 * long instructions and schema sent once, and the answers go back in one call, each checked like submit-result.
 */
final class McpBatchTasksTest extends SiteTestCase
{
    private const array SCHEMA = ['type' => 'object', 'properties' => ['label' => ['type' => 'string']], 'required' => ['label'], 'additionalProperties' => false];

    protected function setUp(): void
    {
        parent::setUp();
        config(['moxdop-mcp.token' => 'test-mcp-token']);
        Queue::fake();
    }

    public function test_get_tasks_sends_shared_instructions_once_and_skips_known_hashes(): void
    {
        $tasks = collect(range(1, 3))->map(fn (int $i): AiTask => $this->task('run-a', $i, 'Uzun talimat'));
        $this->task('run-b', 1, 'Başka iş', 'site.cluster_match');

        $response = MoxdopServer::tool(GetTasks::class, ['operation' => 'queries.triage', 'limit' => 10]);
        $response->assertOk()->assertSee('"instructions_hash":"'.GetTasks::hash('Uzun talimat').'"')
            ->assertDontSee('Başka iş');
        $this->assertSame(1, substr_count(implode('', (fn (): array => $this->content())->call($response)), 'Uzun talimat'), 'the prompt text appears once for three tasks');
        $this->assertSame([AiTask::CLAIMED], $tasks->map(fn (AiTask $t): string => $t->fresh()->status)->unique()->values()->all());

        $again = MoxdopServer::tool(GetTasks::class, ['operation' => 'queries.triage', 'known' => [GetTasks::hash('Uzun talimat'), GetTasks::hash((string) json_encode(self::SCHEMA))]]);
        $again->assertOk()->assertDontSee('Uzun talimat')->assertSee('"instructions":{}');
    }

    public function test_submit_results_stores_valid_answers_and_returns_errors_for_the_rest(): void
    {
        [$good, $bad, $skip] = [$this->task('run-a', 1, 't'), $this->task('run-a', 2, 't'), $this->task('run-a', 3, 't')];

        MoxdopServer::tool(SubmitResults::class, ['results' => [
            ['id' => $good->id, 'output' => ['label' => 'implant']],
            ['id' => $bad->id, 'output' => ['wrong' => 1]],
            ['id' => $skip->id, 'fail_reason' => 'Girdi boş.'],
        ]])->assertOk()->assertSee('"stored":['.$good->id.']')->assertSee('"failed":['.$skip->id.']');

        $this->assertSame(AiTask::DONE, $good->fresh()->status);
        $this->assertSame(['label' => 'implant'], $good->fresh()->output);
        $this->assertTrue($bad->fresh()->isOpen(), 'a refused answer stays open to fix');
        $this->assertSame(AiTask::FAILED, $skip->fresh()->status);
        Queue::assertNotPushed(RunSiteOperationJob::class, 'the run still waits for the refused answer');

        MoxdopServer::tool(SubmitResults::class, ['results' => [['id' => $bad->id, 'output' => ['label' => 'diş']]]])->assertOk();
        Queue::assertPushed(RunSiteOperationJob::class);
    }

    public function test_list_tasks_counts_waiting_work_per_operation(): void
    {
        $this->task('run-a', 1, 't');
        $this->task('run-a', 2, 't');
        $this->task('run-b', 1, 't', 'site.cluster_match');

        MoxdopServer::tool(ListTasks::class, [])->assertOk()->assertSee('"by_operation":{"queries.triage":2,"site.cluster_match":1}');
    }

    private function task(string $run, int $sequence, string $instructions, string $operation = 'queries.triage'): AiTask
    {
        return AiTask::query()->create(['operation' => $operation, 'resume_key' => hash('sha256', $run), 'sequence' => $sequence, 'input_hash' => 'h'.$sequence,
            'status' => AiTask::PENDING, 'instructions' => $instructions, 'input' => 'DATA_JSON {"n":'.$sequence.'}', 'output_schema' => self::SCHEMA,
            'resume' => serialize(new RunSiteOperationJob($this->site->id, SiteOperations::CLUSTER_PAGES, []))]);
    }
}
