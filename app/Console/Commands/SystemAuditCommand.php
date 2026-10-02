<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Operations\PageSmokeAudit;
use App\Services\Operations\SystemAudit;
use App\Support\Roles;
use Illuminate\Console\Command;

/**
 * moxdop:audit — one server-side report to paste back: system checks and every operator page opened with
 * real data. Read-only: jobs, mail and notifications are faked, outside HTTP calls are blocked and every
 * page request is rolled back. The report is also written to storage/logs/audit-*.txt.
 */
final class SystemAuditCommand extends Command
{
    protected $signature = 'moxdop:audit
        {--user= : E-posta; boşsa ilk aktif yönetici}
        {--per-type=3 : Her varlık türünden kaç varlık açılsın}
        {--only= : Yalnız adresinde / rota adında bu metin geçen sayfalar}
        {--skip-pages : Sadece sistem kontrolleri}
        {--allow-http : Sayfaların dış API çağrılarına izin ver (varsayılan: engelli)}
        {--no-transaction : Sayfa isteklerini geri alınan işlem içinde çalıştırma}';

    protected $description = 'Sistem ve tüm operatör sayfalarını sunucuda denetler; yapıştırılacak tek rapor üretir.';

    /** @var list<string> */
    private array $report = [];

    public function handle(SystemAudit $system, PageSmokeAudit $pages): int
    {
        $icons = ['ok' => '✓', 'info' => '·', 'warn' => '!', 'fail' => '✗'];
        $this->line2('== MoxDOP denetim raporu '.now()->toDateTimeString().' ==');
        $this->line2('');
        $this->line2('-- Sistem --');
        $systemFails = 0;
        foreach ($system->run() as [$level, $title, $detail]) {
            $systemFails += $level === 'fail' ? 1 : 0;
            $this->line2(sprintf('%s %s: %s', $icons[$level] ?? '?', $title, $detail));
        }

        $pageFails = 0;
        if (! $this->option('skip-pages')) {
            $user = $this->resolveUser();
            if ($user === null) {
                $this->line2('✗ Sayfa denetimi: kullanıcı bulunamadı (--user=e-posta verin).');

                return $this->finish(1);
            }
            $this->line2('');
            $this->line2('-- Sayfalar ('.$user->email.' olarak, her türden '.(int) $this->option('per-type').' varlık) --');
            $done = 0;
            $results = $pages->run($user, max(1, (int) $this->option('per-type')), (bool) $this->option('allow-http'), ! $this->option('no-transaction'),
                $this->option('only') ?: null, function () use (&$done): void {
                    if (++$done % 25 === 0) {
                        $this->output->write('.');
                    }
                });
            $this->newLine();
            $byLevel = collect($results)->groupBy('level');
            $pageFails = $byLevel->get('error', collect())->count();
            $this->line2(sprintf('%d sayfa açıldı · %d hata · %d dış API çağrısı · %d yavaş (>5 sn) · %d yetkisiz · %d bulunamadı',
                count($results), $pageFails, $byLevel->get('http', collect())->count(), $byLevel->get('slow', collect())->count(),
                $byLevel->get('forbidden', collect())->count(), $byLevel->get('missing', collect())->count()));

            // Same error on many pages: print once with the pages it hit.
            foreach (collect($results)->whereIn('level', ['error', 'http'])->groupBy(fn (array $r): string => $r['error'].' @ '.$r['where']) as $key => $group) {
                $this->line2('');
                $this->line2(sprintf('✗ [%s] %s', $group->first()['level'] === 'http' ? 'DIŞ ÇAĞRI' : 'HATA '.$group->first()['status'], $group->first()['error']));
                $this->line2('   yer: '.($group->first()['where'] ?? '?'));
                foreach ($group->take(6) as $r) {
                    $this->line2('   '.$r['url']);
                }
                if ($group->count() > 6) {
                    $this->line2('   … +'.($group->count() - 6).' sayfa');
                }
            }
            foreach (collect($results)->where('level', 'slow')->sortByDesc('ms')->take(15) as $r) {
                $this->line2(sprintf('! yavaş %.1f sn  %s', $r['ms'] / 1000, $r['url']));
            }
            foreach (collect($results)->where('level', 'forbidden')->take(10) as $r) {
                $this->line2('! 403  '.$r['url']);
            }
            foreach (collect($results)->where('level', 'missing')->take(10) as $r) {
                $this->line2('· 404  '.$r['url']);
            }
            $writers = collect($results)->filter(fn (array $r): bool => $r['writes'] !== [])->groupBy('route');
            foreach ($writers->take(15) as $route => $group) {
                $this->line2(sprintf('· GET sırasında yazma (geri alındı) %s: %s', $route, implode(', ', array_unique(array_merge(...$group->pluck('writes')->all())))));
            }
        }

        return $this->finish($systemFails + $pageFails > 0 ? 1 : 0);
    }

    private function resolveUser(): ?User
    {
        if (filled($this->option('user'))) {
            return User::query()->where('email', (string) $this->option('user'))->first();
        }

        return User::query()->where('is_active', true)->whereHas('roles', fn ($q) => $q->where('name', Roles::ADMIN))->orderBy('id')->first();
    }

    private function line2(string $text): void
    {
        $this->report[] = $text;
        $this->line($text);
    }

    private function finish(int $code): int
    {
        $path = storage_path('logs/audit-'.now()->format('Ymd-His').'.txt');
        @file_put_contents($path, implode("\n", $this->report)."\n");
        $this->newLine();
        $this->info('Rapor kaydedildi: '.$path);

        return $code;
    }
}
