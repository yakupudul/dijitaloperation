<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Prompts\PromptRegistry;
use App\Support\Roles;
use Illuminate\Console\Command;

/**
 * moxdop:prompts:adopt-default — an operation whose prompt was published from the screen (or by moxdop:mcp:delegate)
 * no longer follows a changed code default by itself. This publishes the code default template as the next version
 * and keeps the model in use (Claude MCP stays Claude MCP), as the first active admin, so the history shows it.
 */
final class PromptsAdoptDefaultCommand extends Command
{
    protected $signature = 'moxdop:prompts:adopt-default {operations* : Operation keys, e.g. site.write_article}';

    protected $description = 'Publish the code default prompt of the given AI operations, keeping their current model.';

    public function handle(PromptRegistry $registry): int
    {
        $admin = User::query()->role(Roles::ADMIN)->where('is_active', true)->orderBy('id')->first();
        if ($admin === null) {
            $this->error('Aktif Admin kullanıcı yok.');

            return self::FAILURE;
        }
        foreach ((array) $this->argument('operations') as $operation) {
            $operation = (string) $operation;
            if (! $registry->has($operation)) {
                $this->warn($operation.': bilinmeyen işlem, atlandı.');

                continue;
            }
            $current = $registry->current($operation);
            $definition = $registry->definition($operation);
            if ($current->template === PromptRegistry::withGuard($definition['template'])) {
                $this->line($operation.': zaten kod varsayılanında (v'.$current->version.').');

                continue;
            }
            $version = $registry->publish($operation, ['template' => $definition['template'], 'model' => $current->model, 'purpose' => $current->purpose], $admin);
            $this->info($operation.': kod varsayılanı yayında (v'.$version->version.', model '.($version->model ?? 'rota modeli').').');
        }

        return self::SUCCESS;
    }
}
