<?php

namespace App\Services\Ai;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The scheduled work that calls AI by itself (no operator click), with the next run — read from the real schedule
 * (routes/console.php), so the AI işlemleri page always shows what the system will start and when.
 */
final class AiSchedule
{
    /** Schedule name => [label, what it does]. Only AI work is listed. */
    public const array TASKS = [
        'queries-autopilot' => ['Sorgu otomatik pilotu', 'Yeni sorgulara hizmet / filtre (her sorgu bir kez); günde bir kez yeni sorguları kümeler.'],
        'brands-dossier' => ['Marka dosyası', 'Marka dosyası ve Eksikler (AI\'sız), ardından site akışı (Claude\'a devredilmişse kendiliğinden, değilse «Akışı ilerlet» ile).'],
        'site-weekly' => ['Site haftalık yenileme', 'Yeni sayfaların kategorisi, eşleşmeler ve kullanılan sayfaların özetleri.'],
        'analyst-weekly' => ['Kanal analistleri', 'Aktif markaların canlı kanalları (Google Ads, Meta, İşletme Profili) için haftalık inceleme.'],
        'brands-care' => ['Marka bakım ajanı', 'Marka dosyası değiştiyse inceler, işleri İş listesine yazar.'],
        'brands-chief' => ['Şef: denetim + haftalık plan', 'AI kararlarında hata arar (AI\'sız), sonra haftalık planı yazar.'],
    ];

    /** Schedule name => its gate (routes/console.php `when`): off (no next run) unless it may run by itself (AiBudget::automaticAllowed). */
    private const array GATES = ['analyst-weekly' => 'analyst.weekly', 'site-weekly' => 'site.weekly_refresh', 'brands-care' => 'brand.care', 'brands-chief' => 'brand.chief'];

    /**
     * @return list<array{name: string, label: string, what: string, next: ?CarbonImmutable}>
     */
    public function upcoming(): array
    {
        $expressions = Cache::remember('ai-schedule:expressions', now()->addMinutes(10), fn (): array => $this->expressions());
        $out = [];
        foreach (self::TASKS as $name => [$label, $what]) {
            $next = null;
            if (isset(self::GATES[$name]) && ! AiBudget::automaticAllowed(self::GATES[$name])) {
                $out[] = ['name' => $name, 'label' => $label, 'what' => 'Kapalı: yalnız tıklayınca çalışır (otomatik AI: Sorgular ve Claude\'a devredilen işler).', 'next' => null];

                continue;
            }
            if (isset($expressions[$name])) {
                try {
                    $next = CarbonImmutable::instance((new CronExpression($expressions[$name][0]))->getNextRunDate(now($expressions[$name][1])))->timezone('Europe/Istanbul');
                } catch (Throwable) {
                    $next = null;
                }
            }
            $out[] = ['name' => $name, 'label' => $label, 'what' => $what, 'next' => $next];
        }
        usort($out, fn (array $a, array $b): int => ($a['next']?->getTimestamp() ?? PHP_INT_MAX) <=> ($b['next']?->getTimestamp() ?? PHP_INT_MAX));

        return $out;
    }

    /** @return array<string, array{0: string, 1: string}> name => [cron, timezone] */
    private function expressions(): array
    {
        try {
            app(Kernel::class)->all(); // loads routes/console.php (the schedule) outside the console too
        } catch (Throwable) {
            // the schedule stays as registered
        }
        $out = [];
        foreach (app(Schedule::class)->events() as $event) {
            /** @var Event $event */
            if ($event->description !== null && isset(self::TASKS[$event->description])) {
                $timezone = $event->timezone instanceof \DateTimeZone ? $event->timezone->getName() : (string) ($event->timezone ?: config('app.timezone'));
                $out[$event->description] = [$event->expression, $timezone];
            }
        }

        return $out;
    }
}
