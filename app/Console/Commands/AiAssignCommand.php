<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Ai\AiAssignments;
use App\Support\Roles;
use Illuminate\Console\Command;

/**
 * moxdop:ai:assign — gives every AI operation to GPT, the Claude API or the Claude subscription in one go, as the
 * "Toplu dağılım" buttons on Ayarlar › AI işlemleri do (published as the first active Admin, so the version history
 * shows the switch; "Bu sürüme dön" undoes it per operation).
 */
final class AiAssignCommand extends Command
{
    protected $signature = 'moxdop:ai:assign {plan : onerilen | claude_api | abonelik | rota}';

    protected $description = 'Give every AI operation to GPT, the Claude API or the Claude subscription (Ayarlar › AI işlemleri, Toplu dağılım).';

    public function handle(AiAssignments $assignments): int
    {
        $plan = (string) $this->argument('plan');
        if (! array_key_exists($plan, AiAssignments::PLANS)) {
            $this->error('Dağılım şunlardan biri olmalı: '.implode(', ', array_keys(AiAssignments::PLANS)));

            return self::FAILURE;
        }
        $admin = User::query()->role(Roles::ADMIN)->where('is_active', true)->orderBy('id')->first();
        if ($admin === null) {
            $this->error('Aktif Admin kullanıcı yok.');

            return self::FAILURE;
        }
        $counts = $assignments->apply($plan, $admin);
        $this->info(sprintf('%s: %d işlem değişti, %d zaten öyleydi, %d dokunulmadı.', AiAssignments::PLANS[$plan], $counts['changed'], $counts['unchanged'], $counts['kept']));

        return self::SUCCESS;
    }
}
