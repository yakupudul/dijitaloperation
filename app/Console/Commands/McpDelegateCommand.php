<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Prompts\PromptRegistry;
use App\Support\Roles;
use Illuminate\Console\Command;

/**
 * moxdop:mcp:delegate — sets operations to "Claude (MCP, abonelik)" from the deploy shell instead of Ayarlar › AI
 * işlemleri: a new prompt version per operation with the same template, published as the first active admin (so the
 * version history shows who and when). --api turns them back to the route model. Already set operations are skipped.
 */
final class McpDelegateCommand extends Command
{
    protected $signature = 'moxdop:mcp:delegate {operations?* : Operation keys (default: every supported one)} {--api : Back to the route model}';

    protected $description = 'Hand AI operations to Claude (MCP) or back to the API, as the AI işlemleri screen does.';

    public function handle(PromptRegistry $registry, AiTaskQueue $tasks): int
    {
        if (! AiTaskQueue::enabled()) {
            $this->error('MOXDOP_MCP_TOKEN boş: önce .env dosyasına ekleyip php artisan config:cache çalıştırın.');

            return self::FAILURE;
        }
        $admin = User::query()->role(Roles::ADMIN)->where('is_active', true)->orderBy('id')->first();
        if ($admin === null) {
            $this->error('Aktif Admin kullanıcı yok.');

            return self::FAILURE;
        }
        $operations = (array) $this->argument('operations') ?: AiTaskQueue::SUPPORTED;
        $model = $this->option('api') ? null : AiTaskQueue::MODEL;
        foreach ($operations as $operation) {
            if (! $tasks->supports((string) $operation)) {
                $this->warn($operation.': Claude (MCP) ile çalışamaz, atlandı.');

                continue;
            }
            $current = $registry->current((string) $operation);
            if ($current->model === $model) {
                $this->line($operation.': zaten öyle.');

                continue;
            }
            $registry->publish((string) $operation, ['template' => (string) $current->template, 'model' => $model], $admin);
            $this->info($operation.': '.($model === null ? 'API (rota modeli)' : 'Claude (MCP, abonelik)').'.');
        }

        return self::SUCCESS;
    }
}
