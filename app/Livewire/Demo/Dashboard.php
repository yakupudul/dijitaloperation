<?php

namespace App\Livewire\Demo;

use App\Jobs\Brand\RunBrandChiefJob;
use App\Models\User;
use App\Services\Brand\BrandChief;
use App\Services\Observability\ErrorTriage;
use App\Services\Operator\TodayCockpitReader;
use App\Services\Outcomes\OutcomeTracker;
use App\Services\Work\DailySend;
use App\Support\Demo\DemoState;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Bugün answers one question, "what is waiting on me today?" (yakup approved the concept 2026-10-20): first the few
 * things that wait on the operator (ready work to send, accounts losing money, fixes to look at, Hata merkezi), then
 * this week's four numbers, one line per brand (8 weeks of organic clicks, Ads conversions, fixes waiting, worst
 * alarm), the alarm list, last night's work, Şef's weekly plan and "Sonuçlar" (applied suggestions measured later).
 */
#[Layout('operator.layouts.app')]
#[Title('Bugün')]
class Dashboard extends Component
{
    /** Şef's plan again, now (queued; the plan replaces this week's). */
    public function refreshPlan(): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);
        RunBrandChiefJob::dispatch(false);
        $this->dispatch('ai-live-refresh');
        $this->dispatch('operator-notice', message: 'Şef planı hazırlıyor; birkaç dakika içinde burada.', tone: 'success');
    }

    public function render(): View
    {
        $cockpit = app(TodayCockpitReader::class);

        return view('livewire.demo.dashboard', [
            'approvals' => $cockpit->approvals(),
            'week' => $cockpit->week(),
            'alarms' => $cockpit->alarms(),
            'overnight' => $cockpit->overnight(),
            'brands' => $cockpit->brands(),
            'daily' => $this->daily(),
            'systemAlerts' => $this->systemAlerts(),
            'results' => $this->results(),
            'chiefPlan' => BrandChief::current(),
            'flash' => DemoState::pullFlash(),
        ]);
    }

    /**
     * The daily send (Onarım masası top card): what passed Claude's check and when the last send ran.
     *
     * @return array{ready: int, content: int, posts: int, repair: int, last_at: ?CarbonImmutable, failed?: bool}
     */
    private function daily(): array
    {
        try {
            $summary = app(DailySend::class)->summary();
            $last = Cache::get(DailySend::LAST_KEY);
            $lastAt = is_array($last) && isset($last['at']) ? CarbonImmutable::parse($last['at'])->timezone('Europe/Istanbul') : null;

            return ['ready' => $summary['content'] + $summary['posts'] + $summary['repair'], 'content' => $summary['content'],
                'posts' => $summary['posts'], 'repair' => $summary['repair'], 'last_at' => $lastAt];
        } catch (Throwable $error) {
            report($error);

            return ['ready' => 0, 'content' => 0, 'posts' => 0, 'repair' => 0, 'last_at' => null, 'failed' => true];
        }
    }

    /**
     * Sonuçlar: the last measured suggestions and the verdict counts of the last 90 days.
     *
     * @return array{counts: array<string, int>, items: list<array<string, string>>}
     */
    private function results(): array
    {
        try {
            return app(OutcomeTracker::class)->summary();
        } catch (Throwable $error) {
            report($error);

            return ['counts' => [], 'items' => []];
        }
    }

    /**
     * Hata merkezi on Bugün: only what needs the operator (ErrorTriage `you` + `code`); self-healing problems stay off.
     *
     * @return array{critical: int, warning: int, top: ?string, failed?: bool}
     */
    private function systemAlerts(): array
    {
        try {
            $groups = app(ErrorTriage::class)->groups();
            $you = collect($groups[ErrorTriage::YOU] ?? []);
            $code = collect($groups[ErrorTriage::CODE] ?? []);

            return [
                'critical' => (int) $you->sum('count'),
                'warning' => (int) $code->sum('count'),
                'top' => $you->first()['title'] ?? $code->first()['title'] ?? null,
            ];
        } catch (Throwable $error) {
            // Never "0 sorun" when the error center itself is broken (panel audit N5): Bugün says it could not be read.
            report($error);

            return ['critical' => 0, 'warning' => 0, 'top' => null, 'failed' => true];
        }
    }
}
