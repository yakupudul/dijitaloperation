<?php

namespace App\Console\Commands;

use App\Mail\AdvisorWeeklyDigestMail;
use App\Models\User;
use App\Services\Advisor\AdvisorWorkQueue;
use App\Services\Operator\OperatorMailConfigService;
use App\Services\ReportDelivery\ReportMailConfigGuard;
use App\Support\Roles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * moxdop:advisor:digest — weekly internal email with the top jobs. Off unless ADVISOR_DIGEST_ENABLED=true
 * (or --force) and operator mail is configured. Recipients: active admins.
 */
final class AdvisorDigestCommand extends Command
{
    protected $signature = 'moxdop:advisor:digest {--force : Send even when the weekly digest is disabled}';

    protected $description = 'Email active admins the week\'s top jobs across SEO Görevleri and the advisor channels.';

    public function handle(AdvisorWorkQueue $queue, OperatorMailConfigService $mailConfig, ReportMailConfigGuard $guard): int
    {
        if (! $this->option('force') && ! (bool) config('moxdop-advisor.digest.enabled', false)) {
            $this->line('Haftalık özet kapalı (ADVISOR_DIGEST_ENABLED=false).');

            return self::SUCCESS;
        }
        $mailConfig->reloadForQueuedSend();
        if (! $guard->isConfigured()) {
            $this->warn('E-posta ayarı yapılmamış (Ayarlar → E-posta); özet gönderilmedi.');

            return self::SUCCESS;
        }
        $items = $queue->top((int) config('moxdop-advisor.digest.items', 5));
        if ($items === []) {
            $this->line('Açık iş yok; özet gönderilmedi.');

            return self::SUCCESS;
        }
        $sent = 0;
        foreach (User::query()->where('is_active', true)->role(Roles::ADMIN)->get() as $user) {
            if (filled($user->email)) {
                Mail::to((string) $user->email)->send(new AdvisorWeeklyDigestMail($items));
                $sent++;
            }
        }
        $this->info(sprintf('Özet %d kişiye gönderildi.', $sent));

        return self::SUCCESS;
    }
}
