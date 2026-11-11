<?php

namespace App\Mcp\Tools;

use App\Models\AiTask;
use App\Models\MoxiMessage;
use App\Services\AiTasks\AiTaskPriority;
use App\Services\AiTasks\AiWorkers;
use App\Services\AiTasks\McpCaller;
use App\Services\Brand\Findings;
use App\Services\Brand\MonthlySession;
use App\Support\Ai\AiOperationLabels;
use App\Support\SchemaState;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lists AI tasks waiting for Claude (most urgent first: writing and repair checks, then WhatsApp / review replies, then bulk work; oldest first inside): id, operation, brand, subject, status, plus `by_operation` (waiting count per operation in the order to work them, for get-tasks) and `oldest_waiting_hours`. Optional filters: operation key, brand_id. Lists only tasks you may take (another worker\'s taken task stays its own for two hours). When `your_turn` is false another AI worker (Ayarlar › AI order) has the queue now: stop the round without doing anything.')]
#[IsReadOnly]
class ListTasks extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'operation' => ['nullable', 'string', 'max:80'],
            'brand_id' => ['nullable', 'integer'],
        ]);
        $caller = McpCaller::current();
        $workers = app(AiWorkers::class);
        if ($caller->writes()) {
            $workers->markSeen($caller->worker);
            if (! $workers->mayWork($caller->worker)) {
                $moxi = SchemaState::hasTable('moxi_messages') ? MoxiMessage::query()->where('role', MoxiMessage::REQUEST)->where('status', MoxiMessage::PENDING)->count() : 0;

                return Response::json(['your_turn' => false, 'moxi_requests' => $moxi,
                    'message' => $workers->standDownMessage($caller->worker).($moxi > 0 ? ' Yalnız önce '.$moxi.' Moxi işini yap (moxi-requests); kuyruğa dokunma.' : ''),
                    'waiting' => AiTask::query()->whereIn('status', [AiTask::PENDING, AiTask::CLAIMED])->count(), 'by_operation' => (object) [], 'tasks' => []]);
            }
        }
        $open = fn () => AiWorkers::servable(AiTask::query(), $caller->worker)->reorder();
        $tasks = AiWorkers::servable(AiTask::query()->with('brand:id,name'), $caller->worker)->reorder()
            ->when($validated['operation'] ?? null, fn ($q, string $operation) => $q->where('operation', $operation))
            ->when($validated['brand_id'] ?? null, fn ($q, int $brandId) => $q->where('brand_id', $brandId))
            ->orderByRaw(AiTaskPriority::orderSql())->orderBy('id')->limit((int) config('moxdop-mcp.list_limit', 20))->get();
        $oldest = AiTask::query()->where('status', AiTask::PENDING)->min('created_at');

        return Response::json([
            'your_turn' => true,
            // Moxi işleri: the operator's own jobs handed over by the panel assistant; done before the queue.
            'moxi_requests' => SchemaState::hasTable('moxi_messages') ? MoxiMessage::query()->where('role', MoxiMessage::REQUEST)->where('status', MoxiMessage::PENDING)->count() : 0,
            // Marka sayfası › "Çöz": findings the operator handed over (brand-findings only_requested=true); also before the queue.
            'findings_requested' => SchemaState::hasTable('brand_findings') ? Findings::requested() : 0,
            // Aylık oturum (Marka Düzeni 2.6): brands whose month has not been read yet (brand-findings action=session).
            'sessions_due' => MonthlySession::due(),
            'waiting' => $open()->count(),
            'oldest_waiting_hours' => $oldest !== null ? (int) floor(Carbon::parse((string) $oldest)->diffInMinutes(now()) / 60) : null,
            // Work order: writing / repair checks first, then people waiting (WhatsApp, reviews), then bulk; oldest first inside.
            'by_operation' => $open()
                ->selectRaw('operation, count(*) as waiting, min(created_at) as oldest')->groupBy('operation')->get()
                ->sortBy(fn ($row): array => [AiTaskPriority::tier((string) $row->operation), (string) $row->oldest, (string) $row->operation])
                ->mapWithKeys(fn ($row): array => [(string) $row->operation => (int) $row->waiting])->all(),
            'tasks' => $tasks->map(fn (AiTask $task): array => [
                'id' => $task->id,
                'operation' => $task->operation,
                'label' => AiOperationLabels::for($task->operation),
                'brand' => $task->brand?->name,
                'subject' => $task->subject,
                'status' => $task->status,
                'attempts' => $task->attempts,
                'created_at' => $task->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->description('Only this operation key, e.g. site.write_article.'),
            'brand_id' => $schema->integer()->description('Only this brand.'),
        ];
    }
}
