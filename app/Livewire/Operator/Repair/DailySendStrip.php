<?php

namespace App\Livewire\Operator\Repair;

use App\Services\Work\DailySend;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * Günlük gönderim (yakup, 2026-10-14, "Günde 1 tık"): the top strip of the Onarım masası. Shows what the night prepared
 * and AI checked (articles, Business Profile posts, desk fixes) and what the check held back with its reason; one Admin
 * button sends everything that passed (SendDailyBatchJob, off the web request). While it runs the strip polls and shows
 * how far it got ("X / Y gönderildi", DailySend::currentProgress).
 */
final class DailySendStrip extends Component
{
    public string $message = '';

    public bool $showHeld = false;

    public function send(DailySend $daily): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        if (Cache::has(DailySend::RUNNING_KEY)) {
            $this->message = 'Gönderim zaten sürüyor.';

            return;
        }
        $summary = $daily->summary();
        $daily->queue(auth()->user(), ['content' => $summary['content'], 'posts' => $summary['posts'], 'repair' => $summary['repair']]);
        $this->message = 'Gönderim başladı; birkaç dakika içinde biter. Bu haftanın yazıları gününe zamanlanmış olarak, geriye dönük yazılar tarihli taslak olarak, gönderiler gününde, onarımlar "Yapılanlar"a gider.';
    }

    public function placeholder(): string
    {
        return '<div class="rounded-[20px] bg-hero p-6 text-sm text-white/60">Günlük gönderim hazırlanıyor…</div>';
    }

    public function render(DailySend $daily): View
    {
        $running = Cache::has(DailySend::RUNNING_KEY);

        return view('livewire.operator.repair.daily-send-strip', [
            'summary' => $daily->summary(),
            'running' => $running,
            'progress' => $running ? DailySend::currentProgress() : null,
            'last' => Cache::get(DailySend::LAST_KEY),
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }
}
